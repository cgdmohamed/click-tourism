<?php

namespace App\Modules\Invoices\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceLine;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Models\Supplier;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer/supplier invoice lifecycle (Prompt 26) with party subaccount
 * provisioning (Prompt 27).
 *
 * All GL effects go through PostingService. Invoices post to genuine
 * posting-capable child accounts provisioned per party; no invoice-local
 * balance truth is kept beyond the outstanding mirror of the ledger.
 */
class InvoiceService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
        private readonly FxService $fx,
    ) {}

    /**
     * Provision (or return) the posting-capable subaccount for a party/role.
     *
     * @param  'customer'|'supplier'|'employee'  $partyType
     */
    public function provisionSubaccount(string $partyType, int $partyId, string $role): Account
    {
        return DB::transaction(function () use ($partyType, $partyId, $role) {
            $existing = PartyAccount::query()->where('party_type', $partyType)
                ->where('party_id', $partyId)
                ->where('role', $role)
                ->with('account')
                ->first();

            if ($existing) {
                return $existing->account;
            }

            $party = $this->resolveParty($partyType, $partyId);
            $branch = $party->branch;

            $controlCode = match ($role) {
                PartyAccount::ROLE_RECEIVABLE => (string) $this->settings->get('accounting', 'ar_account_code', '1100'),
                PartyAccount::ROLE_PAYABLE, PartyAccount::ROLE_PAYABLE_BALANCE => (string) $this->settings->get('accounting', 'ap_account_code', '2100'),
                PartyAccount::ROLE_ADVANCE => (string) $this->settings->get('accounting', 'advance_account_code', '2300'),
                default => throw new DomainException("Unsupported party account role: {$role}"),
            };

            $control = Account::query()->where('code', $controlCode)->first();
            if (! $control || ! $control->is_active || ! $control->is_postable) {
                throw new DomainException("Control account {$controlCode} is missing or not postable.");
            }

            $code = $this->generateChildCode($control->id, $control->code);
            $type = $role === PartyAccount::ROLE_RECEIVABLE ? 'asset' : 'liability';

            $account = Account::query()->create([
                'code' => $code,
                'name_ar' => $party->name_ar.' ('.$role.')',
                'name_en' => $party->name_en.' ('.$role.')',
                'type' => $type,
                'parent_id' => $control->id,
                'is_postable' => true,
                'is_active' => true,
                'currency_code' => $party->currency_code ?? $this->settings->baseCurrency(),
            ]);

            $partyAccount = PartyAccount::query()->create([
                'party_type' => $partyType,
                'party_id' => $partyId,
                'role' => $role,
                'account_id' => $account->id,
            ]);

            $this->audit->log('party_account.provisioned', $partyAccount, null, [
                'party_type' => $partyType,
                'party_id' => $partyId,
                'role' => $role,
                'account_code' => $code,
            ], null);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Branch $branch, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $partyType = $data['party_type'] ?? 'customer';
            $partyId = $this->resolvePartyId($partyType, $data['party_id'] ?? null);

            // Provision the genuine posting-capable subaccount for this party/role (Prompt 27).
            $this->provisionSubaccount($partyType, $partyId, $partyType === 'customer'
                ? PartyAccount::ROLE_RECEIVABLE
                : PartyAccount::ROLE_PAYABLE);

            $currency = strtoupper((string) ($data['currency'] ?? $this->settings->baseCurrency()));
            $issueDate = (string) $data['issue_date'];
            $dueDate = $data['due_date'] ?? null;
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $issueDate)));

            $lines = $this->prepareLines($data, $currency, $branch);

            $subtotal = (int) array_sum(array_column($lines, 'subtotal_minor'));
            $tax = (int) array_sum(array_column($lines, 'tax_minor'));
            $total = $subtotal + $tax;

            $series = $data['series'] ?? $this->invoiceSeries($data['type']);
            $number = $this->sequences->next('invoice.'.$series, $branch, Carbon::parse($issueDate));

            $invoice = Invoice::query()->create([
                'type' => $data['type'],
                'series' => $series,
                'number' => $number,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'branch_id' => $branch->id,
                'booking_id' => $data['booking_id'] ?? null,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency_code' => $currency,
                'fx_rate' => $fxRate,
                'fx_rate_date' => $issueDate,
                'fx_rate_source' => 'manual',
                'status' => 'draft',
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'outstanding_minor' => $total,
                'memo' => $data['memo'] ?? null,
                'credit_note_of_id' => $data['credit_note_of_id'] ?? null,
                'created_by' => $actorId,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            foreach ($lines as $line) {
                InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'],
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'tax_minor' => $line['tax_minor'],
                    'tax_account_id' => $line['tax_account_id'] ?? null,
                    'party_type' => $line['party_type'] ?? null,
                    'party_id' => $line['party_id'] ?? null,
                ]);
            }

            $this->audit->log('invoice.created', $invoice, null, [
                'type' => $invoice->type,
                'number' => $invoice->number,
                'total_minor' => $total,
            ], $actorId);

            if (! empty($data['submit'])) {
                $this->submit($invoice, $actorId);
            }

            return $invoice->refresh()->load('lines.account', 'branch');
        });
    }

    public function submit(Invoice $invoice, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== 'draft') {
                throw new DomainException('Only draft invoices can be submitted.');
            }

            $this->assertOpenPeriod($invoice->issue_date, $invoice->branch_id, $actorId);

            $invoice->update(['status' => 'submitted', 'approved_by' => null]);
            $this->audit->log('invoice.submitted', $invoice, ['status' => 'draft'], ['status' => 'submitted'], $actorId);

            return $invoice->refresh()->load('lines.account');
        });
    }

    public function approve(Invoice $invoice, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== 'submitted') {
                throw new DomainException('Only submitted invoices can be approved.');
            }

            $this->assertOpenPeriod($invoice->issue_date, $invoice->branch_id, $actorId);

            $invoice->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->audit->log('invoice.approved', $invoice, ['status' => 'submitted'], ['status' => 'approved'], $actorId);

            return $invoice->refresh()->load('lines.account');
        });
    }

    /**
     * Post the approved invoice to the ledger. Idempotent: a posted journal
     * linked to this invoice means it has already been issued.
     */
    public function issue(Invoice $invoice, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId) {
            $invoice = Invoice::query()->with(['lines.account', 'creditNoteOf'])->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== 'approved') {
                throw new DomainException('Only approved invoices can be issued.');
            }

            if ($invoice->journal_entry_id !== null) {
                throw new DomainException("Invoice {$invoice->number} has already been issued.");
            }

            // Prevent double revenue recognition: if a linked booking already
            // recognized revenue (booking-sourced entry), do not post revenue
            // again through this invoice. The invoice should only record the
            // balance-sheet movement (AR/AP), not the P&L impact.
            if ($invoice->booking_id !== null) {
                $bookingRevenue = JournalEntry::query()
                    ->where('source_type', 'booking')
                    ->where('source_id', $invoice->booking_id)
                    ->where('event', 'revenue')
                    ->where('reversal_of_id', null)
                    ->exists();

                if ($bookingRevenue) {
                    throw new DomainException(
                        "Revenue was already recognized for booking {$invoice->booking_id}. "
                        .'Issue the invoice without revenue posting or reverse the booking revenue first.'
                    );
                }
            }

            $this->assertOpenPeriod($invoice->issue_date, $invoice->branch_id, $actorId);

            $partyAccount = $this->partySubaccount($invoice);
            $lines = $this->postingLines($invoice, $partyAccount);

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $invoice->issue_date,
                branch: $invoice->branch,
                currency: $invoice->currency_code,
                lines: $lines,
                memo: $this->invoiceMemo($invoice),
                sourceType: 'invoice',
                sourceId: $invoice->id,
                event: 'issued',
                fxRate: (string) $invoice->fx_rate,
                fxRateDate: $invoice->issue_date,
                fxRateSource: $invoice->fx_rate_source,
                actorId: $actorId,
            ));

            $invoice->update([
                'status' => 'issued',
                'journal_entry_id' => $entry->id,
                'issued_at' => now(),
            ]);

            $this->audit->log('invoice.issued', $invoice, ['status' => 'approved'], [
                'status' => 'issued',
                'journal' => $entry->number,
            ], $actorId);

            return $invoice->refresh()->load('lines.account', 'journalEntry');
        });
    }

    public function void(Invoice $invoice, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, ['draft', 'submitted'], true)) {
                throw new DomainException('Only draft or submitted invoices can be voided.');
            }

            $invoice->update(['status' => 'voided']);
            $this->audit->log('invoice.voided', $invoice, ['status' => $invoice->status], ['status' => 'voided'], $actorId);

            return $invoice->refresh()->load('lines.account');
        });
    }

    /**
     * Create a credit note for an issued invoice. Posts a reversing entry that
     * keeps the original trail (credit_note_of_id + reversal_of_id on the journal).
     */
    public function creditNote(Invoice $invoice, array $data, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $actorId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== 'issued') {
                throw new DomainException('Only issued invoices can be credited.');
            }

            $creditNoteLines = $invoice->lines->map(fn (InvoiceLine $line) => [
                'account_id' => $line->account->public_id,
                'description' => 'Credit note reversal: '.($line->description ?? ''),
                'debit_minor' => $line->debit_minor,
                'credit_minor' => $line->credit_minor,
                'tax_minor' => $line->tax_minor,
                'tax_account_id' => $line->taxAccount?->public_id,
                'party_type' => $line->party_type,
                'party_id' => $line->party_id,
            ])->values()->all();

            $party = match ($invoice->party_type) {
                'customer' => Customer::query()->find($invoice->party_id),
                'supplier' => Supplier::query()->find($invoice->party_id),
                default => null,
            };

            $creditNoteData = [
                'type' => Invoice::TYPE_CREDIT_NOTE,
                'series' => $data['series'] ?? $this->invoiceSeries(Invoice::TYPE_CREDIT_NOTE),
                'party_type' => $invoice->party_type,
                'party_id' => $party?->public_id ?? $invoice->party_id,
                'branch_id' => $invoice->branch_id,
                'booking_id' => $invoice->booking_id,
                'issue_date' => $data['issue_date'] ?? $invoice->issue_date,
                'due_date' => null,
                'currency' => $invoice->currency_code,
                'fx_rate' => (string) $invoice->fx_rate,
                'lines' => $creditNoteLines,
                'memo' => 'Credit note for '.$invoice->number.(($data['memo'] ?? null) ? ' - '.$data['memo'] : ''),
                'subtotal_minor' => $invoice->subtotal_minor,
                'tax_minor' => $invoice->tax_minor,
                'total_minor' => $invoice->total_minor,
                'outstanding_minor' => $invoice->total_minor,
                'credit_note_of_id' => $invoice->id,
            ];

            // Override amounts when partial credit is requested.
            if (isset($data['total_minor']) && (int) $data['total_minor'] !== 0) {
                $ratio = $invoice->total_minor !== 0
                    ? (int) $data['total_minor'] / $invoice->total_minor
                    : 1;
                $creditNoteData['subtotal_minor'] = (int) round($invoice->subtotal_minor * $ratio);
                $creditNoteData['tax_minor'] = (int) round($invoice->tax_minor * $ratio);
                $creditNoteData['total_minor'] = (int) $data['total_minor'];
                $creditNoteData['outstanding_minor'] = (int) $data['total_minor'];
            }

            $creditNote = $this->create($creditNoteData, $invoice->branch, $actorId);
            $this->submit($creditNote, $actorId);
            $this->approve($creditNote, $actorId);

            return $creditNote->refresh()->load('lines.account', 'creditNoteOf');
        });
    }

    /** @return array<int, array{account_id: int, description: string, debit_minor: int, credit_minor: int, tax_minor: int, tax_account_id: ?int, party_type: ?string, party_id: ?int, subtotal_minor: int}> */
    private function prepareLines(array $data, string $currency, Branch $branch): array
    {
        $rawLines = $data['lines'] ?? [];
        if (count($rawLines) < 1) {
            throw new DomainException('An invoice needs at least one line.');
        }

        $lines = [];
        foreach ($rawLines as $index => $raw) {
            $position = $index + 1;
            $accountId = $this->resolveAccountId($raw['account_id'] ?? null);
            $account = Account::query()->findOrFail($accountId);

            if (! $account->is_active || ! $account->is_postable) {
                throw new DomainException("Line {$position}: account {$account->code} is not active and postable.");
            }

            if ($account->currency_code !== null && strtoupper($account->currency_code) !== $currency) {
                throw new DomainException("Line {$position}: account {$account->code} only accepts {$account->currency_code}.");
            }

            $debit = (int) ($raw['debit_minor'] ?? 0);
            $credit = (int) ($raw['credit_minor'] ?? 0);
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                throw new DomainException("Line {$position}: enter exactly one of debit or credit, greater than zero.");
            }

            $tax = (int) ($raw['tax_minor'] ?? 0);
            if ($tax < 0) {
                throw new DomainException("Line {$position}: tax cannot be negative.");
            }

            $taxAccountId = null;
            if (! empty($raw['tax_account_id'])) {
                $taxAccount = Account::query()->where('public_id', $raw['tax_account_id'])->first()
                    ?? Account::query()->where('code', $raw['tax_account_id'])->first();
                if (! $taxAccount) {
                    throw new DomainException("Line {$position}: tax account {$raw['tax_account_id']} not found.");
                }
                $taxAccountId = $taxAccount->id;
            }

            $lines[] = [
                'account_id' => $account->id,
                'description' => $raw['description'] ?? null,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'tax_minor' => $tax,
                'tax_account_id' => $taxAccountId,
                'party_type' => $raw['party_type'] ?? null,
                'party_id' => $raw['party_id'] ?? null,
                'subtotal_minor' => $debit + $credit,
            ];
        }

        return $lines;
    }

    /**
     * Build the balanced posting lines for an issued invoice or credit note.
     *
     * Customer invoice: Dr party subaccount (total), Cr revenue lines (subtotal), Cr tax payable (tax).
     * Supplier invoice: Cr party subaccount (total), Dr expense lines (subtotal), Dr input tax (tax).
     * Credit notes reverse every direction of the original invoice.
     */
    private function postingLines(Invoice $invoice, Account $partyAccount): array
    {
        $isCreditNote = $invoice->type === Invoice::TYPE_CREDIT_NOTE;
        $isCustomer = $invoice->party_type === 'customer';
        // For a credit note, every direction of the original invoice is flipped.
        $partyDebit = $isCustomer ? ! $isCreditNote : $isCreditNote;
        $lineDebit = ! $partyDebit;
        $taxDebit = $isCustomer ? $isCreditNote : ! $isCreditNote;

        $lines = [];
        $description = 'Invoice '.$invoice->number;

        foreach ($invoice->lines as $line) {
            $lineDescription = $line->description ?? $description;
            // For credit notes the amount comes from the opposite column so the
            // direction flips (a credited revenue line becomes a debited one).
            $amount = $isCreditNote
                ? ($lineDebit ? $line->credit_minor : $line->debit_minor)
                : ($lineDebit ? $line->debit_minor : $line->credit_minor);
            if ($lineDebit) {
                $lines[] = PostingLine::debit($line->account, $amount, $line->party_type, $line->party_id, $lineDescription);
            } else {
                $lines[] = PostingLine::credit($line->account, $amount, $line->party_type, $line->party_id, $lineDescription);
            }

            if ($line->tax_minor > 0) {
                $taxAccount = $line->taxAccount ?? $this->taxAccount($isCustomer);
                $lines[] = $taxDebit
                    ? PostingLine::debit($taxAccount, $line->tax_minor, null, null, 'Input tax '.$invoice->number)
                    : PostingLine::credit($taxAccount, $line->tax_minor, null, null, 'Output tax '.$invoice->number);
            }
        }

        $partyType = $invoice->party_type;
        $partyDescription = $description.($isCreditNote ? ' (credit note)' : '');
        if ($partyDebit) {
            $lines[] = PostingLine::debit($partyAccount, (int) $invoice->total_minor, $partyType, $invoice->party_id, $partyDescription);
        } else {
            $lines[] = PostingLine::credit($partyAccount, (int) $invoice->total_minor, $partyType, $invoice->party_id, $partyDescription);
        }

        return $lines;
    }

    private function partySubaccount(Invoice $invoice): Account
    {
        $role = $invoice->party_type === 'customer' ? PartyAccount::ROLE_RECEIVABLE : PartyAccount::ROLE_PAYABLE;

        $mapping = PartyAccount::query()->where('party_type', $invoice->party_type)
            ->where('party_id', $invoice->party_id)
            ->where('role', $role)
            ->with('account')
            ->first();

        if (! $mapping) {
            throw new DomainException("No {$role} subaccount provisioned for party {$invoice->party_type}:{$invoice->party_id}.");
        }

        return $mapping->account;
    }

    private function taxAccount(bool $outputTax): Account
    {
        $code = $outputTax
            ? (string) $this->settings->get('accounting', 'output_tax_account_code', '2200')
            : (string) $this->settings->get('accounting', 'input_tax_account_code', '1160');

        return $this->posting->account($code);
    }

    private function generateChildCode(int $parentId, string $parentCode): string
    {
        $max = Account::query()->where('parent_id', $parentId)
            ->where('code', 'like', $parentCode.'%')
            ->max('code');

        $suffix = $max !== null ? (int) substr($max, strlen($parentCode)) : 0;
        $next = $suffix + 1;

        return $parentCode.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function resolveParty(string $partyType, int $partyId): Branch|Customer|Supplier
    {
        $party = match ($partyType) {
            'customer' => Customer::query()->find($partyId),
            'supplier' => Supplier::query()->find($partyId),
            default => throw new DomainException("Unsupported party type: {$partyType}"),
        };

        if (! $party) {
            throw new DomainException("The selected {$partyType} does not exist.");
        }

        return $party;
    }

    private function resolvePartyId(string $partyType, ?string $publicId): int
    {
        $party = match ($partyType) {
            'customer' => Customer::query()->where('public_id', $publicId ?? '')->first(),
            'supplier' => Supplier::query()->where('public_id', $publicId ?? '')->first(),
            default => throw new DomainException("Unsupported party type: {$partyType}"),
        };

        if (! $party) {
            throw new DomainException("The selected {$partyType} does not exist.");
        }

        return $party->id;
    }

    private function resolveAccountId(?string $reference): int
    {
        if ($reference === null || $reference === '') {
            throw new DomainException('An account is required on each invoice line.');
        }

        $account = Account::query()->where('public_id', $reference)->first()
            ?? Account::query()->where('code', $reference)->first();

        if (! $account) {
            throw new DomainException("Account {$reference} not found.");
        }

        return $account->id;
    }

    private function invoiceSeries(string $type): string
    {
        return match ($type) {
            Invoice::TYPE_CUSTOMER_INVOICE => (string) $this->settings->get('documents', 'customer_invoice_prefix', 'INV'),
            Invoice::TYPE_SUPPLIER_INVOICE => (string) $this->settings->get('documents', 'supplier_invoice_prefix', 'BIL'),
            Invoice::TYPE_CREDIT_NOTE => (string) $this->settings->get('documents', 'credit_note_prefix', 'CN'),
        };
    }

    private function invoiceMemo(Invoice $invoice): string
    {
        return ($invoice->type === 'customer_invoice' ? 'Customer invoice' : 'Supplier invoice')
            .' '.$invoice->number.($invoice->memo ? ' - '.$invoice->memo : '');
    }

    private function assertOpenPeriod(string $date, int $branchId, ?int $actorId): void
    {
        // Branch scope is enforced by the caller; here we only require an open period.
        $period = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if (! $period) {
            throw new DomainException("No fiscal period covers {$date}. Create and open the period first.");
        }

        if ($period->status !== 'open') {
            throw new DomainException("Fiscal period {$period->name} is {$period->status}; invoicing is not allowed.");
        }
    }
}
