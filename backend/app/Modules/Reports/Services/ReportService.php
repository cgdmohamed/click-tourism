<?php

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierObligation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Read-only financial reporting. Reports aggregate stored base-currency
 * amounts and never re-convert history (ADR-0003 §7, ADR-0004 §8).
 */
class ReportService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly FxService $fx,
    ) {}

    /**
     * Without $onlyCurrency the report sums stored base-currency amounts.
     * With it, only lines posted in that transaction currency are included
     * and their original-currency amounts are summed (no re-conversion).
     */
    public function trialBalance(string $from, string $to, ?Branch $branch, ?string $onlyCurrency = null): array
    {
        $currency = $onlyCurrency ?? $this->settings->baseCurrency();
        [$debitColumn, $creditColumn] = $this->amountColumns($onlyCurrency);

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->when($onlyCurrency, fn ($query) => $query->where('jl.currency_code', $onlyCurrency))
            ->groupBy('a.id', 'a.public_id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type')
            ->selectRaw('a.id as account_id, a.public_id, a.code, a.name_ar, a.name_en, a.type')
            ->selectRaw("COALESCE(SUM($debitColumn), 0) as debit_minor")
            ->selectRaw("COALESCE(SUM($creditColumn), 0) as credit_minor")
            ->orderBy('a.code')
            ->get()
            ->map(function ($row) {
                $row->debit_minor = (int) $row->debit_minor;
                $row->credit_minor = (int) $row->credit_minor;
                $row->balance_minor = $row->debit_minor - $row->credit_minor;

                return $row;
            })
            ->all();

        return [
            'currency' => $currency,
            'basis' => $onlyCurrency ? 'original' : 'base',
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_debit_minor' => array_sum(array_column($rows, 'debit_minor')),
            'total_credit_minor' => array_sum(array_column($rows, 'credit_minor')),
        ];
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
            ->selectRaw('jl.id as line_id, je.public_id as entry_id, je.number, je.entry_date, je.memo, je.source_type, je.event')
            ->selectRaw('a.code as account_code, jl.description, jl.currency_code, jl.debit_minor, jl.credit_minor')
            ->selectRaw('jl.base_debit_minor, jl.base_credit_minor')
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
                'event' => $row->event,
                'currency' => $row->currency_code,
                'debit_minor' => (int) $row->debit_minor,
                'credit_minor' => (int) $row->credit_minor,
                'base_debit_minor' => (int) $row->base_debit_minor,
                'base_credit_minor' => (int) $row->base_credit_minor,
                'balance_minor' => $openingMinor + (int) $row->running_minor,
            ])
            ->all();

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

    public function incomeStatement(string $from, string $to, ?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type')
            ->selectRaw('a.code, a.name_ar, a.name_en, a.type')
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit_minor')
            ->selectRaw('COALESCE(SUM(jl.base_credit_minor), 0) as credit_minor')
            ->orderBy('a.code')
            ->get();

        $revenue = [];
        $expenses = [];

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
                    $expenses[] = $this->line($row, $amount);
                }
            }
        }

        $totalRevenue = array_sum(array_column($revenue, 'amount_minor'));
        $totalExpense = array_sum(array_column($expenses, 'amount_minor'));

        return [
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue_minor' => $totalRevenue,
            'total_expense_minor' => $totalExpense,
            'net_profit_minor' => $totalRevenue - $totalExpense,
        ];
    }

    public function balanceSheet(string $asOf, ?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('a.type', ['asset', 'liability', 'equity'])
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type')
            ->selectRaw('a.code, a.name_ar, a.name_en, a.type')
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
                'asset' => $assets[] = $this->line($row, $amount),
                'liability' => $liabilities[] = $this->line($row, $amount),
                'equity' => $equity[] = $this->line($row, $amount),
            };
        }

        return [
            'currency' => $currency,
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets_minor' => array_sum(array_column($assets, 'amount_minor')),
            'total_liabilities_minor' => array_sum(array_column($liabilities, 'amount_minor')),
            'total_equity_minor' => array_sum(array_column($equity, 'amount_minor')),
        ];
    }

    public function aging(string $type, string $asOf, ?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();
        $buckets = [
            '0-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '90+' => 0,
        ];

        if ($type === 'ar') {
            $bookings = Booking::query()
                ->withSum('allocations', 'amount_minor')
                ->whereNotIn('status', ['cancelled', 'closed', 'no_show'])
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->get();

            foreach ($bookings as $booking) {
                $paid = (int) ($booking->allocations_sum_amount_minor ?? 0);
                $balance = $booking->total_sell_minor - $paid;
                if ($balance <= 0) {
                    continue;
                }

                $base = (new Money($balance, $booking->currency_code))
                    ->convertTo($currency, (string) $booking->fx_rate)->amountMinor;

                $age = max(0, (int) floor((strtotime($asOf) - $booking->start_date->timestamp) / 86400));
                $buckets[$this->bucket($age)] += $base;
            }
        } else {
            $obligations = SupplierObligation::query()
                ->where('status', 'open')
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->get();

            foreach ($obligations as $obligation) {
                $rate = strtoupper($obligation->currency_code) === $currency
                    ? '1'
                    : $this->fx->rateFor($obligation->currency_code, $asOf);

                $base = (new Money($obligation->amount_minor, $obligation->currency_code))
                    ->convertTo($currency, $rate)->amountMinor;

                $age = max(0, (int) floor((strtotime($asOf) - $obligation->created_at->timestamp) / 86400));
                $buckets[$this->bucket($age)] += $base;
            }
        }

        $labels = [
            '0-30' => $type === 'ar' ? '0-30 يوم' : '0-30 days',
            '31-60' => $type === 'ar' ? '31-60 يوم' : '31-60 days',
            '61-90' => $type === 'ar' ? '61-90 يوم' : '61-90 days',
            '90+' => $type === 'ar' ? 'أكثر من 90 يوم' : '90+ days',
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

    public function dashboard(?Branch $branch): array
    {
        $currency = $this->settings->baseCurrency();

        $bookingScope = fn () => Booking::query()->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $open = $bookingScope()->whereIn('status', ['draft', 'quoted', 'confirmed', 'in_progress'])->count();
        $confirmed = $bookingScope()->whereIn('status', ['confirmed', 'in_progress'])->count();

        $collected = 0;
        Payment::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->where('status', 'posted')
            ->get()
            ->each(function (Payment $payment) use (&$collected, $currency) {
                $collected += (new Money($payment->amount_minor, $payment->currency_code))
                    ->convertTo($currency, (string) $payment->fx_rate)->amountMinor;
            });

        $overdue = 0;
        $bookingScope()
            ->withSum('allocations', 'amount_minor')
            ->whereNotIn('status', ['cancelled', 'closed', 'no_show'])
            ->where('start_date', '<', now()->toDateString())
            ->get()
            ->each(function (Booking $booking) use (&$overdue, $currency) {
                $balance = $booking->total_sell_minor - (int) ($booking->allocations_sum_amount_minor ?? 0);
                if ($balance > 0) {
                    $overdue += (new Money($balance, $booking->currency_code))
                        ->convertTo($currency, (string) $booking->fx_rate)->amountMinor;
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

        return [
            'open_bookings' => $open,
            'confirmed_bookings' => $confirmed,
            'collected_minor' => $collected,
            'overdue_minor' => $overdue,
            'currency' => $currency,
            'recent_bookings' => $recentBookings,
            'recent_payments' => $recentPayments,
        ];
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

    private function bucket(int $age): string
    {
        return match (true) {
            $age <= 30 => '0-30',
            $age <= 60 => '31-60',
            $age <= 90 => '61-90',
            default => '90+',
        };
    }
}
