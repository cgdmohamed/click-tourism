<?php

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\RoundingPolicy;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Services\TreasuryService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only financial reporting. Reports aggregate stored base-currency
 * amounts and never re-convert history (ADR-0003 §7, ADR-0004 §8).
 */
class ReportService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly RoundingPolicy $rounding,
        private readonly FxService $fx,
        private readonly ReportReferences $references,
        private readonly TreasuryService $treasury,
    ) {}

    /**
     * Trial balance with opening, period movement and closing balances.
     *
     * Without $onlyCurrency the report sums stored base-currency amounts.
     * With it, only lines posted in that transaction currency are included
     * and their original-currency amounts are summed (no re-conversion).
     *
     * $maxLevel rolls each posting up to its ancestor at that chart-of-accounts
     * depth (root = level 1) so parents and children are never double counted.
     * $costCenterId limits the report to the allocated analytical portion of
     * each line. $adjustments selects include|exclude|only period-end
     * adjustment vouchers so a before/after-adjustment view is possible.
     */
    public function trialBalance(
        string $from,
        string $to,
        ?Branch $branch,
        ?string $onlyCurrency = null,
        bool $preClosing = false,
        ?int $maxLevel = null,
        ?int $costCenterId = null,
        string $adjustments = 'include',
    ): array {
        $currency = $onlyCurrency ?? $this->settings->baseCurrency();
        [$debitColumn, $creditColumn] = $this->amountColumns($onlyCurrency);

        $accounts = Account::query()->get(['id', 'public_id', 'code', 'name_ar', 'name_en', 'type', 'parent_id']);
        $byId = $accounts->keyBy('id');
        $levels = $this->accountLevels($byId);
        $rollup = $this->rollupMap($byId, $levels, $maxLevel);

        $opening = $this->trialBalanceAmounts(
            $from, $to, true, $branch, $onlyCurrency, $preClosing, $costCenterId, $adjustments, $debitColumn, $creditColumn,
        );
        $movements = $this->trialBalanceAmounts(
            $from, $to, false, $branch, $onlyCurrency, $preClosing, $costCenterId, $adjustments, $debitColumn, $creditColumn,
        );

        $aggregated = [];
        foreach ([$opening, $movements] as $source => $bucket) {
            foreach ($bucket as $accountId => $amounts) {
                $target = $rollup[(int) $accountId] ?? (int) $accountId;
                $aggregated[$target] ??= ['opening_debit' => 0, 'opening_credit' => 0, 'debit' => 0, 'credit' => 0];
                $key = $source === 0 ? 'opening_' : '';
                $aggregated[$target][$key.'debit'] += $amounts['debit'];
                $aggregated[$target][$key.'credit'] += $amounts['credit'];
            }
        }

        $rows = [];
        foreach ($aggregated as $accountId => $amounts) {
            $account = $byId->get($accountId);
            if (! $account) {
                continue;
            }

            $openingNet = $amounts['opening_debit'] - $amounts['opening_credit'];
            $closingNet = $openingNet + $amounts['debit'] - $amounts['credit'];

            if ($openingNet === 0 && $amounts['debit'] === 0 && $amounts['credit'] === 0) {
                continue;
            }

            // Abnormal = closing balance sits on the opposite side of the
            // account's normal side (assets/expenses debit; others credit).
            $debitNormal = in_array($account->type, ['asset', 'expense'], true);
            $abnormal = $debitNormal ? $closingNet < 0 : $closingNet > 0;

            $rows[] = (object) [
                'account_id' => $account->public_id,
                'public_id' => $account->public_id,
                'code' => $account->code,
                'name_ar' => $account->name_ar,
                'name_en' => $account->name_en,
                'type' => $account->type,
                'level' => $levels[$accountId] ?? 1,
                'opening_debit_minor' => max($openingNet, 0),
                'opening_credit_minor' => max(-$openingNet, 0),
                'opening_balance_minor' => $openingNet,
                'debit_minor' => $amounts['debit'],
                'credit_minor' => $amounts['credit'],
                'closing_debit_minor' => max($closingNet, 0),
                'closing_credit_minor' => max(-$closingNet, 0),
                'closing_balance_minor' => $closingNet,
                'balance_minor' => $closingNet,
                'abnormal' => $abnormal,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a->code, $b->code));

        $totalDebit = array_sum(array_column($rows, 'debit_minor'));
        $totalCredit = array_sum(array_column($rows, 'credit_minor'));

        return [
            'currency' => $currency,
            'basis' => $onlyCurrency ? 'original' : 'base',
            'from' => $from,
            'to' => $to,
            'max_level' => $maxLevel,
            'cost_center_id' => $costCenterId,
            'adjustments' => $adjustments,
            'rows' => $rows,
            'total_debit_minor' => $totalDebit,
            'total_credit_minor' => $totalCredit,
            'total_opening_debit_minor' => array_sum(array_column($rows, 'opening_debit_minor')),
            'total_opening_credit_minor' => array_sum(array_column($rows, 'opening_credit_minor')),
            'total_closing_debit_minor' => array_sum(array_column($rows, 'closing_debit_minor')),
            'total_closing_credit_minor' => array_sum(array_column($rows, 'closing_credit_minor')),
            // When cost-center filtering is active, only the allocated analytical
            // portion of each line is shown. If only one side of a journal entry
            // is allocated to the center, debits and credits will not match.
            // Consumers should not interpret this as a ledger imbalance.
            'balanced' => $totalDebit === $totalCredit,
        ];
    }

    /**
     * @return array<int, array{debit: int, credit: int}> account id => amounts
     */
    private function trialBalanceAmounts(
        string $from,
        string $to,
        bool $opening,
        ?Branch $branch,
        ?string $onlyCurrency,
        bool $preClosing,
        ?int $costCenterId,
        string $adjustments,
        string $debitColumn,
        string $creditColumn,
    ): array {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->when($onlyCurrency, fn ($query) => $query->where('jl.currency_code', $onlyCurrency))
            ->when($preClosing, fn ($query) => $this->excludeClosing($query));

        $this->applyAdjustmentsScope($query, $adjustments);

        if ($opening) {
            $query->whereDate('je.entry_date', '<', $from);
        } else {
            $query->whereDate('je.entry_date', '>=', $from)
                ->whereDate('je.entry_date', '<=', $to);
        }

        if ($costCenterId !== null) {
            $query->join('cost_center_allocations as cca', 'cca.journal_line_id', '=', 'jl.id')
                ->where('cca.cost_center_id', $costCenterId);

            if ($onlyCurrency) {
                $query->selectRaw('jl.account_id as account_id')
                    ->selectRaw('COALESCE(SUM(CASE WHEN jl.debit_minor <> 0 THEN cca.amount_minor ELSE 0 END), 0) as debit')
                    ->selectRaw('COALESCE(SUM(CASE WHEN jl.credit_minor <> 0 THEN cca.amount_minor ELSE 0 END), 0) as credit');
            } else {
                $ratio = 'CASE WHEN (jl.debit_minor + jl.credit_minor) = 0 THEN 0 ELSE (1.0 * cca.amount_minor / (jl.debit_minor + jl.credit_minor)) END';
                $query->selectRaw('jl.account_id as account_id')
                    ->selectRaw("COALESCE(SUM(ROUND(jl.base_debit_minor * $ratio)), 0) as debit")
                    ->selectRaw("COALESCE(SUM(ROUND(jl.base_credit_minor * $ratio)), 0) as credit");
            }

            $query->groupBy('jl.account_id');
        } else {
            $query->selectRaw('jl.account_id as account_id')
                ->selectRaw("COALESCE(SUM($debitColumn), 0) as debit")
                ->selectRaw("COALESCE(SUM($creditColumn), 0) as credit")
                ->groupBy('jl.account_id');
        }

        return $query->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->account_id => ['debit' => (int) round((float) $row->debit), 'credit' => (int) round((float) $row->credit)],
            ])
            ->all();
    }

    /** @param Collection<int, Account> $accounts */
    private function accountLevels($accounts): array
    {
        $levels = [];

        $resolve = function (int $id) use (&$resolve, &$levels, $accounts): int {
            if (isset($levels[$id])) {
                return $levels[$id];
            }

            $account = $accounts->get($id);
            if (! $account || ! $account->parent_id || ! $accounts->has($account->parent_id)) {
                return $levels[$id] = 1;
            }

            return $levels[$id] = 1 + $resolve((int) $account->parent_id);
        };

        foreach ($accounts as $account) {
            $resolve((int) $account->id);
        }

        return $levels;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $levels
     * @return array<int, int> account id => rollup target account id
     */
    private function rollupMap($accounts, array $levels, ?int $maxLevel): array
    {
        $map = [];

        foreach ($accounts as $account) {
            $target = $account;
            if ($maxLevel !== null) {
                while (($levels[$target->id] ?? 1) > $maxLevel && $target->parent_id && $accounts->has($target->parent_id)) {
                    $target = $accounts->get($target->parent_id);
                }
            }
            $map[(int) $account->id] = (int) $target->id;
        }

        return $map;
    }

    private function applyAdjustmentsScope($query, string $mode): void
    {
        if ($mode === 'include') {
            return;
        }

        $adjustmentIds = DB::table('accounting_documents')
            ->where('type', 'adjustment_voucher')
            ->select('id');

        if ($mode === 'only') {
            $query->where('je.source_type', 'accounting_document')
                ->whereIn('je.source_id', $adjustmentIds);

            return;
        }

        // exclude: keep everything that is not a posted adjustment voucher.
        $query->where(function ($query) use ($adjustmentIds) {
            $query->whereNull('je.source_type')
                ->orWhere('je.source_type', '!=', 'accounting_document')
                ->orWhereNotIn('je.source_id', $adjustmentIds);
        });
    }

    /**
     * General ledger for one account (including its descendants): opening
     * balance, paginated movements with a running balance, and closing balance.
     * Balances are the stored base-currency values (debit minus credit). With
     * $onlyCurrency only lines posted in that transaction currency are shown
     * and balances use their original-currency amounts. $party optionally
     * limits lines to one customer or supplier: ['type' => string, 'id' => int].
     *
     * @param  array{type: string, id: int}|null  $party
     * @return array{summary: array<string, mixed>, lines: array<int, array<string, mixed>>, total: int}
     */
    public function generalLedger(
        Account $account,
        string $from,
        string $to,
        ?Branch $branch,
        int $page,
        int $perPage,
        ?string $onlyCurrency = null,
        ?array $party = null,
    ): array {
        $accountIds = $this->descendantIds($account);
        [$debitColumn, $creditColumn] = $this->amountColumns($onlyCurrency);

        $scoped = fn () => DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $accountIds)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->when($onlyCurrency, fn ($query) => $query->where('jl.currency_code', $onlyCurrency))
            ->when($party, fn ($query) => $query
                ->where('jl.party_type', $party['type'])
                ->where('jl.party_id', $party['id']));

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

        return [
            'summary' => [
                'currency' => $onlyCurrency ?? $this->settings->baseCurrency(),
                'basis' => $onlyCurrency ? 'original' : 'base',
                'account' => $account->toApiArray(),
                'from' => $from,
                'to' => $to,
                'opening_minor' => $openingMinor,
                'total_debit_minor' => $debit,
                'total_credit_minor' => $credit,
                'closing_minor' => $openingMinor + $debit - $credit,
            ],
            'lines' => $lines,
            'total' => (int) $totals->total,
        ];
    }

    /** @return array{0: string, 1: string} debit and credit column names for the chosen basis */
    private function amountColumns(?string $onlyCurrency): array
    {
        return $onlyCurrency
            ? ['jl.debit_minor', 'jl.credit_minor']
            : ['jl.base_debit_minor', 'jl.base_credit_minor'];
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

    public function incomeStatement(string $from, string $to, ?Branch $branch, ?string $compareFrom = null, ?string $compareTo = null): array
    {
        $current = $this->incomeStatementData($from, $to, $branch);

        if ($compareFrom !== null && $compareTo !== null) {
            $prior = $this->incomeStatementData($compareFrom, $compareTo, $branch);
            $current['comparison'] = $prior;
            $current['variance'] = [
                'total_revenue_minor' => $current['total_revenue_minor'] - $prior['total_revenue_minor'],
                'total_expense_minor' => $current['total_expense_minor'] - $prior['total_expense_minor'],
                'total_cost_of_services_minor' => $current['total_cost_of_services_minor'] - $prior['total_cost_of_services_minor'],
                'total_operating_expenses_minor' => $current['total_operating_expenses_minor'] - $prior['total_operating_expenses_minor'],
                'gross_profit_minor' => $current['gross_profit_minor'] - $prior['gross_profit_minor'],
                'operating_profit_minor' => $current['operating_profit_minor'] - $prior['operating_profit_minor'],
                'net_profit_minor' => $current['net_profit_minor'] - $prior['net_profit_minor'],
            ];
        } else {
            $current['comparison'] = null;
            $current['variance'] = null;
        }

        return $current;
    }

    private function incomeStatementData(string $from, string $to, ?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->tap(fn ($query) => $this->excludeClosing($query))
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type')
            ->selectRaw('a.id, a.code, a.name_ar, a.name_en, a.type')
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit_minor')
            ->selectRaw('COALESCE(SUM(jl.base_credit_minor), 0) as credit_minor')
            ->orderBy('a.code')
            ->get();

        $costIds = $this->costOfServicesAccountIds();
        $costCode = $this->costAccountCode();

        $revenue = [];
        $expenses = [];
        $costOfServices = [];
        $operatingExpenses = [];

        foreach ($rows as $row) {
            $debit = (int) $row->debit_minor;
            $credit = (int) $row->credit_minor;

            if ($row->type === 'revenue') {
                $amount = $credit - $debit;
                if ($amount !== 0) {
                    $revenue[] = $this->line($row, $amount);
                }
            } else {
                $amount = $debit - $credit;
                if ($amount !== 0) {
                    $line = $this->line($row, $amount);
                    $expenses[] = $line;
                    if (isset($costIds[(int) $row->id]) || str_starts_with((string) $row->code, $costCode)) {
                        $costOfServices[] = $line;
                    } else {
                        $operatingExpenses[] = $line;
                    }
                }
            }
        }

        $totalRevenue = array_sum(array_column($revenue, 'amount_minor'));
        $totalExpense = array_sum(array_column($expenses, 'amount_minor'));
        $totalCost = array_sum(array_column($costOfServices, 'amount_minor'));
        $totalOpex = array_sum(array_column($operatingExpenses, 'amount_minor'));
        $grossProfit = $totalRevenue - $totalCost;

        return [
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expenses' => $expenses,
            // Cost of services = the configured accounting.cost_account_code
            // account, its descendants and any code starting with it.
            'cost_of_services' => $costOfServices,
            'operating_expenses' => $operatingExpenses,
            'total_revenue_minor' => $totalRevenue,
            'total_expense_minor' => $totalExpense,
            'total_cost_of_services_minor' => $totalCost,
            'total_operating_expenses_minor' => $totalOpex,
            'gross_profit_minor' => $grossProfit,
            'operating_profit_minor' => $grossProfit - $totalOpex,
            'net_profit_minor' => $totalRevenue - $totalExpense,
        ];
    }

    public function balanceSheet(string $asOf, ?Branch $branch, ?string $compareAsOf = null): array
    {
        $current = $this->balanceSheetData($asOf, $branch);

        if ($compareAsOf !== null) {
            $prior = $this->balanceSheetData($compareAsOf, $branch);
            $current['comparison'] = $prior;
            $current['variance'] = [
                'total_assets_minor' => $current['total_assets_minor'] - $prior['total_assets_minor'],
                'total_liabilities_minor' => $current['total_liabilities_minor'] - $prior['total_liabilities_minor'],
                'total_equity_minor' => $current['total_equity_minor'] - $prior['total_equity_minor'],
                'current_assets_minor' => $current['current_assets_minor'] - $prior['current_assets_minor'],
                'non_current_assets_minor' => $current['non_current_assets_minor'] - $prior['non_current_assets_minor'],
                'current_liabilities_minor' => $current['current_liabilities_minor'] - $prior['current_liabilities_minor'],
                'non_current_liabilities_minor' => $current['non_current_liabilities_minor'] - $prior['non_current_liabilities_minor'],
                'current_earnings_minor' => $current['current_earnings_minor'] - $prior['current_earnings_minor'],
            ];
        } else {
            $current['comparison'] = null;
            $current['variance'] = null;
        }

        return $current;
    }

    private function balanceSheetData(string $asOf, ?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('a.type', ['asset', 'liability', 'equity'])
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type', 'a.classification')
            ->selectRaw('a.code, a.name_ar, a.name_en, a.type, a.classification')
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit_minor')
            ->selectRaw('COALESCE(SUM(jl.base_credit_minor), 0) as credit_minor')
            ->orderBy('a.code')
            ->get();

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($rows as $row) {
            $debit = (int) $row->debit_minor;
            $credit = (int) $row->credit_minor;
            $amount = $row->type === 'asset' ? $debit - $credit : $credit - $debit;

            if ($amount === 0) {
                continue;
            }

            match ($row->type) {
                'asset' => $assets[] = $this->line($row, $amount) + ['classification' => $row->classification],
                'liability' => $liabilities[] = $this->line($row, $amount) + ['classification' => $row->classification],
                'equity' => $equity[] = $this->line($row, $amount) + ['classification' => $row->classification],
            };
        }

        // Profit and loss not yet rolled into equity by a year-end close.
        $earnings = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('a.type', ['revenue', 'expense'])
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(jl.base_credit_minor), 0) - COALESCE(SUM(jl.base_debit_minor), 0) as net')
            ->value('net');

        $totalAssets = array_sum(array_column($assets, 'amount_minor'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount_minor'));
        $totalEquity = array_sum(array_column($equity, 'amount_minor'));

        // Grouping follows each account's explicit `classification`. An asset or
        // liability without a valid one falls back to the legacy code-prefix rule
        // and is listed in `unclassified_accounts` so the accountant can fix it.
        $unclassified = [];
        $assets = array_map(function ($l) use (&$unclassified) {
            return $this->withEffectiveClassification($l, 'asset', $unclassified);
        }, $assets);
        $liabilities = array_map(function ($l) use (&$unclassified) {
            return $this->withEffectiveClassification($l, 'liability', $unclassified);
        }, $liabilities);

        // Odoo-style presentation: prepayments are current, fixed assets are
        // non-current. The finer value stays on each line (`classification`), and
        // the subtotals below are informational parts of the two totals, so
        // current + non-current always equals the total.
        $isCurrentAsset = fn ($l) => in_array($l['classification'], ['current_asset', 'prepayment'], true);
        $currentAssets = array_values(array_filter($assets, $isCurrentAsset));
        $nonCurrentAssets = array_values(array_filter($assets, fn ($l) => ! $isCurrentAsset($l)));
        $currentLiabilities = array_values(array_filter($liabilities, fn ($l) => $l['classification'] === 'current_liability'));
        $nonCurrentLiabilities = array_values(array_filter($liabilities, fn ($l) => $l['classification'] !== 'current_liability'));
        $sumOf = fn (array $lines, string $classification) => array_sum(array_column(
            array_filter($lines, fn ($l) => $l['classification'] === $classification),
            'amount_minor',
        ));

        return [
            'currency' => $currency,
            'as_of' => $asOf,
            'classification' => $unclassified === [] ? 'by_account' : 'mixed',
            'unclassified_accounts' => array_values(array_unique($unclassified)),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'current_assets' => $currentAssets,
            'non_current_assets' => $nonCurrentAssets,
            'current_liabilities' => $currentLiabilities,
            'non_current_liabilities' => $nonCurrentLiabilities,
            'current_assets_minor' => array_sum(array_column($currentAssets, 'amount_minor')),
            'non_current_assets_minor' => array_sum(array_column($nonCurrentAssets, 'amount_minor')),
            'current_liabilities_minor' => array_sum(array_column($currentLiabilities, 'amount_minor')),
            'non_current_liabilities_minor' => array_sum(array_column($nonCurrentLiabilities, 'amount_minor')),
            'prepayments_minor' => $sumOf($assets, 'prepayment'),
            'fixed_assets_minor' => $sumOf($assets, 'fixed_asset'),
            'equity' => $equity,
            'current_earnings_minor' => (int) $earnings,
            'total_assets_minor' => $totalAssets,
            'total_liabilities_minor' => $totalLiabilities,
            'total_equity_minor' => $totalEquity,
            'total_liabilities_and_equity_minor' => $totalLiabilities + $totalEquity + (int) $earnings,
            'balanced' => $totalAssets === $totalLiabilities + $totalEquity + (int) $earnings,
        ];
    }

    /**
     * Resolve the balance-sheet class of a line: the account's own valid
     * `classification`, else the legacy code-prefix rule (recorded in $unclassified).
     *
     * @param  array<string, mixed>  $line
     * @param  list<string>  $unclassified
     * @return array<string, mixed>
     */
    private function withEffectiveClassification(array $line, string $type, array &$unclassified): array
    {
        if (! in_array($line['classification'] ?? null, Account::classificationsFor($type), true)) {
            $unclassified[] = $line['code'];
            $line['classification'] = Account::defaultClassification($type, (string) $line['code']);
        }

        return $line;
    }

    private function costAccountCode(): string
    {
        return (string) $this->settings->get('accounting', 'cost_account_code', '5100');
    }

    /** @return array<int, true> ids of the cost account and its descendants */
    private function costOfServicesAccountIds(): array
    {
        $account = Account::query()->where('code', $this->costAccountCode())->first();

        return $account ? array_fill_keys($this->descendantIds($account), true) : [];
    }

    /** Hide year-end closing entries (and their reversals) so a closed year still shows its profit. */
    private function excludeClosing($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('je.source_type')->orWhere('je.source_type', '!=', 'fiscal_year');
        });
    }

    /**
     * Invoice-based AR/AP aging. Customers ('ar') are issued customer invoices
     * with an open balance, suppliers ('ap') issued supplier invoices; issued
     * credit notes with remaining outstanding reduce their own bucket. Age is
     * measured from the due date (issue date when none) to $asOf; not-yet-due
     * items fall in 0-30. Operational supplier obligations that have no
     * invoice are off-ledger and deliberately excluded. Shares its definition
     * with AgingReconciliationService via openDocuments().
     */
    public function aging(string $type, string $asOf, ?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();
        $buckets = [
            '0-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '90+' => 0,
        ];

        foreach (self::openDocuments($type === 'ar' ? 'customer' : 'supplier', $asOf, $branch) as $invoice) {
            $amount = $invoice->type === Invoice::TYPE_CREDIT_NOTE
                ? -(int) $invoice->outstanding_minor
                : (int) $invoice->outstanding_minor;

            $base = (new Money($amount, $invoice->currency_code))
                ->convertTo($currency, (string) $invoice->fx_rate, $this->rounding->mode())->amountMinor;

            $buckets[self::bucketFor(self::ageInDays($invoice, $asOf))] += $base;
        }

        $labels = [
            '0-30' => app()->getLocale() === 'ar' ? '0-30 يوم' : '0-30 days',
            '31-60' => app()->getLocale() === 'ar' ? '31-60 يوم' : '31-60 days',
            '61-90' => app()->getLocale() === 'ar' ? '61-90 يوم' : '61-90 days',
            '90+' => app()->getLocale() === 'ar' ? 'أكثر من 90 يوم' : '90+ days',
        ];

        $result = [];
        foreach ($buckets as $key => $amount) {
            $result[] = ['label' => $labels[$key], 'amount_minor' => $amount];
        }

        return [
            'currency' => $currency,
            'as_of' => $asOf,
            'type' => $type,
            'buckets' => $result,
            'total_minor' => array_sum($buckets),
        ];
    }

    public function dashboard(?Branch $branch, array $filters = []): array
    {
        $currency = $this->settings->baseCurrency();
        $today = now()->toDateString();
        $period = $filters['period'] ?? 'thisMonth';
        [$monthStart, $monthEnd] = match ($period) {
            'today' => [$today, $today],
            'lastMonth' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'customRange' => [$filters['from'], $filters['to']],
            default => [now()->startOfMonth()->toDateString(), $today],
        };
        $monthEnd = min($monthEnd, $today);

        $bookingScope = fn () => Booking::query()->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $customers = Customer::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->where('is_active', true)
            ->count();
        $bookings = $bookingScope()->count();
        $invoices = Invoice::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->where('status', '!=', Invoice::STATUS_VOIDED)
            ->count();
        // A ticket is one sold flight unit. Cancelled/no-show bookings do not
        // contribute to the operational count shown on the dashboard.
        $tickets = (int) DB::table('booking_items as bi')
            ->join('bookings as b', 'b.id', '=', 'bi.booking_id')
            ->where('bi.service_type', 'flight')
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->when($branch, fn ($query) => $query->where('b.branch_id', $branch->id))
            ->sum('bi.quantity');

        $open = $bookingScope()->whereIn('status', ['draft', 'quoted', 'confirmed', 'in_progress'])->count();
        $confirmed = $bookingScope()->whereIn('status', ['confirmed', 'in_progress'])->count();

        $collected = $this->netReceiptsMinor($branch, $currency, $monthStart, $monthEnd);

        $overdue = 0;
        $bookingScope()
            ->withSum('allocations', 'amount_minor')
            ->whereNotIn('status', ['cancelled', 'closed', 'no_show'])
            ->where('start_date', '<', $today)
            ->get()
            ->each(function (Booking $booking) use (&$overdue, $currency) {
                $balance = $booking->total_sell_minor - (int) ($booking->allocations_sum_amount_minor ?? 0);
                if ($balance > 0) {
                    $overdue += (new Money($balance, $booking->currency_code))
                        ->convertTo($currency, (string) $booking->fx_rate, $this->rounding->mode())->amountMinor;
                }
            });

        $recentBookings = $bookingScope()
            ->with('customer')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Booking $booking) => $booking->toApiArray())
            ->all();

        $recentPayments = Payment::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Payment $payment) => $payment->toApiArray())
            ->all();

        // Use base currency amounts from posted GL; never sum foreign cash at
        // an artificial FX rate. Account figures retain native currencies.
        $cashAccounts = CashAccount::query()
            ->when($branch, fn ($q) => $q->where(function ($sub) use ($branch) {
                $sub->where('branch_id', $branch->id)->orWhereNull('branch_id');
            }))
            ->where('is_active', true)
            ->with('account')
            ->get()
            ->map(fn (CashAccount $ca) => [
                'id' => $ca->public_id,
                'name_ar' => $ca->name_ar,
                'name_en' => $ca->name_en,
                'kind' => $ca->kind,
                'currency' => $ca->currency_code,
                'balance_minor' => $this->treasury->nativeBalance($ca, $branch),
                'base_balance_minor' => $this->treasury->balance($ca, $branch),
            ]);

        $hasForeignCash = $cashAccounts->contains(fn ($ca) => strtoupper($ca['currency']) !== $currency);
        $cashBalanceMinor = $hasForeignCash ? null : (int) $cashAccounts->sum('base_balance_minor');

        $invoiceScope = fn ($type) => Invoice::query()
            ->where('type', $type)
            ->where('status', Invoice::STATUS_ISSUED)
            ->where('outstanding_minor', '>', 0)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id));

        $convertInvoice = fn (Invoice $invoice) => (new Money(
            $invoice->outstanding_minor, $invoice->currency_code,
        ))->convertTo($currency, (string) $invoice->fx_rate, $this->rounding->mode())->amountMinor;

        $arOutstandingMinor = $invoiceScope(Invoice::TYPE_CUSTOMER_INVOICE)->get()->sum($convertInvoice);
        $apOutstandingMinor = $invoiceScope(Invoice::TYPE_SUPPLIER_INVOICE)->get()->sum($convertInvoice);
        $overdueArMinor = $invoiceScope(Invoice::TYPE_CUSTOMER_INVOICE)
            ->whereDate('due_date', '<', $today)->get()->sum($convertInvoice);
        $overdueApMinor = $invoiceScope(Invoice::TYPE_SUPPLIER_INVOICE)
            ->whereDate('due_date', '<', $today)->get()->sum($convertInvoice);

        $customerAdvancesMinor = Payment::query()
            ->where('status', 'posted')->where('unallocated_minor', '>', 0)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->get()->sum(fn (Payment $p) => (new Money($p->unallocated_minor, $p->currency_code))
            ->convertTo($currency, (string) $p->fx_rate, $this->rounding->mode())->amountMinor);

        $unreconciledBankCount = DB::table('bank_statement_lines as bsl')
            ->leftJoin('bank_reconciliation_matches as brm', 'brm.bank_statement_line_id', '=', 'bsl.id')
            ->join('bank_statements as bs', 'bs.id', '=', 'bsl.bank_statement_id')
            ->whereNull('brm.id')
            ->when($branch, fn ($q) => $q->where('bs.branch_id', $branch->id))
            ->count();

        $netProfitMinor = $this->netProfitMinor($monthStart, $monthEnd, $branch, $currency);

        // Aging uses invoice due dates and OUTSTANDING amounts, not booking
        // start dates. All numbers are converted using document snapshots.
        $agingTotals = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $invoiceScope(Invoice::TYPE_CUSTOMER_INVOICE)->get()
            ->each(function (Invoice $invoice) use (&$agingTotals, $convertInvoice, $today) {
                $days = $invoice->due_date
                    ? max(0, (int) floor((strtotime($today) - strtotime((string) $invoice->due_date)) / 86400))
                    : 0;
                $bucket = $days === 0 ? 'current' : ($days <= 30 ? '1-30'
                    : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+')));
                $agingTotals[$bucket] += $convertInvoice($invoice);
            });
        $agingLabels = [
            'current' => ['Current', 'غير مستحق'],
            '1-30' => ['1–30 days', '١–٣٠ يوم'],
            '31-60' => ['31–60 days', '٣١–٦٠ يوم'],
            '61-90' => ['61–90 days', '٦١–٩٠ يوم'],
            '90+' => ['90+ days', 'أكثر من ٩٠ يوم'],
        ];
        $agingBuckets = [];
        foreach ($agingTotals as $key => $amount) {
            $agingBuckets[] = [
                'key' => $key,
                'label_en' => $agingLabels[$key][0],
                'label_ar' => $agingLabels[$key][1],
                'amount_minor' => $amount,
            ];
        }

        // Actual cash movements: posted receipts and supplier payments.
        // Revenue/expense is computed separately from posted GL account types.
        $monthlyCashFlow = [];
        $monthlyProfitLoss = [];
        for ($i = 5; $i >= 0; $i--) {
            $at = now()->subMonthsNoOverflow($i);
            $from = $at->copy()->startOfMonth()->toDateString();
            $to = min($at->copy()->endOfMonth()->toDateString(), $today);
            $inflow = $this->netReceiptsMinor($branch, $currency, $from, $to);
            $outflow = SupplierPayment::query()
                ->where('status', 'posted')
                ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
                ->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to)
                ->get()->sum(fn ($p) => (new Money($p->amount_minor, $p->currency_code))
                ->convertTo($currency, (string) $p->fx_rate, $this->rounding->mode())->amountMinor);
            $monthlyCashFlow[] = [
                'month' => $at->format('Y-m'),
                'inflow_minor' => $inflow,
                'outflow_minor' => $outflow,
                'net_minor' => $inflow - $outflow,
            ];

            $glTotals = $this->glTotalsByType($from, $to, $branch);
            $revenue = $glTotals['revenue']['credits'] - $glTotals['revenue']['debits'];
            $expenses = $glTotals['expense']['debits'] - $glTotals['expense']['credits'];
            $monthlyProfitLoss[] = [
                'month' => $at->format('Y-m'),
                'revenue_minor' => $revenue,
                'expenses_minor' => $expenses,
            ];
        }

        $attentionItems = [];
        foreach ($invoiceScope(Invoice::TYPE_CUSTOMER_INVOICE)
            ->whereDate('due_date', '<', $today)->with('party')
            ->orderBy('due_date')->limit(8)->get() as $invoice) {
            $attentionItems[] = [
                'id' => 'invoice-'.$invoice->public_id,
                'type' => 'overdue_invoice',
                'severity' => 'high',
                'amount_minor' => $invoice->outstanding_minor,
                'currency' => $invoice->currency_code,
                'due_date' => $invoice->due_date,
                'title' => 'Overdue invoice '.$invoice->number,
                'party_name' => $invoice->party?->name_ar ?? $invoice->party?->name_en,
                'url' => '/invoices/'.$invoice->public_id,
                'action_url' => '/invoices/'.$invoice->public_id,
                'action_label' => 'View invoice',
            ];
        }
        foreach (Payment::query()->where('status', 'posted')->where('unallocated_minor', '>', 0)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->with('customer')->oldest('paid_at')->limit(8)->get() as $payment) {
            $attentionItems[] = [
                'id' => 'payment-'.$payment->public_id,
                'type' => 'unallocated_receipt',
                'severity' => 'medium',
                'amount_minor' => $payment->unallocated_minor,
                'currency' => $payment->currency_code,
                'title' => 'Unallocated receipt '.$payment->number,
                'party_name' => $payment->customer?->name_ar ?? $payment->customer?->name_en,
                'url' => '/payments',
                'action_url' => '/payments',
                'action_label' => 'Review receipt',
            ];
        }
        usort($attentionItems, fn ($a, $b) => ['high' => 0, 'medium' => 1, 'low' => 2][$a['severity']]
            <=> ['high' => 0, 'medium' => 1, 'low' => 2][$b['severity']]);

        return [
            'customers_count' => $customers,
            'bookings_count' => $bookings,
            'invoices_count' => $invoices,
            'tickets_count' => $tickets,
            'open_bookings' => $open,
            'confirmed_bookings' => $confirmed,
            'collected_minor' => $collected,
            'overdue_minor' => $overdue,
            'currency' => $currency,
            'recent_bookings' => $recentBookings,
            'recent_payments' => $recentPayments,
            // New KPI data
            'cash_balance_minor' => $cashBalanceMinor,
            'cash_balance_mixed_currency' => $hasForeignCash,
            'cash_accounts' => $cashAccounts->values()->all(),
            'ar_outstanding_minor' => $arOutstandingMinor,
            'ap_outstanding_minor' => $apOutstandingMinor,
            'overdue_ar_minor' => $overdueArMinor,
            'overdue_ap_minor' => $overdueApMinor,
            'customer_advances_minor' => $customerAdvancesMinor,
            'unreconciled_bank_count' => $unreconciledBankCount,
            'net_profit_minor' => $netProfitMinor,
            'aging_buckets' => $agingBuckets,
            'monthly_cash_flow' => $monthlyCashFlow,
            'monthly_profit_loss' => $monthlyProfitLoss,
            'period_from' => $monthStart,
            'period_to' => $monthEnd,
            'attention_items' => array_slice($attentionItems, 0, 10),
            'as_of' => $today,
        ];
    }

    /**
     * Net profit/loss for a period: revenue minus expense from posted journals.
     */
    private function netProfitMinor(string $from, string $to, ?Branch $branch, string $currency): int
    {
        $totals = $this->glTotalsByType($from, $to, $branch);

        return ($totals['revenue']['credits'] - $totals['revenue']['debits'])
            - ($totals['expense']['debits'] - $totals['expense']['credits']);
    }

    /**
     * Posted revenue/expense debit and credit totals, excluding year-end
     * closing entries exactly like the income statement.
     *
     * @return array{revenue: array{debits: int, credits: int}, expense: array{debits: int, credits: int}}
     */
    private function glTotalsByType(string $from, string $to, ?Branch $branch): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->tap(fn ($query) => $this->excludeClosing($query))
            ->when($branch, fn ($q) => $q->where('jl.branch_id', $branch->id))
            ->selectRaw('a.type, SUM(jl.base_debit_minor) AS debits, SUM(jl.base_credit_minor) AS credits')
            ->groupBy('a.type')->get()->keyBy('type');

        $pick = fn (string $type) => [
            'debits' => (int) ($rows->get($type)?->debits ?? 0),
            'credits' => (int) ($rows->get($type)?->credits ?? 0),
        ];

        return ['revenue' => $pick('revenue'), 'expense' => $pick('expense')];
    }

    /**
     * Money actually collected from customers in base currency: posted
     * receipts (cancelled/reversed ones are not 'posted') minus approved
     * refunds of those receipts, each converted with the receipt's FX snapshot.
     * Refunds are dated by approval.
     */
    private function netReceiptsMinor(?Branch $branch, string $currency, ?string $from = null, ?string $to = null): int
    {
        $received = Payment::query()
            ->where('status', 'posted')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_at', '<=', $to))
            ->get()->sum(fn (Payment $p) => (new Money($p->amount_minor, $p->currency_code))
            ->convertTo($currency, (string) $p->fx_rate, $this->rounding->mode())->amountMinor);

        $refunded = Refund::query()
            ->where('status', 'approved')
            ->whereHas('payment', fn ($q) => $q->where('status', 'posted'))
            ->with('payment')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->when($from, fn ($q) => $q->whereDate('approved_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('approved_at', '<=', $to))
            ->get()->sum(fn (Refund $r) => (new Money($r->amount_minor, $r->currency_code))
            ->convertTo($currency, (string) $r->payment->fx_rate, $this->rounding->mode())->amountMinor);

        return (int) $received - (int) $refunded;
    }

    private function line(object $row, int $amount): array
    {
        return [
            'code' => $row->code,
            'name_ar' => $row->name_ar,
            'name_en' => $row->name_en,
            'amount_minor' => $amount,
        ];
    }

    /**
     * Issued invoices and credit notes of a party type that still carry an
     * outstanding balance as of the date (the shared AR/AP aging definition).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Invoice>
     */
    public static function openDocuments(string $partyType, string $asOf, ?Branch $branch)
    {
        return Invoice::query()
            ->where('party_type', $partyType)
            ->whereIn('type', [
                $partyType === 'customer' ? Invoice::TYPE_CUSTOMER_INVOICE : Invoice::TYPE_SUPPLIER_INVOICE,
                Invoice::TYPE_CREDIT_NOTE,
            ])
            ->where('status', Invoice::STATUS_ISSUED)
            ->where('outstanding_minor', '>', 0)
            ->whereDate('issue_date', '<=', $asOf)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->get();
    }

    public static function ageInDays(Invoice $invoice, string $asOf): int
    {
        $due = $invoice->due_date?->toDateString() ?? $invoice->issue_date->toDateString();

        return max(0, (int) floor((strtotime($asOf) - strtotime($due)) / 86400));
    }

    public static function bucketFor(int $age): string
    {
        return match (true) {
            $age <= 30 => '0-30',
            $age <= 60 => '31-60',
            $age <= 90 => '61-90',
            default => '90+',
        };
    }
}
