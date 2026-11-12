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
use App\Modules\Parties\Services\CreditLimitService;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Tax\Services\TaxPolicy;
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
        private readonly PartyLedgerService $ledger,
        private readonly CreditLimitService $creditLimits,
        private readonly TaxPolicy $taxPolicy,
    ) {}

    /**
     * Provision (or return) the posting-capable subaccount for a party/role.
     *
     * @param  'customer'|'supplier'|'employee'  $partyType
     */
    public function provisionSubaccount(string $partyType, int $partyId, string $role): Account
    {
        return $this->ledger->account($partyType, $partyId, $role);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * Create exactly one draft sales invoice from a booking snapshot.
     * No journal is posted here; existing booking revenue is reused only on
     * issuance, after explicit approval and a full monetary match.
     */
    public function createFromBooking(Booking $booking, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($booking, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if (in_array($booking->status, ['cancelled', 'no_show'], true)) {
                throw new DomainException('Cancelled bookings cannot be invoiced.');
            }
            if (Invoice::query()->where('booking_id', $booking->id)
                ->where('type', Invoice::TYPE_CUSTOMER_INVOICE)
                ->where('status', '!=', Invoice::STATUS_VOIDED)->exists()) {
                throw new DomainException('This booking already has a customer invoice. Open the existing invoice.');
            }

            $booking->load('items', 'customer', 'branch');
            if ($booking->items->isEmpty() || (int) $booking->total_sell_minor <= 0) {
                throw new DomainException('A booking needs billable services before invoicing.');
            }
            $revenue = $this->posting->account((string) $this->settings->get('accounting', 'revenue_account_code', '4100'));
            $lines = $booking->items->map(function ($item) use ($revenue) {
                $net = (int) $item->total_sell_minor - (int) $item->tax_minor;
                $quantity = (int) $item->quantity;
                if ($net <= 0 || $quantity <= 0) {
                    throw new DomainException('A booking contains a service with no positive invoice value.');
                }

                return [
                    'account_id' => $revenue->public_id,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    // Inclusive tax or rounding can make the net non-divisible.
                    // Preserve the exact booked net without inventing unit prices.
                    'unit_price_minor' => $net % $quantity === 0 ? intdiv($net, $quantity) : null,
                    'credit_minor' => $net,
                    'tax_minor' => (int) $item->tax_minor,
                ];
            })->all();

            return $this->create([
                'type' => Invoice::TYPE_CUSTOMER_INVOICE,
                'party_type' => 'customer',
                'party_id' => $booking->customer->public_id,
                'booking_id' => $booking->public_id,
                'issue_date' => now()->toDateString(),
                'currency' => $booking->currency_code,
                'fx_rate' => (string) $booking->fx_rate,
                'memo' => 'Booking '.$booking->number,
                'lines' => $lines,
            ], $booking->branch, $actorId);
        });
    }

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
            $subaccount = $this->provisionSubaccount($partyType, $partyId, $partyType === 'customer'
                ? PartyAccount::ROLE_RECEIVABLE
                : PartyAccount::ROLE_PAYABLE);
            if ($subaccount->currency_code !== null && strtoupper($subaccount->currency_code) !== $currency) {
                throw new DomainException("This party's ledger account only accepts {$subaccount->currency_code}; the invoice currency is {$currency}.");
            }
            $issueDate = (string) $data['issue_date'];
            $dueDate = $data['due_date'] ?? null;
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $issueDate)));

            $linkedBookingId = $this->resolveBookingId($data['booking_id'] ?? null);
            if ($linkedBookingId !== null) {
                $linkedBooking = Booking::query()->findOrFail($linkedBookingId);
                if ($linkedBooking->branch_id !== $branch->id) {
                    throw new DomainException('The linked booking belongs to a different branch.');
                }
                if ($partyType === 'customer' && $linkedBooking->customer_id !== $partyId) {
                    throw new DomainException('The invoice customer must match the linked booking customer.');
                }
            }

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
                'booking_id' => $linkedBookingId,
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
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
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

    /**
     * Edit a draft invoice. Only the supplied fields change; replacing the
     * lines recomputes subtotal, tax, total and outstanding.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Invoice $invoice, array $data, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $actorId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'draft') {
                throw new DomainException('Only draft invoices can be edited.');
            }

            $currency = strtoupper((string) ($data['currency'] ?? $invoice->currency_code));
            $issueDate = (string) ($data['issue_date'] ?? $invoice->issue_date);
            $attributes = [];

            if (array_key_exists('issue_date', $data)) {
                $attributes['issue_date'] = $issueDate;
                $attributes['fx_rate_date'] = $issueDate;
            }
            if (array_key_exists('due_date', $data)) {
                $attributes['due_date'] = $data['due_date'];
            }
            if (array_key_exists('memo', $data)) {
                $attributes['memo'] = $data['memo'];
            }
            if (isset($data['currency']) || isset($data['fx_rate']) || isset($data['issue_date'])) {
                $attributes['currency_code'] = $currency;
                $attributes['fx_rate'] = $currency === $this->settings->baseCurrency()
                    ? '1'
                    : (string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $issueDate));
            }

            if (isset($data['lines'])) {
                $lines = $this->prepareLines($data, $currency, $invoice->branch);
                $invoice->lines()->delete();
                foreach ($lines as $line) {
                    InvoiceLine::query()->create([
                        'invoice_id' => $invoice->id,
                        'account_id' => $line['account_id'],
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_price_minor' => $line['unit_price_minor'],
                        'debit_minor' => $line['debit_minor'],
                        'credit_minor' => $line['credit_minor'],
                        'tax_minor' => $line['tax_minor'],
                        'tax_account_id' => $line['tax_account_id'] ?? null,
                        'party_type' => $line['party_type'] ?? null,
                        'party_id' => $line['party_id'] ?? null,
                    ]);
                }
                $subtotal = (int) array_sum(array_column($lines, 'subtotal_minor'));
                $tax = (int) array_sum(array_column($lines, 'tax_minor'));
                $attributes['subtotal_minor'] = $subtotal;
                $attributes['tax_minor'] = $tax;
                $attributes['total_minor'] = $subtotal + $tax;
                $attributes['outstanding_minor'] = $subtotal + $tax;
            }

            if ($attributes !== []) {
                $invoice->update($attributes);
                $this->audit->log('invoice.updated', $invoice, null, ['fields' => array_keys($attributes)], $actorId);
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

            // DEC-017 (work order 02): maker-checker. The person who created
            // the invoice cannot approve it while invoices.segregation is on.
            if ($this->settings->bool('invoices', 'segregation', true)
                && $actorId !== null
                && $actorId === $invoice->created_by) {
                throw new DomainException('The person who created an invoice cannot approve it.');
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
    public function issue(Invoice $invoice, ?int $actorId, ?string $overrideReason = null, bool $canOverrideCreditLimit = false): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId, $overrideReason, $canOverrideCreditLimit) {
            $invoice = Invoice::query()->with(['lines.account', 'creditNoteOf'])->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== 'approved') {
                throw new DomainException('Only approved invoices can be issued.');
            }

            if ($invoice->journal_entry_id !== null) {
                throw new DomainException("Invoice {$invoice->number} has already been issued.");
            }

            // Preserve existing partial-invoice workflows when no booking
            // revenue journal exists. If revenue WAS already booked, the
            // invoice must document that exact single posted transaction.
            $existingBookingRevenue = null;
            if ($invoice->booking_id !== null && $invoice->type === Invoice::TYPE_CUSTOMER_INVOICE) {
                $booking = Booking::query()->lockForUpdate()->findOrFail($invoice->booking_id);
                if (in_array($booking->status, ['cancelled', 'no_show'], true)
                    || $booking->customer_id !== $invoice->party_id
                    || $booking->branch_id !== $invoice->branch_id) {
                    throw new DomainException('The invoice must belong to an active booking for the same customer and branch.');
                }
                $existingBookingRevenue = JournalEntry::query()
                    ->where('source_type', 'booking')
                    ->where('source_id', $invoice->booking_id)
                    ->where('event', 'revenue')
                    ->where('status', 'posted')
                    ->whereNull('reversal_of_id')
                    ->whereNotExists(function ($query) {
                        $query->select('id')->from('journal_entries as reversals')
                            ->whereColumn('reversals.reversal_of_id', 'journal_entries.id');
                    })->first();

                if ($existingBookingRevenue) {
                    if ($booking->currency_code !== $invoice->currency_code
                        || bccomp((string) $booking->fx_rate, (string) $invoice->fx_rate, 8) !== 0
                        || (int) $booking->total_sell_minor !== (int) $invoice->total_minor
                        || (int) $booking->tax_minor !== (int) $invoice->tax_minor) {
                        throw new DomainException('Posted booking revenue can only be reused for an invoice matching the full booking total, tax, currency and FX rate.');
                    }
                    if ($booking->allocations()->exists()) {
                        throw new DomainException('This booking has directly allocated receipts. Reconcile these receipts before issuing an invoice.');
                    }
                    if (Invoice::query()->where('booking_id', $invoice->booking_id)
                        ->where('type', Invoice::TYPE_CUSTOMER_INVOICE)
                        ->where('status', Invoice::STATUS_ISSUED)
                        ->where('id', '!=', $invoice->id)->exists()) {
                        throw new DomainException('A customer invoice for this posted booking has already been issued.');
                    }
                }
            }

            // Prevent double cost recognition: booking completion already
            // posted the supplier cost (Dr cost / Cr supplier payable), so a
            // supplier invoice for the same booking must not post it again.
            if ($invoice->booking_id !== null
                && $invoice->type === Invoice::TYPE_SUPPLIER_INVOICE
                && $this->bookingCostRecognized((int) $invoice->booking_id)) {
                throw new DomainException(
                    "Cost was already recognized for booking {$invoice->booking_id}. "
                    .'Reverse the booking cost entry (or cancel the booking) before issuing this supplier invoice, '
                    .'or issue the invoice without linking it to the booking.'
                );
            }

            $this->assertOpenPeriod($invoice->issue_date, $invoice->branch_id, $actorId);

            // Customer credit limit (accounting.credit_limit_policy); credit notes only reduce exposure.
            $warnings = [];
            if (! $existingBookingRevenue && $invoice->type === Invoice::TYPE_CUSTOMER_INVOICE && $invoice->party_type === 'customer') {
                $customer = Customer::query()->find($invoice->party_id);
                if ($customer) {
                    $warnings = $this->creditLimits->enforce(
                        $customer,
                        $this->creditLimits->toBase((int) $invoice->total_minor, $invoice->currency_code, (string) $invoice->fx_rate),
                        $invoice,
                        'invoice issue',
                        $actorId,
                        $invoice->booking_id ? (int) $invoice->booking_id : null,
                        $canOverrideCreditLimit,
                        $overrideReason,
                    );
                }
            }

            if ($existingBookingRevenue) {
                // No new financial transaction: the booking's posted entry is
                // the ledger source of truth for this one full-value invoice.
                $entry = $existingBookingRevenue;
            } else {
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
            }

            $invoice->update([
                'status' => 'issued',
                'journal_entry_id' => $entry->id,
                'issued_at' => now(),
            ]);

            // A credit note settles the original invoice first; any excess
            // stays on the note as a credit balance.
            if ($invoice->type === Invoice::TYPE_CREDIT_NOTE && $invoice->credit_note_of_id !== null) {
                $original = Invoice::query()->lockForUpdate()->find($invoice->credit_note_of_id);
                if ($original) {
                    $applied = min((int) $original->outstanding_minor, (int) $invoice->total_minor);
                    $original->update(['outstanding_minor' => (int) $original->outstanding_minor - $applied]);
                    $invoice->update(['outstanding_minor' => (int) $invoice->total_minor - $applied]);
                }
            }

            $this->audit->log('invoice.issued', $invoice, ['status' => 'approved'], [
                'status' => 'issued',
                'journal' => $entry->number,
                'reused_booking_entry' => $existingBookingRevenue !== null,
            ], $actorId);

            return $invoice->refresh()->load('lines.account', 'journalEntry')->withWarnings($warnings);
        });
    }

    /** True when a booking-sourced cost entry exists for the booking and has not been reversed. */
    private function bookingCostRecognized(int $bookingId): bool
    {
        return JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $bookingId)
            ->where('event', 'cost')
            ->whereNull('reversal_of_id')
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('journal_entries as reversals')
                    ->whereColumn('reversals.reversal_of_id', 'journal_entries.id');
            })
            ->exists();
    }

    public function void(Invoice $invoice, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, ['draft', 'submitted'], true)) {
                throw new DomainException('Only draft or submitted invoices can be voided.');
            }

            if ($invoice->is_opening) {
                throw new DomainException('Opening balance documents cannot be voided; reverse the opening balance batch instead.');
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

            if ($invoice->is_opening) {
                throw new DomainException('Opening balance documents cannot be credited; reverse the opening balance batch instead.');
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

            // Cumulative credit can never exceed the invoice total.
            $alreadyCredited = (int) Invoice::query()
                ->where('credit_note_of_id', $invoice->id)
                ->where('status', '!=', Invoice::STATUS_VOIDED)
                ->sum('total_minor');
            $requested = isset($data['total_minor']) && (int) $data['total_minor'] !== 0
                ? abs((int) $data['total_minor'])
                : (int) $invoice->total_minor - $alreadyCredited;
            if ($requested <= 0) {
                throw new DomainException('The credit amount must be greater than zero.');
            }
            if ($requested + $alreadyCredited > (int) $invoice->total_minor) {
                throw new DomainException('Credit notes cannot exceed the invoice total.');
            }

            // Partial credit: scale every line proportionally; the last line
            // absorbs the rounding residual so the note adds up exactly.
            if ($requested !== (int) $invoice->total_minor) {
                $creditNoteData['lines'] = $this->scaleLines($creditNoteLines, $invoice, $requested);
            }

            $creditNote = $this->create($creditNoteData, $invoice->branch, $actorId);
            $this->submit($creditNote, $actorId);
            $this->approve($creditNote, $actorId);

            return $creditNote->refresh()->load('lines.account', 'creditNoteOf');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function scaleLines(array $lines, Invoice $invoice, int $targetTotal): array
    {
        $total = (int) $invoice->total_minor;
        $targetTax = $total === 0 ? 0 : intdiv((int) $invoice->tax_minor * $targetTotal + intdiv($total, 2), $total);
        $targetSubtotal = $targetTotal - $targetTax;
        $subtotal = max(1, (int) $invoice->subtotal_minor);
        $last = array_key_last($lines);
        $usedAmount = 0;
        $usedTax = 0;

        foreach ($lines as $index => &$line) {
            $amount = (int) $line['debit_minor'] + (int) $line['credit_minor'];
            $tax = (int) $line['tax_minor'];
            if ($index === $last) {
                $newAmount = $targetSubtotal - $usedAmount;
                $newTax = $targetTax - $usedTax;
            } else {
                $newAmount = intdiv($amount * $targetSubtotal + intdiv($subtotal, 2), $subtotal);
                $newTax = (int) $invoice->tax_minor === 0 ? 0 : intdiv($tax * $targetTax + intdiv((int) $invoice->tax_minor, 2), (int) $invoice->tax_minor);
            }
            $usedAmount += $newAmount;
            $usedTax += $newTax;
            $debit = (int) $line['debit_minor'] > 0;
            $line['debit_minor'] = $debit ? $newAmount : 0;
            $line['credit_minor'] = $debit ? 0 : $newAmount;
            $line['tax_minor'] = $newTax;
        }
        unset($line);

        foreach ($lines as $line) {
            if ((int) $line['debit_minor'] + (int) $line['credit_minor'] <= 0) {
                throw new DomainException('The credit amount is too small to spread across the invoice lines.');
            }
        }

        return $lines;
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

            $quantity = (int) ($raw['quantity'] ?? 1);
            $unitPrice = array_key_exists('unit_price_minor', $raw)
                ? (int) $raw['unit_price_minor'] : null;
            if ($quantity < 1 || ($unitPrice !== null && ($unitPrice <= 0 || $quantity * $unitPrice !== $debit + $credit))) {
                throw new DomainException("Line {$position}: quantity and unit price must match the line amount.");
            }

            // When the UI supplies the tax toggle, never trust a manually supplied
            // tax amount: calculate it from the settings (rate + inclusive flag).
            // Inclusive: the entered amount contains the tax, so the line keeps the net.
            $applied = array_key_exists('tax_applied', $raw);
            $tax = $applied
                ? ((bool) $raw['tax_applied'] ? $this->taxPolicy->taxOn($debit + $credit) : 0)
                : (int) ($raw['tax_minor'] ?? 0);
            if ($applied && $tax > 0 && $this->taxPolicy->inclusive()) {
                if ($debit > 0) {
                    $debit -= $tax;
                } else {
                    $credit -= $tax;
                }
            }
            if ($tax < 0) {
                throw new DomainException("Line {$position}: tax cannot be negative.");
            }
            $this->taxPolicy->assertTaxAllowed($tax, "Line {$position}");

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
                'quantity' => $quantity,
                'unit_price_minor' => $unitPrice,
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
