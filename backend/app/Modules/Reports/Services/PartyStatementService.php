<?php

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Support\Money;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated customer/supplier/employee statement sourced from the posted
 * general ledger (Prompt 28).
 *
 * The statement reads the party's control-account family plus its genuine
 * subaccounts and keeps the ledger as the only balance truth. Operational
 * invoice/payment figures are shown next to the ledger for reconciliation and
 * any difference is surfaced explicitly rather than hidden.
 */
class PartyStatementService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly ReportReferences $references,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statement(
        string $partyType,
        int $partyId,
        string $from,
        string $to,
        ?Branch $branch,
        int $page = 1,
        int $perPage = 200,
        ?string $onlyCurrency = null,
    ): array {
        $party = $this->party($partyType, $partyId, $branch);
        $currency = $onlyCurrency ?? $this->settings->baseCurrency();
        $familyIds = $this->controlFamilyIds($partyType);
        $subaccountIds = $this->subaccountIds($partyType, $partyId);
        [$debitColumn, $creditColumn] = $onlyCurrency
            ? ['jl.debit_minor', 'jl.credit_minor']
            : ['jl.base_debit_minor', 'jl.base_credit_minor'];

        $scoped = fn () => DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $familyIds)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->when($onlyCurrency, fn ($query) => $query->where('jl.currency_code', $onlyCurrency))
            ->where(function ($query) use ($partyType, $partyId, $subaccountIds) {
                $query->where(function ($query) use ($partyType, $partyId) {
                    $query->where('jl.party_type', $partyType)->where('jl.party_id', $partyId);
                });

                if ($subaccountIds !== []) {
                    $query->orWhereIn('jl.account_id', $subaccountIds);
                }
            });

        $opening = $scoped()
            ->whereDate('je.entry_date', '<', $from)
            ->selectRaw("COALESCE(SUM($debitColumn), 0) as debit, COALESCE(SUM($creditColumn), 0) as credit")
            ->first();
        $openingMinor = (int) $opening->debit - (int) $opening->credit;

        $period = $scoped()
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to);

        $totals = (clone $period)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM($debitColumn), 0) as debit, COALESCE(SUM($creditColumn), 0) as credit")
            ->first();

        $running = (clone $period)
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->selectRaw('jl.id as line_id, je.public_id as entry_id, je.number, je.entry_date, je.memo, je.source_type, je.source_id, je.event')
            ->selectRaw('a.code as account_code, jl.description, jl.currency_code, jl.debit_minor, jl.credit_minor')
            ->selectRaw('jl.base_debit_minor, jl.base_credit_minor, jl.party_type, jl.party_id')
            ->selectRaw("SUM($debitColumn - $creditColumn) OVER (ORDER BY je.entry_date, je.id, jl.id) as running_minor");

        $lines = DB::query()
            ->fromSub($running, 't')
            ->orderBy('entry_date')
            ->orderBy('line_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn ($row) => [
                'entry_id' => $row->entry_id,
                'number' => $row->number,
                'entry_date' => substr((string) $row->entry_date, 0, 10),
                'memo' => $row->memo,
                'description' => $row->description,
                'account_code' => $row->account_code,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id !== null ? (int) $row->source_id : null,
                'event' => $row->event,
                'party_type' => $row->party_type,
                'party_id' => $row->party_id !== null ? (int) $row->party_id : null,
                'currency' => $row->currency_code,
                'debit_minor' => (int) $row->debit_minor,
                'credit_minor' => (int) $row->credit_minor,
                'base_debit_minor' => (int) $row->base_debit_minor,
                'base_credit_minor' => (int) $row->base_credit_minor,
                'balance_minor' => $openingMinor + (int) $row->running_minor,
            ])
            ->all();

        $lines = $this->references->enrich($lines);

        $debit = (int) $totals->debit;
        $credit = (int) $totals->credit;
        $closingMinor = $openingMinor + $debit - $credit;

        $subaccountClosing = $subaccountIds === []
            ? 0
            : (int) $this->balanceAt($familyIds, $subaccountIds, $partyType, $partyId, $to, $branch, $onlyCurrency);
        $taggedControlClosing = $closingMinor - $subaccountClosing;

        $normalizedLedger = $partyType === 'supplier' ? -$closingMinor : $closingMinor;
        $operational = $this->operationalBalance($partyType, $partyId, $to, $branch);
        $unapplied = $this->unappliedCredits($partyType, $partyId, $to, $branch);
        $difference = $normalizedLedger - $operational;

        $differences = [];
        if ($difference < 0) {
            $differences[] = [
                'code' => 'credits_not_applied_to_invoices',
                'label_en' => 'Payments/credits posted to the ledger are not reflected in invoice open balances',
                'label_ar' => 'مدفوعات أو أرصدة دائنة في دفتر الأستاذ غير ظاهرة في أرصدة الفواتير',
                'amount_minor' => -$difference,
            ];
        } elseif ($difference > 0) {
            $differences[] = [
                'code' => 'charges_not_reflected_in_invoices',
                'label_en' => 'Ledger charges have no matching open invoice',
                'label_ar' => 'حركات مدينة في دفتر الأستاذ بدون فاتورة مفتوحة مقابلة',
                'amount_minor' => $difference,
            ];
        }

        return [
            'party_type' => $partyType,
            'party' => [
                'id' => $party->public_id,
                'name_ar' => $party->name_ar,
                'name_en' => $party->name_en,
            ],
            'currency' => $currency,
            'basis' => $onlyCurrency ? 'original' : 'base',
            'from' => $from,
            'to' => $to,
            'control_account' => $this->controlAccount($partyType)->toApiArray(),
            'subaccounts' => PartyAccount::query()
                ->where('party_type', $partyType)
                ->where('party_id', $partyId)
                ->with('account')
                ->get()
                ->map(fn (PartyAccount $mapping) => $mapping->toApiArray())
                ->all(),
            'opening_minor' => $openingMinor,
            'total_debit_minor' => $debit,
            'total_credit_minor' => $credit,
            'closing_minor' => $closingMinor,
            'subaccount_closing_minor' => $subaccountClosing,
            'tagged_control_closing_minor' => $taggedControlClosing,
            'reconciliation' => [
                'ledger_closing_minor' => $closingMinor,
                'normalized_ledger_minor' => $normalizedLedger,
                'operational_open_invoices_minor' => $operational,
                'unapplied_credits_minor' => $unapplied,
                'difference_minor' => $difference,
                'reconciled' => $difference === 0,
                'differences' => $differences,
            ],
            'lines' => $lines,
            'total' => (int) $totals->total,
        ];
    }

    private function party(string $partyType, int $partyId, ?Branch $branch): Customer|Supplier|Employee
    {
        $party = match ($partyType) {
            'customer' => Customer::query()->find($partyId),
            'supplier' => Supplier::query()->find($partyId),
            'employee' => Employee::query()->find($partyId),
            default => throw new DomainException("Unsupported party type: {$partyType}"),
        };

        if (! $party) {
            throw new DomainException('Party not found.');
        }

        if ($branch && isset($party->branch_id) && (int) $party->branch_id !== $branch->id) {
            throw new AuthorizationException('The party belongs to another branch.');
        }

        return $party;
    }

    private function controlAccount(string $partyType): Account
    {
        $code = match ($partyType) {
            'customer' => (string) $this->settings->get('accounting', 'ar_account_code', '1100'),
            'supplier' => (string) $this->settings->get('accounting', 'ap_account_code', '2100'),
            'employee' => (string) $this->settings->get('accounting', 'employee_advance_account_code', '1151'),
            default => throw new DomainException("Unsupported party type: {$partyType}"),
        };

        return Account::query()->where('code', $code)->firstOrFail();
    }

    /** @return int[] control account plus descendants, including both employee control accounts */
    private function controlFamilyIds(string $partyType): array
    {
        $codes = match ($partyType) {
            'employee' => array_values(array_unique([
                (string) $this->settings->get('accounting', 'employee_advance_account_code', '1151'),
                (string) $this->settings->get('accounting', 'employee_custody_account_code', '1152'),
            ])),
            default => [$this->controlAccount($partyType)->code],
        };

        $ids = [];
        foreach ($codes as $code) {
            $account = Account::query()->where('code', $code)->first();
            if ($account) {
                $ids = array_merge($ids, $this->descendantIds($account));
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return int[] */
    private function subaccountIds(string $partyType, int $partyId): array
    {
        return PartyAccount::query()
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return int[] */
    private function descendantIds(Account $account): array
    {
        $ids = [$account->id];
        $frontier = [$account->id];

        while ($frontier !== []) {
            $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @param int[] $familyIds @param int[] $accountIds */
    private function balanceAt(
        array $familyIds,
        array $accountIds,
        string $partyType,
        int $partyId,
        string $asOf,
        ?Branch $branch,
        ?string $onlyCurrency,
    ): int {
        [$debitColumn, $creditColumn] = $onlyCurrency
            ? ['jl.debit_minor', 'jl.credit_minor']
            : ['jl.base_debit_minor', 'jl.base_credit_minor'];

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', array_values(array_intersect($familyIds, $accountIds)))
            ->whereDate('je.entry_date', '<=', $asOf)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->when($onlyCurrency, fn ($query) => $query->where('jl.currency_code', $onlyCurrency))
            ->where('jl.party_type', $partyType)
            ->where('jl.party_id', $partyId)
            ->selectRaw("COALESCE(SUM($debitColumn), 0) as debit, COALESCE(SUM($creditColumn), 0) as credit")
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    private function operationalBalance(string $partyType, int $partyId, string $asOf, ?Branch $branch): int
    {
        if ($partyType === 'employee') {
            return (int) DB::table('employee_transactions')
                ->where('employee_id', $partyId)
                ->whereDate('transaction_date', '<=', $asOf)
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->sum('amount_minor');
        }

        $currency = $this->settings->baseCurrency();
        $total = 0;

        $invoices = Invoice::query()
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->where('status', Invoice::STATUS_ISSUED)
            ->whereDate('issue_date', '<=', $asOf)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->get();

        foreach ($invoices as $invoice) {
            $amount = $invoice->type === Invoice::TYPE_CREDIT_NOTE
                ? -(int) $invoice->outstanding_minor
                : (int) $invoice->outstanding_minor;

            $total += (new Money($amount, $invoice->currency_code))
                ->convertTo($currency, (string) $invoice->fx_rate)->amountMinor;
        }

        return $total;
    }

    private function unappliedCredits(string $partyType, int $partyId, string $asOf, ?Branch $branch): int
    {
        $currency = $this->settings->baseCurrency();
        $total = 0;

        if ($partyType === 'customer') {
            $payments = Payment::query()
                ->where('customer_id', $partyId)
                ->whereDate('paid_at', '<=', $asOf)
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->get();

            foreach ($payments as $payment) {
                $total += (new Money((int) $payment->unallocated_minor, $payment->currency_code))
                    ->convertTo($currency, (string) $payment->fx_rate)->amountMinor;
            }
        }

        if ($partyType === 'supplier') {
            $payments = SupplierPayment::query()
                ->where('supplier_id', $partyId)
                ->whereDate('paid_at', '<=', $asOf)
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->get();

            foreach ($payments as $payment) {
                $total += (new Money($payment->unallocatedMinor(), $payment->currency_code))
                    ->convertTo($currency, (string) $payment->fx_rate)->amountMinor;
            }
        }

        return $total;
    }
}
