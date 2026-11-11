<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\AccountingDocumentLine;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Treasury\Models\CashAccount;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Accounting documents / vouchers (Prompt 22).
 *
 * Traceable source documents (receipt, payment, transfer, adjustment, refund
 * vouchers) with a Draft -> Submitted -> Approved -> Posted lifecycle. Only
 * drafts are editable; posted documents are immutable and corrections are
 * made via a linked reversal document that itself goes through approval.
 * Every GL effect goes through the existing PostingService, and document
 * lines must balance before anything can be submitted.
 *
 * Tax handling: each line records its tax breakdown and tax account as
 * document metadata. Vouchers do NOT auto-post tax lines; that treatment is
 * an accountant decision (DEC-036) and the balanced debit/credit lines are
 * the sole source of posting amounts.
 */
class AccountingDocumentService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
        private readonly FxService $fx,
    ) {}

    /** @return array<int, string> */
    public static function types(): array
    {
        return AccountingDocument::$types;
    }

    /** @return array<int, string> */
    public static function statuses(): array
    {
        return AccountingDocument::$statuses;
    }

    /**
     * Create a draft document. Idempotent via `idempotency_key`. Accepts an
     * optional `submit` flag to move straight to the submitted state.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Branch $branch, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $partyType = $data['party_type'] ?? null;
            $partyId = $this->resolvePartyId($partyType, $data['party_id'] ?? null);
            $bookingId = $this->resolveBookingId($data['booking_id'] ?? null);

            $currency = strtoupper((string) ($data['currency'] ?? $this->settings->baseCurrency()));
            $date = (string) ($data['document_date'] ?? now()->toDateString());
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : (string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date));

            $rawLines = ($data['simple_mode'] ?? false) === true
                ? $this->simpleSettlementLines($data, $branch, $currency, $partyType, $partyId)
                : ($data['lines'] ?? []);
            $lines = $this->prepareLines($rawLines, $currency);
            $this->assertBalanced($lines);

            $subtotal = (int) array_sum(array_column($lines, 'debit_minor'));
            $tax = (int) array_sum(array_column($lines, 'tax_minor'));
            // In simplified settlement mode the document amount is the single
            // cash movement; summing debit + credit would double-count it.
            $total = ($data['simple_mode'] ?? false) === true ? (int) $data['amount_minor'] : $subtotal + $tax;
            if (($data['simple_mode'] ?? false) === true) {
                $subtotal = $total;
            }

            $series = (string) ($data['series'] ?? $this->documentSeries($data['type']));
            $number = $this->sequences->next($series, $branch);

            $document = AccountingDocument::query()->create([
                'type' => $data['type'],
                'series' => $series,
                'number' => $number,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'branch_id' => $branch->id,
                'booking_id' => $bookingId,
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'currency_code' => $currency,
                'fx_rate' => $fxRate,
                'fx_rate_date' => $date,
                'fx_rate_source' => 'manual',
                'status' => AccountingDocument::STATUS_DRAFT,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
                'reversal_reason' => $data['reversal_reason'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'memo' => $data['memo'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($lines as $line) {
                AccountingDocumentLine::query()->create([
                    'accounting_document_id' => $document->id,
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

            $this->audit->log('accounting_document.created', $document, null, [
                'type' => $document->type,
                'number' => $document->number,
                'total_minor' => $total,
            ], $actorId);

            if (! empty($data['submit'])) {
                $this->submit($document, $actorId);
            }

            return $document->refresh()->load('lines.account', 'branch');
        });
    }

    /**
     * Edit a draft document: replace lines (re-validated and re-balanced) or
     * metadata. Totals are always recomputed from the stored lines.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(AccountingDocument $document, array $data, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $data, $actorId) {
            $document = AccountingDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->status !== AccountingDocument::STATUS_DRAFT) {
                throw new DomainException('Only draft documents can be edited.');
            }

            $attributes = [];

            if (array_key_exists('memo', $data)) {
                $attributes['memo'] = $data['memo'];
            }

            $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : $document->currency_code;
            $date = (string) ($data['document_date'] ?? $document->fx_rate_date?->toDateString() ?? now()->toDateString());

            if (isset($data['lines'])) {
                $lines = $this->prepareLines($data['lines'], $currency);
                $this->assertBalanced($lines);

                $document->lines()->delete();
                foreach ($lines as $line) {
                    AccountingDocumentLine::query()->create([
                        'accounting_document_id' => $document->id,
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

                $attributes['subtotal_minor'] = (int) array_sum(array_column($lines, 'debit_minor'));
                $attributes['tax_minor'] = (int) array_sum(array_column($lines, 'tax_minor'));
                $attributes['total_minor'] = $attributes['subtotal_minor'] + $attributes['tax_minor'];
            }

            if ($currency !== $document->currency_code || isset($data['fx_rate']) || isset($data['document_date'])) {
                $attributes['currency_code'] = $currency;
                $attributes['fx_rate'] = $currency === $this->settings->baseCurrency()
                    ? '1'
                    : (string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date));
                $attributes['fx_rate_date'] = $date;
            }

            if ($attributes !== []) {
                $document->update($attributes);
                $this->audit->log('accounting_document.updated', $document, null, [
                    'fields' => array_keys($attributes),
                ], $actorId);
            }

            return $document->refresh()->load('lines.account');
        });
    }

    public function submit(AccountingDocument $document, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $actorId) {
            $document = AccountingDocument::query()->lockForUpdate()->findOrFail($document->id);

            // Idempotent: replaying a submit on an already submitted document
            // is a no-op so retries and queue replays cannot corrupt state.
            if ($document->status === AccountingDocument::STATUS_SUBMITTED) {
                return $document->load('lines.account');
            }

            if ($document->status !== AccountingDocument::STATUS_DRAFT) {
                throw new DomainException('Only draft documents can be submitted.');
            }

            $this->assertOpenPeriod($document->fx_rate_date->toDateString(), $document->branch_id, $actorId);

            $document->update([
                'status' => AccountingDocument::STATUS_SUBMITTED,
                'submitted_by' => $actorId,
                'submitted_at' => now(),
            ]);
            $this->audit->log('accounting_document.submitted', $document, ['status' => AccountingDocument::STATUS_DRAFT], [
                'status' => AccountingDocument::STATUS_SUBMITTED,
            ], $actorId);

            return $document->refresh()->load('lines.account');
        });
    }

    public function approve(AccountingDocument $document, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $actorId) {
            $document = AccountingDocument::query()->lockForUpdate()->findOrFail($document->id);

            // Idempotent: an already approved document stays approved.
            if ($document->status === AccountingDocument::STATUS_APPROVED) {
                return $document->load('lines.account');
            }

            if ($document->status !== AccountingDocument::STATUS_SUBMITTED) {
                throw new DomainException('Only submitted documents can be approved.');
            }

            if ($this->settings->bool('documents', 'segregation', true)
                && $actorId !== null
                && $actorId === $document->submitted_by) {
                throw new DomainException('The person who submitted a document cannot approve it.');
            }

            $this->assertOpenPeriod($document->fx_rate_date->toDateString(), $document->branch_id, $actorId);

            $document->update([
                'status' => AccountingDocument::STATUS_APPROVED,
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);
            $this->audit->log('accounting_document.approved', $document, ['status' => AccountingDocument::STATUS_SUBMITTED], [
                'status' => AccountingDocument::STATUS_APPROVED,
            ], $actorId);

            return $document->refresh()->load('lines.account');
        });
    }

    /**
     * Post the approved document to the ledger. Idempotent: posting an
     * already posted document returns it with its journal entry, so retries,
     * queue replays and concurrent approvals create exactly one journal.
     */
    public function post(AccountingDocument $document, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $actorId) {
            $document = AccountingDocument::query()->with('lines.account')->lockForUpdate()->findOrFail($document->id);

            if ($document->status === AccountingDocument::STATUS_POSTED && $document->journal_entry_id !== null) {
                return $document->load('lines.account', 'journalEntry');
            }

            if ($document->status !== AccountingDocument::STATUS_APPROVED) {
                throw new DomainException('Only approved documents can be posted.');
            }

            $this->assertOpenPeriod($document->fx_rate_date->toDateString(), $document->branch_id, $actorId);

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $document->fx_rate_date->toDateString(),
                branch: $document->branch,
                currency: $document->currency_code,
                lines: $this->postingLines($document),
                memo: $this->documentMemo($document),
                sourceType: 'accounting_document',
                sourceId: $document->id,
                event: 'posted',
                fxRate: (string) $document->fx_rate,
                fxRateDate: $document->fx_rate_date->toDateString(),
                fxRateSource: $document->fx_rate_source,
                actorId: $actorId,
            ));

            $document->update([
                'status' => AccountingDocument::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by' => $actorId,
            ]);

            $this->audit->log('accounting_document.posted', $document, ['status' => AccountingDocument::STATUS_APPROVED], [
                'status' => AccountingDocument::STATUS_POSTED,
                'journal' => $entry->number,
            ], $actorId);

            return $document->refresh()->load('lines.account', 'journalEntry');
        });
    }

    public function cancel(AccountingDocument $document, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $actorId) {
            $document = AccountingDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! in_array($document->status, [AccountingDocument::STATUS_DRAFT, AccountingDocument::STATUS_SUBMITTED], true)) {
                throw new DomainException('Only draft or submitted documents can be cancelled.');
            }

            $previousStatus = $document->status;
            $document->update(['status' => AccountingDocument::STATUS_CANCELLED]);
            $this->audit->log('accounting_document.cancelled', $document, ['status' => $previousStatus], [
                'status' => AccountingDocument::STATUS_CANCELLED,
            ], $actorId);

            return $document->refresh()->load('lines.account');
        });
    }

    public function reject(AccountingDocument $document, string $reason, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $reason, $actorId) {
            $document = AccountingDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->status !== AccountingDocument::STATUS_SUBMITTED) {
                throw new DomainException('Only submitted documents can be rejected.');
            }

            $document->update(['status' => AccountingDocument::STATUS_REJECTED]);
            $this->audit->log('accounting_document.rejected', $document, ['status' => AccountingDocument::STATUS_SUBMITTED], [
                'status' => AccountingDocument::STATUS_REJECTED,
                'reason' => $reason,
            ], $actorId);

            return $document->refresh()->load('lines.account');
        });
    }

    /** Move a rejected document back to draft so it can be edited and resubmitted. */
    public function reopen(AccountingDocument $document, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $actorId) {
            $document = AccountingDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->status !== AccountingDocument::STATUS_REJECTED) {
                throw new DomainException('Only rejected documents can be reopened.');
            }

            $document->update(['status' => AccountingDocument::STATUS_DRAFT]);
            $this->audit->log('accounting_document.reopened', $document, ['status' => AccountingDocument::STATUS_REJECTED], [
                'status' => AccountingDocument::STATUS_DRAFT,
            ], $actorId);

            return $document->refresh()->load('lines.account');
        });
    }

    /**
     * Create the linked reversal document for a posted document. The reversal
     * swaps every line's debit/credit, keeps the original trail via
     * reversal_of_id, and is left in the submitted state so it goes through
     * the normal approval workflow (maker-checker applies to it too).
     *
     * @param  array<string, mixed>  $data
     */
    public function correct(AccountingDocument $document, array $data, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($document, $data, $actorId) {
            $document = AccountingDocument::query()->with('lines.account', 'lines.taxAccount')->lockForUpdate()->findOrFail($document->id);

            if ($document->status !== AccountingDocument::STATUS_POSTED) {
                throw new DomainException('Only posted documents can be corrected.');
            }

            $reason = (string) ($data['reason'] ?? '');

            $correction = $this->create([
                'type' => $document->type,
                'series' => $data['series'] ?? null,
                'party_type' => $document->party_type,
                'party_id' => $document->party_id,
                'booking_id' => $document->booking_id,
                'cash_account_id' => $document->cash_account_id,
                'currency' => $document->currency_code,
                'fx_rate' => (string) $document->fx_rate,
                'document_date' => $document->fx_rate_date->toDateString(),
                'lines' => $document->lines->map(fn (AccountingDocumentLine $line) => [
                    'account_id' => $line->account_id,
                    'description' => 'Correction of '.$document->number.': '.($line->description ?? ''),
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'tax_minor' => $line->tax_minor,
                    'tax_account_id' => $line->tax_account_id,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                ])->values()->all(),
                'memo' => trim('Correction of '.$document->number.($reason !== '' ? ' — '.$reason : '')),
                'reversal_of_id' => $document->id,
                'reversal_reason' => $reason,
                'submit' => true,
            ], $document->branch, $actorId);

            $this->audit->log('accounting_document.correction_created', $document, null, [
                'correction_id' => $correction->id,
                'correction_number' => $correction->number,
                'reason' => $reason,
            ], $actorId);

            return $correction->load('lines.account', 'reversalOf');
        });
    }

    /**
     * Map the balanced document lines to posting lines. Party references on
     * lines are stored as integer ids and flow to the journal lines as
     * analytical dimensions.
     *
     * @return array<int, PostingLine>
     */
    private function postingLines(AccountingDocument $document): array
    {
        $lines = [];
        $description = 'Document '.$document->number;

        foreach ($document->lines as $line) {
            $lineDescription = $line->description ?? $description;
            $lines[] = $line->debit_minor > 0
                ? PostingLine::debit($line->account, $line->debit_minor, $line->party_type, $line->party_id, $lineDescription)
                : PostingLine::credit($line->account, $line->credit_minor, $line->party_type, $line->party_id, $lineDescription);
        }

        return $lines;
    }

    /**
     * Simplified vouchers only settle an EXISTING customer receivable or
     * supplier payable. Advances, refunds, expense payouts and transfers
     * have distinct accounting semantics and must use their dedicated flows.
     * GL lines are derived server-side and remain subject to normal approval.
     */
    private function simpleSettlementLines(array $data, Branch $branch, string $currency, ?string $partyType, ?int $partyId): array
    {
        // Without an issued invoice we cannot determine the carrying value
        // of foreign AR/AP. Never silently settle it at today's FX rate.
        if (strtoupper($currency) !== $this->settings->baseCurrency()) {
            throw new DomainException('Foreign-currency settlements must use the issued-invoice payment workflow to calculate realized FX gains and losses.');
        }
        $type = $data['type'] ?? null;
        $expectedParty = match ($type) {
            AccountingDocument::TYPE_RECEIPT_VOUCHER => 'customer',
            AccountingDocument::TYPE_PAYMENT_VOUCHER => 'supplier',
            default => throw new DomainException('Simplified entry is supported only for customer receipt and supplier payment vouchers.'),
        };

        if ($partyType !== $expectedParty || $partyId === null) {
            throw new DomainException('Select the required customer or supplier for this voucher.');
        }
        if (empty($data['booking_id']) === false || empty($data['lines']) === false || empty($data['tax_minor']) === false) {
            throw new DomainException('Simplified vouchers cannot carry bookings, manual posting lines or tax overrides.');
        }
        $amount = $data['amount_minor'] ?? null;
        if (is_int($amount) === false || $amount <= 0) {
            throw new DomainException('Voucher amount must be a positive integer in minor currency units.');
        }
        if (empty($data['cash_account_id'])) {
            throw new DomainException('Select a cash or bank account.');
        }
        $cash = CashAccount::query()->with('account')
            ->where('public_id', $data['cash_account_id'])
            ->where(function ($query) use ($branch) {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->where('is_active', true)->first();
        if ($cash === null || $cash->account === null || $cash->account->is_active === false || $cash->account->is_postable === false
            || strtoupper($cash->currency_code) !== $currency) {
            throw new DomainException('Cash/bank account must be active, postable, in this branch and use the voucher currency.');
        }
        $party = $expectedParty === 'customer'
            ? Customer::query()->find($partyId)
            : Supplier::query()->find($partyId);
        if ($party === null || $party->branch_id !== $branch->id) {
            throw new DomainException('The selected party does not belong to this branch.');
        }
        $role = $expectedParty === 'customer' ? PartyAccount::ROLE_RECEIVABLE : PartyAccount::ROLE_PAYABLE;
        $partyAccount = PartyAccount::query()->with('account')
            ->where('party_type', $partyType)->where('party_id', $partyId)
            ->where('role', $role)->first();
        if ($partyAccount === null || $partyAccount->account === null || $partyAccount->account->is_active === false
            || $partyAccount->account->is_postable === false
            || ($partyAccount->account->currency_code !== null && strtoupper($partyAccount->account->currency_code) !== $currency)) {
            throw new DomainException('No matching active party ledger account exists. Use the accounting workflow to configure it.');
        }
        if ($partyAccount->account_id === $cash->account_id) {
            throw new DomainException('Cash and settlement accounts must be different.');
        }
        $description = $data['memo'] ?? null;
        $cashLine = ['account_id' => $cash->account_id, 'description' => $description,
            'debit_minor' => $type === AccountingDocument::TYPE_RECEIPT_VOUCHER ? $amount : 0,
            'credit_minor' => $type === AccountingDocument::TYPE_PAYMENT_VOUCHER ? $amount : 0];
        $partyLine = ['account_id' => $partyAccount->account_id, 'description' => $description,
            'debit_minor' => $type === AccountingDocument::TYPE_PAYMENT_VOUCHER ? $amount : 0,
            'credit_minor' => $type === AccountingDocument::TYPE_RECEIPT_VOUCHER ? $amount : 0,
            'party_type' => $partyType, 'party_id' => $partyId];

        return [$cashLine, $partyLine];
    }

    /**
     * Validate and normalise raw document lines. Accounts must be active and
     * postable, each line carries exactly one of debit/credit, tax breakdown
     * cannot be negative, and party references are resolved to integer ids.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array{account_id: int, description: ?string, debit_minor: int, credit_minor: int, tax_minor: int, tax_account_id: ?int, party_type: ?string, party_id: ?int, subtotal_minor: int}>
     */
    private function prepareLines(array $rawLines, string $currency): array
    {
        if (count($rawLines) < 1) {
            throw new DomainException('A document needs at least one line.');
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
                $taxAccountId = $this->resolveAccountId($raw['tax_account_id']);
            }

            $lines[] = [
                'account_id' => $account->id,
                'description' => $raw['description'] ?? null,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'tax_minor' => $tax,
                'tax_account_id' => $taxAccountId,
                'party_type' => $raw['party_type'] ?? null,
                'party_id' => $this->resolvePartyId($raw['party_type'] ?? null, $raw['party_id'] ?? null),
                'subtotal_minor' => $debit + $credit,
            ];
        }

        return $lines;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function assertBalanced(array $lines): void
    {
        $net = 0;
        foreach ($lines as $line) {
            $net += ($line['debit_minor'] ?? 0) - ($line['credit_minor'] ?? 0);
        }

        if ($net !== 0) {
            throw new DomainException('Document lines must balance (total debit must equal total credit).');
        }
    }

    private function documentSeries(string $type): string
    {
        return match ($type) {
            AccountingDocument::TYPE_RECEIPT_VOUCHER => (string) $this->settings->get('documents', 'receipt_voucher_prefix', 'RCV'),
            AccountingDocument::TYPE_PAYMENT_VOUCHER => (string) $this->settings->get('documents', 'payment_voucher_prefix', 'PAY'),
            AccountingDocument::TYPE_TRANSFER_VOUCHER => (string) $this->settings->get('documents', 'transfer_voucher_prefix', 'TV'),
            AccountingDocument::TYPE_ADJUSTMENT_VOUCHER => (string) $this->settings->get('documents', 'adjustment_voucher_prefix', 'ADJ'),
            AccountingDocument::TYPE_REFUND_VOUCHER => (string) $this->settings->get('documents', 'refund_voucher_prefix', 'RF'),
            default => 'DOC',
        };
    }

    private function documentMemo(AccountingDocument $document): string
    {
        return ucfirst(str_replace('_', ' ', $document->type)).' '.$document->number.($document->memo ? ' - '.$document->memo : '');
    }

    /**
     * Resolve a party reference (public id from the API or internal integer
     * id from persisted lines) to the party's integer database id.
     */
    private function resolvePartyId(?string $partyType, string|int|null $reference): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $query = match ($partyType) {
            'customer' => Customer::query(),
            'supplier' => Supplier::query(),
            'employee' => Employee::query(),
            default => null,
        };

        if ($query === null) {
            throw new DomainException('A party reference needs a valid party type (customer, supplier or employee).');
        }

        $party = is_int($reference)
            ? $query->find($reference)
            : $query->where('public_id', $reference)->first();

        if (! $party) {
            throw new DomainException("The selected {$partyType} does not exist.");
        }

        return $party->id;
    }

    /**
     * Resolve an account reference (public id, account code, or internal
     * integer id) to the account's integer database id.
     */
    private function resolveAccountId(string|int|null $reference): int
    {
        if ($reference === null || $reference === '') {
            throw new DomainException('An account is required on each document line.');
        }

        $account = is_int($reference)
            ? Account::query()->find($reference)
            : (Account::query()->where('public_id', $reference)->first()
                ?? Account::query()->where('code', $reference)->first());

        if (! $account) {
            throw new DomainException("Account {$reference} not found.");
        }

        return $account->id;
    }

    private function resolveBookingId(string|int|null $reference): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $booking = is_int($reference)
            ? Booking::query()->find($reference)
            : Booking::query()->where('public_id', $reference)->first();

        if (! $booking) {
            throw new DomainException('The selected booking does not exist.');
        }

        return $booking->id;
    }

    private function assertOpenPeriod(string $date, int $branchId, ?int $actorId): void
    {
        $period = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if (! $period) {
            throw new DomainException("No fiscal period covers {$date}. Create and open the period first.");
        }

        if ($period->status !== 'open') {
            throw new DomainException("Fiscal period {$period->name} is {$period->status}; posting is not allowed.");
        }

        $user = $actorId !== null ? User::query()->find($actorId) : null;
        if ($user) {
            $this->branchScope->assertBranch($user, Branch::query()->find($branchId));
        }
    }
}
