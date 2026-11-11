<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Tax\Services\TaxPolicy;
use App\Modules\Treasury\Models\CashAccount;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expense lifecycle: draft -> submitted -> approved (posts once) | rejected | cancelled,
 * and approved -> reversed. Only approval touches the ledger, and only through
 * PostingService (DEC-032: thresholds and VAT account are provisional).
 */
class ExpenseService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly FxService $fx,
        private readonly SequenceService $sequences,
        private readonly AuditService $audit,
        private readonly PartyLedgerService $ledger,
        private readonly TaxPolicy $taxPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Branch $branch, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $attributes = $this->attributes($data, $branch);

            $expense = Expense::query()->create($attributes + [
                'number' => $this->sequences->next('expense', $branch, Carbon::parse($attributes['expense_date'])),
                'branch_id' => $branch->id,
                'status' => 'draft',
                'created_by' => $actorId,
            ]);

            if (! empty($data['submit'])) {
                $this->transitionToSubmitted($expense, $actorId);
            }

            $this->audit->log('expense.created', $expense, null, ['number' => $expense->number, 'amount_minor' => $expense->amount_minor], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $data, $actorId) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== 'draft') {
                throw new DomainException('Only draft expenses can be edited.');
            }

            $merged = array_merge($this->editable($expense), $data);
            $expense->update($this->attributes($merged, $expense->branch));

            $this->audit->log('expense.updated', $expense, null, ['number' => $expense->number], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    public function submit(Expense $expense, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $actorId) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== 'draft') {
                throw new DomainException('Only draft expenses can be submitted.');
            }

            $this->transitionToSubmitted($expense, $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    public function approve(Expense $expense, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $actorId) {
            $expense = Expense::query()->with('category.account', 'cashAccount.account', 'branch')->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== 'submitted') {
                throw new DomainException('Only submitted expenses can be approved.');
            }

            if ($this->settings->bool('expenses', 'segregation', true)
                && $actorId !== null
                && in_array($actorId, array_filter([$expense->created_by, $expense->submitted_by]), true)) {
                throw new DomainException('The person who created or submitted an expense cannot approve it.');
            }

            if (! $expense->category->is_active) {
                throw new DomainException('The expense category is inactive.');
            }

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $expense->expense_date->toDateString(),
                branch: $expense->branch,
                currency: $expense->currency_code,
                lines: $this->lines($expense),
                memo: 'Expense '.$expense->number.' - '.$expense->description,
                sourceType: 'expense',
                sourceId: $expense->id,
                event: 'approved',
                fxRate: (string) $expense->fx_rate,
                fxRateDate: $expense->fx_rate_date,
                fxRateSource: $expense->fx_rate_source,
                actorId: $actorId,
            ));

            $expense->update([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            $this->audit->log('expense.approved', $expense, ['status' => 'submitted'], ['status' => 'approved', 'journal' => $entry->number], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    public function reject(Expense $expense, string $reason, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actorId) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== 'submitted') {
                throw new DomainException('Only submitted expenses can be rejected.');
            }

            $expense->update(['status' => 'rejected', 'rejection_reason' => $reason]);
            $this->audit->log('expense.rejected', $expense, ['status' => 'submitted'], ['status' => 'rejected', 'reason' => $reason], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    /** Move a rejected expense back to draft; the rejection reason is kept in the audit log. */
    public function reopen(Expense $expense, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $actorId) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== 'rejected') {
                throw new DomainException('Only rejected expenses can be reopened.');
            }

            $reason = $expense->rejection_reason;
            $expense->update(['status' => 'draft', 'rejection_reason' => null]);
            $this->audit->log('expense.reopened', $expense, ['status' => 'rejected', 'rejection_reason' => $reason], ['status' => 'draft'], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    public function cancel(Expense $expense, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $actorId) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if (! in_array($expense->status, ['draft', 'submitted', 'rejected'], true)) {
                throw new DomainException('Only unposted expenses can be cancelled; reverse an approved expense instead.');
            }

            $before = $expense->status;
            $expense->update(['status' => 'cancelled']);
            $this->audit->log('expense.cancelled', $expense, ['status' => $before], ['status' => 'cancelled'], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    public function reverse(Expense $expense, string $reason, ?int $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actorId) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== 'approved') {
                throw new DomainException('Only approved expenses can be reversed.');
            }

            $this->posting->reverse($expense->journalEntry, $reason, $actorId);
            $expense->update(['status' => 'reversed']);
            $this->audit->log('expense.reversed', $expense, ['status' => 'approved'], ['status' => 'reversed', 'reason' => $reason], $actorId);

            return $expense->refresh()->load('category.account', 'cashAccount');
        });
    }

    private function transitionToSubmitted(Expense $expense, ?int $actorId): void
    {
        $expense->update(['status' => 'submitted', 'submitted_by' => $actorId]);
        $this->audit->log('expense.submitted', $expense, ['status' => 'draft'], ['status' => 'submitted'], $actorId);
    }

    /** @return array<int, PostingLine> */
    private function lines(Expense $expense): array
    {
        $net = $expense->amount_minor - $expense->tax_minor;
        $lines = [PostingLine::debit($expense->category->account, $net, null, null, $expense->description)];

        if ($expense->tax_minor > 0) {
            $taxAccount = $this->posting->account((string) $this->settings->get('accounting', 'input_tax_account_code', '1160'));
            $lines[] = PostingLine::debit($taxAccount, $expense->tax_minor, null, null, 'Input tax '.$expense->number);
        }

        if ($expense->cashAccount) {
            $lines[] = PostingLine::credit($expense->cashAccount->account, $expense->amount_minor, null, null, 'Paid '.$expense->number);
        } else {
            $ap = $expense->payee_id !== null
                ? $this->ledger->payable($expense->payee_id)
                : $this->posting->account((string) $this->settings->get('accounting', 'ap_account_code', '2100'));
            $lines[] = PostingLine::credit($ap, $expense->amount_minor, 'supplier', $expense->payee_id, 'Payable '.$expense->number);
        }

        return $lines;
    }

    /**
     * Validate and normalise input into column values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Branch $branch): array
    {
        $category = ExpenseCategory::query()->where('public_id', $data['category_id'])->first();
        if (! $category || ! $category->is_active) {
            throw new DomainException('Choose an active expense category.');
        }

        $amount = (int) $data['amount_minor'];
        $tax = (int) ($data['tax_minor'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('The expense amount must be greater than zero.');
        }
        $this->taxPolicy->assertTaxAllowed($tax);
        if ($tax < 0 || $tax >= $amount) {
            throw new DomainException('Tax must be zero or less than the total amount (tax is included in the amount).');
        }

        $currency = strtoupper((string) $data['currency']);
        $base = $this->settings->baseCurrency();
        $date = Carbon::parse($data['expense_date'])->toDateString();
        $rate = $currency === $base
            ? '1'
            : (isset($data['fx_rate']) && $data['fx_rate'] !== '' ? (string) $data['fx_rate'] : $this->fx->rateFor($currency, $date));

        $cashAccount = null;
        if (! empty($data['cash_account_id'])) {
            $cashAccount = CashAccount::query()->where('public_id', $data['cash_account_id'])->first();
            if (! $cashAccount || ! $cashAccount->is_active) {
                throw new DomainException('Choose an active cash or bank account.');
            }
            if ($cashAccount->branch_id !== null && $cashAccount->branch_id !== $branch->id) {
                throw new DomainException('The cash account belongs to another branch.');
            }
            if (strtoupper($cashAccount->currency_code) !== $currency) {
                throw new DomainException("The expense currency must match the {$cashAccount->currency_code} payment account.");
            }
        }

        $payeeType = $data['payee_type'] ?? 'none';
        $payeeId = null;
        $payeeName = $data['payee_name'] ?? null;

        if ($payeeType === 'supplier') {
            $supplier = Supplier::query()->where('public_id', $data['payee_id'] ?? '')->first();
            if (! $supplier) {
                throw new DomainException('The selected supplier does not exist.');
            }
            if ($supplier->branch_id !== null && $supplier->branch_id !== $branch->id) {
                throw new DomainException('The supplier belongs to another branch.');
            }
            $payeeId = $supplier->id;
            $payeeName = $supplier->name_en;
        } elseif ($payeeType !== 'none') {
            throw new DomainException('Unsupported payee type.');
        }

        if (! $cashAccount && $payeeType !== 'supplier') {
            throw new DomainException('Choose a payment account, or a supplier to book the expense on account.');
        }

        return [
            'expense_category_id' => $category->id,
            'expense_date' => $date,
            'payee_type' => $payeeType,
            'payee_id' => $payeeId,
            'payee_name' => $payeeName,
            'cash_account_id' => $cashAccount?->id,
            'currency_code' => $currency,
            'fx_rate' => $rate,
            'fx_rate_date' => $date,
            'fx_rate_source' => 'manual',
            'amount_minor' => $amount,
            'tax_minor' => $tax,
            'description' => $data['description'],
            'reference' => $data['reference'] ?? null,
        ];
    }

    /** @return array<string, mixed> Current values in the shape accepted by attributes(). */
    private function editable(Expense $expense): array
    {
        return [
            'category_id' => $expense->category->public_id,
            'expense_date' => $expense->expense_date->toDateString(),
            'payee_type' => $expense->payee_type,
            'payee_id' => $expense->payee_type === 'supplier' ? Supplier::query()->find($expense->payee_id)?->public_id : null,
            'payee_name' => $expense->payee_name,
            'cash_account_id' => $expense->cashAccount?->public_id,
            'currency' => $expense->currency_code,
            'fx_rate' => (string) $expense->fx_rate,
            'amount_minor' => $expense->amount_minor,
            'tax_minor' => $expense->tax_minor,
            'description' => $expense->description,
            'reference' => $expense->reference,
        ];
    }
}
