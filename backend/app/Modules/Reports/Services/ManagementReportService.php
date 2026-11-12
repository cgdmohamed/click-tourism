<?php

namespace App\Modules\Reports\Services;

use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Management report pack (GAP-RPT-01): profitability, sales, commissions and
 * tax summary. Everything is derived from posted journal lines (base currency,
 * branch scoped) so each report reconciles to the income statement / trial
 * balance; the commissions report adds the module's pending accruals and payouts.
 *
 * Revenue/cost are attributed to a booking through the journal source: the
 * booking itself, an invoice linked to a booking, or an accounting document
 * linked to a booking. Whatever has no booking stays visible as "unlinked" so
 * the totals always equal the ledger.
 */
class ManagementReportService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly ReportService $reports,
    ) {}

    /** @return array<string, mixed> */
    public function profitability(string $from, string $to, ?Branch $branch): array
    {
        $rows = $this->bookingLines($from, $to, $branch);
        $byBooking = [];
        foreach ($rows as $row) {
            $key = $row['booking_id'] ?? 0;
            $byBooking[$key] ??= ['revenue_minor' => 0, 'direct_cost_minor' => 0];
            $byBooking[$key]['revenue_minor'] += $row['revenue'];
            $byBooking[$key]['direct_cost_minor'] += $row['cost'];
        }

        $details = $this->bookingDetails(array_filter(array_keys($byBooking)));
        $bookings = [];
        $serviceTypes = [];
        foreach ($byBooking as $bookingId => $amounts) {
            if ($amounts['revenue_minor'] === 0 && $amounts['direct_cost_minor'] === 0) {
                continue;
            }
            $detail = $details[$bookingId] ?? null;
            $bookings[] = [
                'booking' => $detail ? [
                    'id' => $detail['public_id'],
                    'number' => $detail['number'],
                    'status' => $detail['status'],
                    'customer_name_ar' => $detail['customer_name_ar'],
                    'customer_name_en' => $detail['customer_name_en'],
                ] : null,
            ] + $amounts + ['margin_minor' => $amounts['revenue_minor'] - $amounts['direct_cost_minor']];

            $revenueSplit = $this->allocate($amounts['revenue_minor'], $detail['revenue_weights'] ?? []);
            $costSplit = $this->allocate($amounts['direct_cost_minor'], $detail['cost_weights'] ?? []);
            foreach ($revenueSplit + $costSplit as $type => $_) {
                $serviceTypes[$type] ??= ['revenue_minor' => 0, 'direct_cost_minor' => 0];
            }
            foreach ($revenueSplit as $type => $amount) {
                $serviceTypes[$type]['revenue_minor'] += $amount;
            }
            foreach ($costSplit as $type => $amount) {
                $serviceTypes[$type]['direct_cost_minor'] += $amount;
            }
        }

        usort($bookings, fn ($a, $b) => strcmp((string) ($a['booking']['number'] ?? '~'), (string) ($b['booking']['number'] ?? '~')));
        ksort($serviceTypes);

        $byService = [];
        foreach ($serviceTypes as $type => $amounts) {
            $byService[] = ['service_type' => $type === '' ? null : $type] + $amounts
                + ['margin_minor' => $amounts['revenue_minor'] - $amounts['direct_cost_minor']];
        }

        $revenue = array_sum(array_column($bookings, 'revenue_minor'));
        $cost = array_sum(array_column($bookings, 'direct_cost_minor'));
        $income = $this->reports->incomeStatement($from, $to, $branch);

        return [
            'currency' => $this->settings->baseCurrency(),
            'from' => $from,
            'to' => $to,
            'by_booking' => $bookings,
            'by_service_type' => $byService,
            'total_revenue_minor' => $revenue,
            'total_direct_cost_minor' => $cost,
            'total_margin_minor' => $revenue - $cost,
            'reconciliation' => [
                'income_statement_revenue_minor' => $income['total_revenue_minor'],
                'income_statement_cost_of_services_minor' => $income['total_cost_of_services_minor'],
                'reconciled' => $income['total_revenue_minor'] === $revenue && $income['total_cost_of_services_minor'] === $cost,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function sales(string $from, string $to, ?Branch $branch): array
    {
        $rows = $this->bookingLines($from, $to, $branch);

        $byBranch = [];
        $byUser = [];
        $bookingOwner = DB::table('bookings')
            ->whereIn('id', array_filter(array_unique(array_column($rows, 'booking_id'))))
            ->pluck('owner_user_id', 'id');

        foreach ($rows as $row) {
            if ($row['revenue'] === 0) {
                continue;
            }
            $byBranch[$row['branch_id']]['revenue_minor'] = ($byBranch[$row['branch_id']]['revenue_minor'] ?? 0) + $row['revenue'];

            $userId = $row['booking_id'] !== null ? ($bookingOwner[$row['booking_id']] ?? null) : null;
            $key = $userId ?? 0;
            $byUser[$key]['revenue_minor'] = ($byUser[$key]['revenue_minor'] ?? 0) + $row['revenue'];
            if ($row['booking_id'] !== null) {
                $byUser[$key]['bookings'][$row['booking_id']] = true;
            }
        }

        $branches = DB::table('branches')->whereIn('id', array_keys($byBranch))->get()->keyBy('id');
        $users = DB::table('users')->whereIn('id', array_filter(array_keys($byUser)))->get()->keyBy('id');

        $branchRows = [];
        foreach ($byBranch as $id => $amounts) {
            $branchRows[] = [
                'branch' => ['id' => $branches[$id]->public_id, 'name_ar' => $branches[$id]->name_ar, 'name_en' => $branches[$id]->name_en],
                'revenue_minor' => $amounts['revenue_minor'],
            ];
        }
        usort($branchRows, fn ($a, $b) => $b['revenue_minor'] <=> $a['revenue_minor']);

        $userRows = [];
        foreach ($byUser as $id => $amounts) {
            $userRows[] = [
                'user' => $id === 0 ? null : ['id' => $users[$id]->public_id ?? (string) $id, 'name' => $users[$id]->name ?? ''],
                'bookings_count' => count($amounts['bookings'] ?? []),
                'revenue_minor' => $amounts['revenue_minor'],
            ];
        }
        usort($userRows, fn ($a, $b) => $b['revenue_minor'] <=> $a['revenue_minor']);

        $total = array_sum(array_column($branchRows, 'revenue_minor'));
        $income = $this->reports->incomeStatement($from, $to, $branch);

        return [
            'currency' => $this->settings->baseCurrency(),
            'from' => $from,
            'to' => $to,
            'by_branch' => $branchRows,
            'by_user' => $userRows,
            'total_revenue_minor' => $total,
            'reconciliation' => [
                'income_statement_revenue_minor' => $income['total_revenue_minor'],
                'reconciled' => $income['total_revenue_minor'] === $total,
            ],
        ];
    }

    /**
     * Commission expense recognised in the ledger (accruals net of reversals,
     * by beneficiary), pending accruals not yet in the ledger and payouts.
     *
     * @return array<string, mixed>
     */
    public function commissions(string $from, string $to, ?Branch $branch): array
    {
        $account = DB::table('accounts')->where('code', (string) $this->settings->get('accounting', 'commission_expense_account_code', '6700'))->first();

        $expense = $account ? DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('commissions as c', 'c.id', '=', 'je.source_id')
            ->where('je.status', 'posted')
            ->where('je.source_type', 'commission')
            ->where('jl.account_id', $account->id)
            ->whereDate('je.entry_date', '>=', $from)->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('jl.branch_id', $branch->id))
            ->groupBy('c.beneficiary_type', 'c.beneficiary_id')
            ->selectRaw('c.beneficiary_type, c.beneficiary_id, SUM(jl.base_debit_minor) - SUM(jl.base_credit_minor) as amount')
            ->get() : collect();

        $pending = DB::table('commissions')
            ->whereIn('status', ['accrued', 'approved'])
            ->whereDate('accrued_on', '>=', $from)->whereDate('accrued_on', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->groupBy('beneficiary_type', 'beneficiary_id')
            ->selectRaw('beneficiary_type, beneficiary_id, SUM(amount_minor) as amount')
            ->get();

        $paid = DB::table('commission_payments as p')
            ->join('commissions as c', 'c.id', '=', 'p.commission_id')
            ->where('p.status', 'posted')
            ->whereDate('p.paid_on', '>=', $from)->whereDate('p.paid_on', '<=', $to)
            ->when($branch, fn ($q) => $q->where('p.branch_id', $branch->id))
            ->groupBy('c.beneficiary_type', 'c.beneficiary_id')
            ->selectRaw('c.beneficiary_type, c.beneficiary_id, SUM(p.amount_minor) as amount')
            ->get();

        $rows = [];
        foreach (['expense_minor' => $expense, 'pending_minor' => $pending, 'paid_minor' => $paid] as $column => $set) {
            foreach ($set as $item) {
                $key = $item->beneficiary_type.':'.$item->beneficiary_id;
                $rows[$key] ??= [
                    'beneficiary_type' => $item->beneficiary_type,
                    'beneficiary_id' => (int) $item->beneficiary_id,
                    'expense_minor' => 0, 'pending_minor' => 0, 'paid_minor' => 0,
                ];
                $rows[$key][$column] = (int) $item->amount;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $describe = CommissionRule::describeBeneficiary($row['beneficiary_type'], $row['beneficiary_id']);
            $out[] = [
                'beneficiary_type' => $row['beneficiary_type'],
                'beneficiary' => $describe ? ['id' => $describe['id'], 'name_ar' => $describe['name_ar'], 'name_en' => $describe['name_en']] : null,
                'pending_minor' => $row['pending_minor'],
                'expense_minor' => $row['expense_minor'],
                'paid_minor' => $row['paid_minor'],
            ];
        }
        usort($out, fn ($a, $b) => $b['expense_minor'] <=> $a['expense_minor']);

        $totalExpense = array_sum(array_column($out, 'expense_minor'));
        $glExpense = $account ? (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $account->id)
            ->whereDate('je.entry_date', '>=', $from)->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('jl.branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor) - SUM(jl.base_credit_minor), 0) as amount')
            ->value('amount') : 0;

        return [
            'currency' => $this->settings->baseCurrency(),
            'from' => $from,
            'to' => $to,
            'rows' => $out,
            'total_pending_minor' => array_sum(array_column($out, 'pending_minor')),
            'total_expense_minor' => $totalExpense,
            'total_paid_minor' => array_sum(array_column($out, 'paid_minor')),
            'reconciliation' => [
                'commission_account_code' => $account?->code,
                'ledger_expense_minor' => $glExpense,
                'reconciled' => $glExpense === $totalExpense,
            ],
        ];
    }

    /**
     * Output tax (credit-side) and input tax (debit-side) per tax profile from
     * posted lines on the tax accounts. A profile owns its sales (output) and
     * purchase (input) accounts; an account shared by profiles goes to the
     * lowest profile id. The configured default tax accounts not owned by any
     * profile form the "default" row, so totals equal the ledger.
     *
     * @return array<string, mixed>
     */
    public function tax(string $from, string $to, ?Branch $branch): array
    {
        $codes = fn (string $key, string $default) => (string) $this->settings->get('accounting', $key, $default);
        $defaultOutput = DB::table('accounts')->whereIn('code', array_unique([$codes('output_tax_account_code', '2200'), $codes('tax_payable_account_code', '2200')]))->pluck('id')->all();
        $defaultInput = DB::table('accounts')->where('code', $codes('input_tax_account_code', '1160'))->pluck('id')->all();

        $profiles = DB::table('tax_profiles')->orderBy('id')->get();
        $outputOwner = [];
        $inputOwner = [];
        foreach ($profiles as $profile) {
            if ($profile->sales_account_id !== null) {
                $outputOwner[$profile->sales_account_id] ??= $profile->id;
            }
            if ($profile->purchase_account_id !== null) {
                $inputOwner[$profile->purchase_account_id] ??= $profile->id;
            }
        }

        $accountIds = array_values(array_unique(array_merge($defaultOutput, $defaultInput, array_keys($outputOwner), array_keys($inputOwner))));
        $movements = $accountIds === [] ? collect() : DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $accountIds)
            ->whereDate('je.entry_date', '>=', $from)->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('jl.branch_id', $branch->id))
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, SUM(jl.base_debit_minor) as debit, SUM(jl.base_credit_minor) as credit')
            ->get()->keyBy('account_id');

        // Output side wins for an account configured on both sides (never expected).
        $outputAccounts = array_values(array_unique(array_merge($defaultOutput, array_keys($outputOwner))));
        $inputAccounts = array_values(array_diff(array_unique(array_merge($defaultInput, array_keys($inputOwner))), $outputAccounts));

        $rows = [];
        foreach ($outputAccounts as $accountId) {
            $m = $movements[$accountId] ?? null;
            if ($m !== null) {
                $key = 'p'.($outputOwner[$accountId] ?? 'default');
                $rows[$key]['output_minor'] = ($rows[$key]['output_minor'] ?? 0) + (int) $m->credit - (int) $m->debit;
            }
        }
        foreach ($inputAccounts as $accountId) {
            $m = $movements[$accountId] ?? null;
            if ($m !== null) {
                $key = 'p'.($inputOwner[$accountId] ?? 'default');
                $rows[$key]['input_minor'] = ($rows[$key]['input_minor'] ?? 0) + (int) $m->debit - (int) $m->credit;
            }
        }

        $out = [];
        foreach ($rows as $key => $amounts) {
            $profile = $key === 'pdefault' ? null : $profiles->firstWhere('id', (int) substr($key, 1));
            $output = $amounts['output_minor'] ?? 0;
            $input = $amounts['input_minor'] ?? 0;
            $out[] = [
                'tax_profile' => $profile ? ['id' => $profile->public_id, 'name' => $profile->name, 'rate' => (string) $profile->rate] : null,
                'output_minor' => $output,
                'input_minor' => $input,
                'net_payable_minor' => $output - $input,
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) ($a['tax_profile']['name'] ?? '~'), (string) ($b['tax_profile']['name'] ?? '~')));

        $output = array_sum(array_column($out, 'output_minor'));
        $input = array_sum(array_column($out, 'input_minor'));

        return [
            'currency' => $this->settings->baseCurrency(),
            'from' => $from,
            'to' => $to,
            'enabled' => (bool) $this->settings->bool('tax', 'enabled', false),
            'rows' => $out,
            'total_output_minor' => $output,
            'total_input_minor' => $input,
            'total_net_payable_minor' => $output - $input,
        ];
    }

    /**
     * Posted revenue / direct-cost movements grouped by branch, booking and account.
     *
     * @return list<array{branch_id: int, booking_id: ?int, revenue: int, cost: int}>
     */
    private function bookingLines(string $from, string $to, ?Branch $branch): array
    {
        $bookingExpr = "COALESCE(CASE WHEN je.source_type = 'booking' THEN je.source_id END, inv.booking_id, ad.booking_id)";

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->leftJoin('invoices as inv', fn ($join) => $join->on('inv.id', '=', 'je.source_id')->whereRaw("je.source_type = 'invoice'"))
            ->leftJoin('accounting_documents as ad', fn ($join) => $join->on('ad.id', '=', 'je.source_id')->whereRaw("je.source_type = 'accounting_document'"))
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->where(fn ($q) => $q->whereNull('je.source_type')->orWhere('je.source_type', '!=', 'fiscal_year'))
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('jl.branch_id', $branch->id))
            ->groupBy('jl.branch_id', 'a.id', 'a.code', 'a.type')
            ->groupByRaw($bookingExpr)
            ->selectRaw("jl.branch_id, a.id as account_id, a.code, a.type, {$bookingExpr} as booking_id")
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->get();

        $costIds = $this->reports->costOfServicesAccountIds();
        $costCode = $this->reports->costAccountCode();

        $result = [];
        foreach ($rows as $row) {
            $revenue = 0;
            $cost = 0;
            if ($row->type === 'revenue') {
                $revenue = (int) $row->credit - (int) $row->debit;
            } elseif (isset($costIds[(int) $row->account_id]) || str_starts_with((string) $row->code, $costCode)) {
                $cost = (int) $row->debit - (int) $row->credit;
            }
            if ($revenue === 0 && $cost === 0) {
                continue;
            }
            $result[] = [
                'branch_id' => (int) $row->branch_id,
                'booking_id' => $row->booking_id !== null ? (int) $row->booking_id : null,
                'revenue' => $revenue,
                'cost' => $cost,
            ];
        }

        return $result;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function bookingDetails(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $bookings = DB::table('bookings as b')
            ->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->whereIn('b.id', $ids)
            ->get(['b.id', 'b.public_id', 'b.number', 'b.status', 'c.name_ar', 'c.name_en']);
        $items = DB::table('booking_items')->whereIn('booking_id', $ids)->get()->groupBy('booking_id');

        $details = [];
        foreach ($bookings as $booking) {
            $revenueWeights = [];
            $costWeights = [];
            foreach ($items[$booking->id] ?? [] as $item) {
                $revenueWeights[$item->service_type] = ($revenueWeights[$item->service_type] ?? 0) + max(0, (int) $item->total_sell_minor - (int) $item->tax_minor);
                $costWeights[$item->service_type] = ($costWeights[$item->service_type] ?? 0) + max(0, (int) $item->total_cost_minor);
            }
            $details[$booking->id] = [
                'public_id' => $booking->public_id,
                'number' => $booking->number,
                'status' => $booking->status,
                'customer_name_ar' => $booking->name_ar,
                'customer_name_en' => $booking->name_en,
                'revenue_weights' => $revenueWeights,
                'cost_weights' => $costWeights,
            ];
        }

        return $details;
    }

    /**
     * Splits an amount across weights (largest remainder, exact sum). Without
     * usable weights the amount stays in the "" (unallocated) bucket.
     *
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    private function allocate(int $amount, array $weights): array
    {
        if ($amount === 0) {
            return [];
        }
        $total = array_sum($weights);
        if ($total <= 0) {
            return ['' => $amount];
        }

        $sign = $amount < 0 ? -1 : 1;
        $abs = abs($amount);
        $shares = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $type => $weight) {
            $shares[$type] = intdiv($abs * $weight, $total);
            $remainders[$type] = ($abs * $weight) % $total;
            $assigned += $shares[$type];
        }
        arsort($remainders);
        foreach (array_keys($remainders) as $type) {
            if ($assigned >= $abs) {
                break;
            }
            $shares[$type]++;
            $assigned++;
        }

        return array_map(fn ($share) => $share * $sign, array_filter($shares, fn ($share) => $share !== 0));
    }
}
