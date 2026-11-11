<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Accounting\Models\OpeningBalanceLine;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceApplication;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\SupplierPayment;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Controlled cutover balances (TE-017). A batch is validated per currency, posts
 * through PostingService as one entry per currency, can post only once, and is
 * corrected by reversal and a new batch (DEC-033).
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly FxService $fx,
        private readonly AuditService $audit,
        private readonly PartyLedgerService $partyLedger,
        private readonly SequenceService $sequences,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Branch $branch, ?int $actorId): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $asOf = (string) $data['as_of_date'];
            $lines = $this->prepareLines($data['lines'] ?? [], $asOf);

            $batch = OpeningBalanceBatch::query()->create([
                'branch_id' => $branch->id,
                'as_of_date' => $asOf,
                'memo' => $data['memo'] ?? null,
                'status' => 'draft',
                'created_by' => $actorId,
            ]);

            foreach ($lines as $line) {
                $batch->lines()->create($line);
            }

            $this->audit->log('opening_balance.created', $batch, null, ['as_of_date' => $asOf, 'lines' => count($lines)], $actorId);

            return $batch->load('lines.account', 'branch');
        });
    }

    public function discard(OpeningBalanceBatch $batch, ?int $actorId): void
    {
        DB::transaction(function () use ($batch, $actorId) {
            $batch = OpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== 'draft') {
                throw new DomainException('Only draft batches can be discarded; reverse a posted batch instead.');
            }

            $this->audit->log('opening_balance.discarded', $batch, ['status' => 'draft'], null, $actorId);
            $batch->delete();
        });
    }

    public function post(OpeningBalanceBatch $batch, ?int $actorId): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $actorId) {
            $batch = OpeningBalanceBatch::query()->with('branch')->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== 'draft') {
                throw new DomainException('This batch has already been posted or reversed and cannot be posted again.');
            }

            $clash = OpeningBalanceBatch::query()
                ->where('branch_id', $batch->branch_id)
                ->whereDate('as_of_date', $batch->as_of_date)
                ->where('status', 'posted')
                ->exists();
            if ($clash) {
                throw new DomainException('An opening balance batch is already posted for this branch and date. Reverse it first.');
            }

            $batch->load('lines.account');
            $groups = $batch->lines->groupBy('currency_code');
            $arCode = (string) $this->settings->get('accounting', 'ar_account_code');
            $apCode = (string) $this->settings->get('accounting', 'ap_account_code');

            if ($groups->isEmpty()) {
                throw new DomainException('The batch has no lines.');
            }

            foreach ($groups as $currency => $lines) {
                $this->assertBalanced($currency, $lines->sum('debit_minor'), $lines->sum('credit_minor'));

                $entry = $this->posting->post(PostingRequest::make(
                    entryDate: $batch->as_of_date->toDateString(),
                    branch: $batch->branch,
                    currency: $currency,
                    lines: $lines->map(fn (OpeningBalanceLine $line) => new PostingLine(
                        account: $this->postingAccount($line, $arCode, $apCode),
                        debitMinor: $line->debit_minor,
                        creditMinor: $line->credit_minor,
                        partyType: $line->party_type,
                        partyId: $line->party_id,
                        description: $line->description ?? 'Opening balance',
                    ))->all(),
                    memo: 'Opening balances '.$batch->as_of_date->toDateString().' ('.$currency.')',
                    sourceType: 'opening_balance',
                    sourceId: $batch->id,
                    event: 'currency:'.$currency,
                    fxRate: (string) $lines->first()->fx_rate,
                    fxRateDate: $batch->as_of_date->toDateString(),
                    actorId: $actorId,
                ));

                $batch->entries()->attach($entry->id, ['currency_code' => $currency]);

                foreach ($lines as $line) {
                    $this->createOpeningRecord($batch, $line, $entry->id, $actorId);
                }
            }

            $batch->update(['status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now()]);
            $this->audit->log('opening_balance.posted', $batch, ['status' => 'draft'], ['status' => 'posted'], $actorId);

            return $batch->refresh()->load('lines.account', 'branch');
        });
    }

    public function reverse(OpeningBalanceBatch $batch, string $reason, ?int $actorId): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $reason, $actorId) {
            $batch = OpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== 'posted') {
                throw new DomainException('Only posted batches can be reversed.');
            }

            $entryIds = $batch->entries()->pluck('journal_entries.id');
            $lineIds = $batch->lines()->pluck('id');
            $documents = Invoice::query()->where('is_opening', true)
                ->where(fn ($q) => $q->whereIn('journal_entry_id', $entryIds)->orWhereIn('opening_balance_line_id', $lineIds))
                ->where('status', '!=', Invoice::STATUS_VOIDED)
                ->lockForUpdate()
                ->get();
            $receipts = Payment::query()->where('is_opening', true)->whereIn('opening_balance_line_id', $lineIds)
                ->where('status', 'posted')->lockForUpdate()->get();
            $advances = SupplierPayment::query()->where('is_opening', true)->whereIn('opening_balance_line_id', $lineIds)
                ->where('status', 'posted')->lockForUpdate()->get();

            foreach ($documents as $document) {
                $applied = $document->applications()->whereNull('reversed_at')->exists();
                if ($applied || (int) $document->outstanding_minor !== (int) $document->total_minor) {
                    throw new DomainException(
                        "Opening document {$document->number} has already been settled; undo its applications before reversing the batch."
                    );
                }
            }
            foreach ($receipts as $receipt) {
                $used = InvoiceApplication::query()->active()->where('payment_id', $receipt->id)->exists()
                    || PaymentAllocation::query()->where('payment_id', $receipt->id)->exists()
                    || (int) $receipt->unallocated_minor !== (int) $receipt->amount_minor;
                if ($used) {
                    throw new DomainException(
                        "Opening credit {$receipt->number} has already been applied; undo its applications before reversing the batch."
                    );
                }
            }
            foreach ($advances as $advance) {
                if ($advance->appliedMinor() > 0) {
                    throw new DomainException(
                        "Opening supplier advance {$advance->public_id} has already been applied; undo its applications before reversing the batch."
                    );
                }
            }

            // Reclassifications made by the backfill command are corrected first.
            $backfills = JournalEntry::query()
                ->where('source_type', OpeningBalanceBackfillService::SOURCE_TYPE)
                ->where('source_id', $batch->id)
                ->whereNull('reversal_of_id')
                ->where('status', 'posted')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as r')->whereColumn('r.reversal_of_id', 'journal_entries.id'))
                ->orderByDesc('id')
                ->get();
            foreach ($backfills as $entry) {
                $this->posting->reverse($entry, $reason, $actorId);
            }

            foreach ($batch->entries as $entry) {
                $this->posting->reverse($entry, $reason, $actorId);
            }

            foreach ($documents as $document) {
                $document->update(['status' => Invoice::STATUS_VOIDED, 'outstanding_minor' => 0]);
                $this->audit->log('invoice.voided', $document, ['status' => Invoice::STATUS_ISSUED], [
                    'status' => Invoice::STATUS_VOIDED,
                    'reason' => 'Opening balance batch reversed',
                ], $actorId);
            }
            foreach ($receipts as $receipt) {
                $receipt->update(['status' => 'cancelled', 'unallocated_minor' => 0]);
                $this->audit->log('payment.cancelled', $receipt, ['status' => 'posted'], [
                    'status' => 'cancelled', 'reason' => 'Opening balance batch reversed',
                ], $actorId);
            }
            foreach ($advances as $advance) {
                $advance->update(['status' => 'cancelled']);
                $this->audit->log('supplier_payment.cancelled', $advance, ['status' => 'posted'], [
                    'status' => 'cancelled', 'reason' => 'Opening balance batch reversed',
                ], $actorId);
            }

            $batch->update(['status' => 'reversed', 'reversal_reason' => $reason]);
            $this->audit->log('opening_balance.reversed', $batch, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason], $actorId);

            return $batch->refresh()->load('lines.account', 'branch');
        });
    }

    /**
     * Compare the batch to what the ledger holds for it, and AR/AP party detail to control accounts.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(OpeningBalanceBatch $batch): array
    {
        $batch->load('lines.account');

        $totals = [];
        foreach ($batch->lines->groupBy('currency_code') as $currency => $lines) {
            $debit = (int) $lines->sum('debit_minor');
            $credit = (int) $lines->sum('credit_minor');
            $totals[] = ['currency' => $currency, 'debit_minor' => $debit, 'credit_minor' => $credit, 'balanced' => $debit === $credit];
        }

        $arCode = $this->arCode();
        $apCode = $this->apCode();
        $ledger = [];
        $ledgerParty = ['customer' => 0, 'supplier' => 0];
        if ($batch->status === 'posted') {
            $entryIds = $batch->entries()->pluck('journal_entries.id');
            $rows = DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)
                ->groupBy('account_id', 'currency_code', 'party_type', 'party_id')
                ->selectRaw('account_id, currency_code, party_type, party_id, SUM(debit_minor) as debit, SUM(credit_minor) as credit')
                ->get();
            // Party lines post to the party's subaccount; roll them up to the control account the batch names.
            $parents = DB::table('party_accounts as pa')
                ->join('accounts as a', 'a.id', '=', 'pa.account_id')
                ->whereIn('pa.account_id', $rows->pluck('account_id'))
                ->pluck('a.parent_id', 'pa.account_id');
            // Customer credit / supplier advance lines post to the advance account
            // tagged with the party; they roll up to the control account too.
            $controlIds = ['customer' => (int) Account::query()->where('code', $arCode)->value('id'), 'supplier' => (int) Account::query()->where('code', $apCode)->value('id')];
            $advanceIds = [
                'customer' => (int) Account::query()->where('code', $this->customerAdvanceCode())->value('id'),
                'supplier' => (int) Account::query()->where('code', $this->supplierAdvanceCode())->value('id'),
            ];
            $routed = [];
            foreach ($batch->lines as $line) {
                if (in_array($this->kind($line, $arCode, $apCode), ['customer_credit', 'supplier_advance'], true)) {
                    $routed[$line->party_type.'|'.$line->party_id.'|'.$line->currency_code] = true;
                }
            }
            foreach ($rows as $row) {
                $accountId = (int) ($parents[$row->account_id] ?? $row->account_id);
                $partyType = $row->party_type;
                if (isset($advanceIds[$partyType]) && (int) $row->account_id === $advanceIds[$partyType]
                    && isset($routed[$partyType.'|'.$row->party_id.'|'.$row->currency_code])) {
                    $accountId = $controlIds[$partyType];
                }
                if (isset($controlIds[$partyType]) && $accountId === $controlIds[$partyType]) {
                    $ledgerParty[$partyType] += (int) $row->debit - (int) $row->credit;
                }
                $key = $accountId.'|'.$row->currency_code;
                $ledger[$key] = [
                    ($ledger[$key][0] ?? 0) + (int) $row->debit,
                    ($ledger[$key][1] ?? 0) + (int) $row->credit,
                ];
            }
        }

        $accounts = [];
        foreach ($batch->lines->groupBy(fn ($line) => $line->account_id.'|'.$line->currency_code) as $key => $lines) {
            $debit = (int) $lines->sum('debit_minor');
            $credit = (int) $lines->sum('credit_minor');
            [$ledgerDebit, $ledgerCredit] = $ledger[$key] ?? [null, null];

            $accounts[] = [
                'account_code' => $lines->first()->account->code,
                'currency' => $lines->first()->currency_code,
                'batch_debit_minor' => $debit,
                'batch_credit_minor' => $credit,
                'ledger_debit_minor' => $ledgerDebit,
                'ledger_credit_minor' => $ledgerCredit,
                'matches_ledger' => $batch->status === 'posted' ? ($ledgerDebit === $debit && $ledgerCredit === $credit) : null,
            ];
        }

        $detail = [];
        foreach (['ar_account_code' => 'customer', 'ap_account_code' => 'supplier'] as $setting => $partyType) {
            $code = (string) $this->settings->get('accounting', $setting);
            $control = $batch->lines->filter(fn ($line) => $line->account->code === $code);
            if ($control->isEmpty()) {
                continue;
            }
            $withParty = $control->filter(fn ($line) => $line->party_type === $partyType && $line->party_id !== null);
            $controlNet = (int) $control->sum('debit_minor') - (int) $control->sum('credit_minor');
            $partyNet = (int) $withParty->sum('debit_minor') - (int) $withParty->sum('credit_minor');

            $detail[] = [
                'account_code' => $code,
                'party_type' => $partyType,
                'control_minor' => $controlNet,
                'party_detail_minor' => $partyNet,
                'matches' => $controlNet === $partyNet,
                'ledger_party_minor' => $batch->status === 'posted' ? $ledgerParty[$partyType] : null,
                'ledger_matches' => $batch->status === 'posted' ? $ledgerParty[$partyType] === $partyNet : null,
            ];
        }

        $reconciled = collect($totals)->every(fn ($row) => $row['balanced'])
            && collect($detail)->every(fn ($row) => $row['matches'] && $row['ledger_matches'] !== false)
            && ($batch->status !== 'posted' || collect($accounts)->every(fn ($row) => $row['matches_ledger']));

        return [
            'batch' => $batch->toApiArray(),
            'currency_totals' => $totals,
            'accounts' => $accounts,
            'control_account_detail' => $detail,
            'reconciled' => $reconciled,
        ];
    }

    /**
     * Where a party line posts (ADR-0015): a customer debit / supplier credit
     * goes to the party's own receivable/payable subaccount; the opposite side
     * (customer credit, supplier advance) goes to the advance account tagged
     * with the party, never to the control account.
     */
    private function postingAccount(OpeningBalanceLine $line, string $arCode, string $apCode): Account
    {
        return match ($this->kind($line, $arCode, $apCode)) {
            'customer_document' => $this->partyLedger->receivable((int) $line->party_id),
            'supplier_document' => $this->partyLedger->payable((int) $line->party_id),
            'customer_credit' => $this->posting->account($this->customerAdvanceCode()),
            'supplier_advance' => $this->posting->account($this->supplierAdvanceCode()),
            default => $line->account,
        };
    }

    /** The account a party line moves to (shared with the backfill command). */
    public function targetAccount(OpeningBalanceLine $line): Account
    {
        return $this->postingAccount($line, $this->arCode(), $this->apCode());
    }

    /**
     * customer_document (debit), supplier_document (credit), customer_credit
     * (credit) or supplier_advance (debit) for party lines on the control
     * accounts; null for everything else.
     */
    public function kind(OpeningBalanceLine $line, ?string $arCode = null, ?string $apCode = null): ?string
    {
        $arCode ??= $this->arCode();
        $apCode ??= $this->apCode();
        if ($line->party_id === null) {
            return null;
        }
        $code = $line->account->code;
        if ($code === $arCode && $line->party_type === 'customer') {
            return $line->debit_minor > 0 ? 'customer_document' : 'customer_credit';
        }
        if ($code === $apCode && $line->party_type === 'supplier') {
            return $line->credit_minor > 0 ? 'supplier_document' : 'supplier_advance';
        }

        return null;
    }

    private function arCode(): string
    {
        return (string) $this->settings->get('accounting', 'ar_account_code');
    }

    private function apCode(): string
    {
        return (string) $this->settings->get('accounting', 'ap_account_code');
    }

    private function customerAdvanceCode(): string
    {
        return (string) $this->settings->get('accounting', 'advance_account_code', '2300');
    }

    private function supplierAdvanceCode(): string
    {
        return (string) $this->settings->get('accounting', 'supplier_advance_account_code', '1153');
    }

    /**
     * A customer debit / supplier credit becomes an issued opening document;
     * a customer credit / supplier debit becomes an unapplied opening receipt /
     * supplier advance. All of them can then be settled through
     * InvoiceSettlementService. None carries a journal entry of its own: they
     * point at the entry that put the balance on the party's account.
     */
    public function createOpeningRecord(OpeningBalanceBatch $batch, OpeningBalanceLine $line, int $entryId, ?int $actorId): ?Model
    {
        return match ($this->kind($line)) {
            'customer_document' => $this->createOpeningDocument($batch, $line, $entryId, true, $actorId),
            'supplier_document' => $this->createOpeningDocument($batch, $line, $entryId, false, $actorId),
            'customer_credit' => $this->createOpeningReceipt($batch, $line, $actorId),
            'supplier_advance' => $this->createOpeningSupplierAdvance($batch, $line, $actorId),
            default => null,
        };
    }

    private function createOpeningDocument(OpeningBalanceBatch $batch, OpeningBalanceLine $line, int $entryId, bool $isCustomer, ?int $actorId): Invoice
    {
        $asOf = $batch->as_of_date->toDateString();
        $amount = $isCustomer ? (int) $line->debit_minor : (int) $line->credit_minor;
        $invoice = Invoice::query()->create([
            'type' => $isCustomer ? Invoice::TYPE_CUSTOMER_INVOICE : Invoice::TYPE_SUPPLIER_INVOICE,
            'series' => 'OB',
            'number' => $this->sequences->next('invoice.OB', $batch->branch, $batch->as_of_date),
            'party_type' => $line->party_type,
            'party_id' => $line->party_id,
            'branch_id' => $batch->branch_id,
            'issue_date' => $asOf,
            'currency_code' => $line->currency_code,
            'fx_rate' => $line->fx_rate,
            'fx_rate_date' => $asOf,
            'fx_rate_source' => 'opening_balance',
            'status' => Invoice::STATUS_ISSUED,
            'subtotal_minor' => $amount,
            'tax_minor' => 0,
            'total_minor' => $amount,
            'outstanding_minor' => $amount,
            'journal_entry_id' => $entryId,
            'is_opening' => true,
            'opening_balance_line_id' => $line->id,
            'created_by' => $actorId,
            'issued_at' => now(),
            'memo' => $line->description ?? 'Opening balance '.$asOf,
        ]);

        $this->audit->log('invoice.opening_created', $invoice, null, [
            'number' => $invoice->number,
            'total_minor' => $amount,
            'batch' => $batch->id,
        ], $actorId);

        return $invoice;
    }

    /** Customer credit: a posted, unapplied receipt that moved no cash. */
    private function createOpeningReceipt(OpeningBalanceBatch $batch, OpeningBalanceLine $line, ?int $actorId): Payment
    {
        $asOf = $batch->as_of_date->toDateString();
        $amount = (int) $line->credit_minor;
        $payment = Payment::query()->create([
            'number' => $this->sequences->next('payment', $batch->branch, $batch->as_of_date),
            'customer_id' => $line->party_id,
            'branch_id' => $batch->branch_id,
            'method' => 'opening',
            'cash_account_id' => null,
            'amount_minor' => $amount,
            'currency_code' => $line->currency_code,
            'fx_rate' => $line->fx_rate,
            'fx_rate_date' => $asOf,
            'fx_rate_source' => 'opening_balance',
            'unallocated_minor' => $amount,
            'status' => 'posted',
            'paid_at' => $asOf.' 00:00:00',
            'created_by' => $actorId,
            'is_opening' => true,
            'opening_balance_line_id' => $line->id,
        ]);

        $this->audit->log('payment.opening_created', $payment, null, [
            'number' => $payment->number,
            'amount_minor' => $amount,
            'batch' => $batch->id,
        ], $actorId);

        return $payment;
    }

    /** Supplier debit balance: a posted, unapplied supplier payment (advance) that moved no cash. */
    private function createOpeningSupplierAdvance(OpeningBalanceBatch $batch, OpeningBalanceLine $line, ?int $actorId): SupplierPayment
    {
        $asOf = $batch->as_of_date->toDateString();
        $amount = (int) $line->debit_minor;
        $payment = SupplierPayment::query()->create([
            'supplier_id' => $line->party_id,
            'branch_id' => $batch->branch_id,
            'amount_minor' => $amount,
            'currency_code' => $line->currency_code,
            'fx_rate' => $line->fx_rate,
            'fx_rate_date' => $asOf,
            'fx_rate_source' => 'opening_balance',
            'cash_account_id' => null,
            'status' => 'posted',
            'created_by' => $actorId,
            'paid_at' => $asOf.' 00:00:00',
            'is_opening' => true,
            'opening_balance_line_id' => $line->id,
        ]);

        $this->audit->log('supplier_payment.opening_created', $payment, null, [
            'amount_minor' => $amount,
            'batch' => $batch->id,
        ], $actorId);

        return $payment;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array<string, mixed>>
     */
    private function prepareLines(array $rawLines, string $asOf): array
    {
        if (count($rawLines) < 2) {
            throw new DomainException('A batch needs at least two lines.');
        }

        $base = $this->settings->baseCurrency();
        $arCode = (string) $this->settings->get('accounting', 'ar_account_code');
        $apCode = (string) $this->settings->get('accounting', 'ap_account_code');
        $prepared = [];
        $rates = [];
        $sums = [];

        foreach ($rawLines as $index => $raw) {
            $position = $index + 1;
            $reference = (string) $raw['account_id'];
            $account = Account::query()->where('public_id', $reference)->first()
                ?? Account::query()->where('code', $reference)->first();

            if (! $account || ! $account->is_active || ! $account->is_postable) {
                throw new DomainException("Line {$position}: choose an active, postable account.");
            }

            $debit = (int) ($raw['debit_minor'] ?? 0);
            $credit = (int) ($raw['credit_minor'] ?? 0);
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                throw new DomainException("Line {$position}: enter exactly one of debit or credit, greater than zero.");
            }

            $currency = strtoupper((string) ($raw['currency'] ?? $base));
            if ($account->currency_code !== null && strtoupper($account->currency_code) !== $currency) {
                throw new DomainException("Line {$position}: account {$account->code} only accepts {$account->currency_code}.");
            }

            $rates[$currency] ??= $currency === $base
                ? '1'
                : (isset($raw['fx_rate']) && $raw['fx_rate'] !== '' ? (string) $raw['fx_rate'] : $this->fx->rateFor($currency, $asOf));

            [$partyType, $partyId] = $this->party($raw, $account, $arCode, $apCode, $position);

            $sums[$currency]['debit'] = ($sums[$currency]['debit'] ?? 0) + $debit;
            $sums[$currency]['credit'] = ($sums[$currency]['credit'] ?? 0) + $credit;

            $prepared[] = [
                'account_id' => $account->id,
                'currency_code' => $currency,
                'fx_rate' => $rates[$currency],
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'description' => $raw['description'] ?? null,
            ];
        }

        foreach ($sums as $currency => $sum) {
            $this->assertBalanced($currency, $sum['debit'], $sum['credit']);
        }

        return $prepared;
    }

    /** @return array{0: ?string, 1: ?int} */
    private function party(array $raw, Account $account, string $arCode, string $apCode, int $position): array
    {
        $isControl = in_array($account->code, [$arCode, $apCode], true);
        $expected = $account->code === $arCode ? 'customer' : ($account->code === $apCode ? 'supplier' : null);

        $type = $raw['party_type'] ?? null;
        $publicId = $raw['party_id'] ?? null;

        if ($isControl && ($type === null || $publicId === null)) {
            throw new DomainException("Line {$position}: receivable and payable lines must name the customer or supplier.");
        }

        if ($type === null && $publicId === null) {
            return [null, null];
        }

        if ($isControl && $type !== $expected) {
            throw new DomainException("Line {$position}: account {$account->code} takes a {$expected} as its party.");
        }

        $party = match ($type) {
            'customer' => Customer::query()->where('public_id', (string) $publicId)->first(),
            'supplier' => Supplier::query()->where('public_id', (string) $publicId)->first(),
            default => null,
        };

        if (! $party) {
            throw new DomainException("Line {$position}: the selected party does not exist.");
        }

        return [$type, $party->id];
    }

    private function assertBalanced(string $currency, int $debit, int $credit): void
    {
        if ($debit !== $credit) {
            throw new DomainException("The {$currency} lines are not balanced: debits {$debit} vs credits {$credit}.");
        }
    }
}
