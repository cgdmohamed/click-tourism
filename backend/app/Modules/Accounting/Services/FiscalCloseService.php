<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Modules\Expenses\Models\Expense;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Year-end close (TE-021, DEC-034). Closing entries are ordinary posted journals
 * created through PostingService, one per branch with profit-and-loss activity,
 * dated on the last day of the year and tagged `source_type = fiscal_year`.
 * Reopening reverses them; nothing is ever deleted or edited.
 */
class FiscalCloseService
{
    public const SOURCE_TYPE = 'fiscal_year';

    /** @var array<string, string> */
    private array $lastPreviousPeriodStatus = [];

    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
        private readonly RevaluationService $revaluation,
    ) {}

    /**
     * @return array{year: array<string, mixed>, can_close: bool, checks: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function checks(FiscalYear $year): array
    {
        $start = $year->starts_on->toDateString();
        $end = $year->ends_on->toDateString();

        $draftsByBranch = $this->countByBranch(
            JournalEntry::query()->where('status', 'draft')
                ->whereDate('entry_date', '>=', $start)->whereDate('entry_date', '<=', $end),
        );
        $drafts = array_sum(array_column($draftsByBranch, 'count'));

        $expensesByBranch = $this->countByBranch(
            Expense::query()->where('status', 'submitted')
                ->whereDate('expense_date', '>=', $start)->whereDate('expense_date', '<=', $end),
        );
        $pendingExpenses = array_sum(array_column($expensesByBranch, 'count'));

        $totals = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '>=', $start)->whereDate('je.entry_date', '<=', $end)
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as debit, COALESCE(SUM(jl.base_credit_minor),0) as credit')
            ->first();

        $lastPeriod = FiscalPeriod::query()->where('fiscal_year_id', $year->id)->orderByDesc('ends_on')->first();
        $retained = Account::query()
            ->where('code', (string) $this->settings->get('accounting', 'retained_earnings_account_code'))
            ->where('type', 'equity')->where('is_active', true)->where('is_postable', true)->exists();

        // Closing entries are dated on the last day of the year, so a period
        // must cover that date whenever there is profit-and-loss activity to
        // close. Otherwise the close would pass every check and then fail
        // while posting, with a message about a missing period.
        $hasProfitAndLoss = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->whereDate('je.entry_date', '>=', $start)
            ->whereDate('je.entry_date', '<=', $end)
            ->exists();

        $yearEndPeriod = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $end)
            ->whereDate('ends_on', '>=', $end)
            ->first();

        $yearEndMessage = $yearEndPeriod === null
            ? "No fiscal period covers {$end}; create and open the final period so the closing entries can post."
            : "The period covering {$end} is {$yearEndPeriod->status}; it must be open before closing the year so the closing entries can post.";

        // Reference behaviour (Odoo, Zoho): the close does not stop for the
        // currency revaluation, but the user is told that revaluing first is the
        // only order that works, because a posting after the close is refused.
        [$revaluationPassed, $revaluationMessage] = $this->revaluationCheck($end);

        $checks = [
            $this->check('year_open', ! $year->isClosed(), $year->isClosed() ? 'The fiscal year is already closed.' : null),
            $this->check('draft_entries', $drafts === 0, $drafts > 0 ? "{$drafts} draft journal entries must be posted or deleted (a draft can be deleted from the journal, or posted by another accountant or a senior accountant). Branches: ".$this->branchSummary($draftsByBranch).'.' : null, $drafts, $draftsByBranch),
            $this->check('submitted_expenses', $pendingExpenses === 0, $pendingExpenses > 0 ? "{$pendingExpenses} submitted expenses await approval. Branches: ".$this->branchSummary($expensesByBranch).'.' : null, $pendingExpenses, $expensesByBranch),
            $this->check('trial_balance', (int) $totals->debit === (int) $totals->credit, 'Posted debits and credits of the year differ.'),
            $this->check('retained_earnings_account', $retained, 'The retained earnings account is missing, inactive, not postable or not an equity account.'),
            $this->check('last_period_postable', $lastPeriod !== null && $lastPeriod->status !== 'closed', 'The final period is closed; reopen it before closing the year so the closing entries can post.'),
            $this->check(
                'year_end_period',
                ! $hasProfitAndLoss || ($yearEndPeriod !== null && $yearEndPeriod->status === 'open'),
                $hasProfitAndLoss ? $yearEndMessage : null,
            ),
        ];

        return [
            'year' => $this->present($year),
            'can_close' => collect($checks)->every(fn ($check) => $check['passed']),
            'checks' => $checks,
            'warnings' => $revaluationPassed ? [] : [['code' => 'fx_revaluation', 'message' => $revaluationMessage]],
        ];
    }

    /**
     * Counts the rows of a query per branch, with the branch code. Deliberately
     * not branch-scoped: a blocker in a branch the user cannot open still blocks
     * the close, so the user must see it (code and count only).
     *
     * @return array<int, array{branch_code: string, count: int}>
     */
    private function countByBranch(Builder $query): array
    {
        $counts = $query->withoutGlobalScopes()->selectRaw('branch_id, COUNT(*) as aggregate')->groupBy('branch_id')->pluck('aggregate', 'branch_id');
        $codes = Branch::query()->withoutGlobalScopes()->whereIn('id', $counts->keys())->pluck('code', 'id');

        return $counts->map(fn ($count, $branchId) => ['branch_code' => (string) ($codes[$branchId] ?? $branchId), 'count' => (int) $count])
            ->sortBy('branch_code')->values()->all();
    }

    /** @param array<int, array{branch_code: string, count: int}> $details */
    private function branchSummary(array $details): string
    {
        return collect($details)->map(fn ($row) => "{$row['branch_code']}: {$row['count']}")->implode(', ');
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function revaluationCheck(string $end): array
    {
        if (! $this->revaluation->enabled()) {
            return [true, null];
        }

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            try {
                $rows = $this->revaluation->preview($end, $branch);
            } catch (DomainException $exception) {
                return [false, "Foreign-currency balances cannot be revalued for {$branch->code}: ".$exception->getMessage()];
            }

            if ($rows !== []) {
                return [false, "Foreign-currency balances of branch {$branch->code} are not revalued at {$end}; run accounting:revalue for the final period before closing the year (a posting after the close is refused)."];
            }
        }

        return [true, null];
    }

    /**
     * The single computation of the closing entries, used by both close() and
     * closePreview() so they cannot diverge. Per branch with profit-and-loss
     * activity: one line per revenue/expense account that zeroes its balance,
     * plus the net profit/loss line against retained earnings. Posts nothing.
     *
     * @return array<int, array{branch: Branch, net_profit_minor: int, lines: array<int, array{account: Account, debit_minor: int, credit_minor: int, description: string}>}>
     */
    private function closingPlan(FiscalYear $year): array
    {
        $start = $year->starts_on->toDateString();
        $end = $year->ends_on->toDateString();
        $retained = $this->posting->account((string) $this->settings->get('accounting', 'retained_earnings_account_code'));

        $perBranch = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->whereDate('je.entry_date', '>=', $start)->whereDate('je.entry_date', '<=', $end)
            ->groupBy('jl.branch_id', 'a.id')
            ->selectRaw('jl.branch_id, a.id as account_id, COALESCE(SUM(jl.base_debit_minor),0) - COALESCE(SUM(jl.base_credit_minor),0) as net')
            ->havingRaw('COALESCE(SUM(jl.base_debit_minor),0) - COALESCE(SUM(jl.base_credit_minor),0) <> 0')
            ->orderBy('jl.branch_id')->orderBy('a.code')
            ->get()
            ->groupBy('branch_id');

        $accounts = Account::query()
            ->whereIn('id', $perBranch->flatten()->pluck('account_id')->unique())->get()->keyBy('id');
        $branches = Branch::query()->whereIn('id', $perBranch->keys())->get()->keyBy('id');

        $plan = [];
        foreach ($perBranch as $branchId => $rows) {
            $lines = [];
            $profit = 0;

            foreach ($rows as $row) {
                $net = (int) $row->net;
                $profit -= $net;
                $account = $accounts[$row->account_id];
                $lines[] = [
                    'account' => $account,
                    'debit_minor' => $net > 0 ? 0 : abs($net),
                    'credit_minor' => $net > 0 ? $net : 0,
                    'description' => 'Close '.$account->code,
                ];
            }

            if ($profit !== 0) {
                $lines[] = [
                    'account' => $retained,
                    'debit_minor' => $profit < 0 ? abs($profit) : 0,
                    'credit_minor' => $profit > 0 ? $profit : 0,
                    'description' => $profit > 0 ? 'Net profit to retained earnings' : 'Net loss to retained earnings',
                ];
            }

            $plan[(int) $branchId] = ['branch' => $branches[$branchId], 'net_profit_minor' => $profit, 'lines' => $lines];
        }

        return $plan;
    }

    /**
     * Read-only preview of the closing entries close() would post.
     *
     * @return array{year: array<string, mixed>, currency: string, branches: array<int, array<string, mixed>>}
     */
    public function closePreview(FiscalYear $year): array
    {
        $plan = $this->closingPlan($year);

        return [
            'year' => $this->present($year),
            'currency' => $this->settings->baseCurrency(),
            'branches' => array_values(array_map(fn (array $item) => [
                'branch_code' => $item['branch']->code,
                'branch_name_ar' => $item['branch']->name_ar,
                'branch_name_en' => $item['branch']->name_en,
                'net_profit_minor' => $item['net_profit_minor'],
                'lines' => array_map(fn (array $line) => [
                    'account_code' => $line['account']->code,
                    'account_name_ar' => $line['account']->name_ar,
                    'account_name_en' => $line['account']->name_en,
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                ], $item['lines']),
            ], $plan)),
        ];
    }

    public function close(FiscalYear $year, ?int $actorId): FiscalYear
    {
        return DB::transaction(function () use ($year, $actorId) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            $report = $this->checks($year);
            if (! $report['can_close']) {
                $failed = collect($report['checks'])->where('passed', false)->pluck('message')->filter()->implode(' ');
                throw new DomainException('The year cannot be closed: '.$failed);
            }

            $end = $year->ends_on->toDateString();
            $base = $this->settings->baseCurrency();
            $round = $year->close_count + 1;
            $plan = $this->closingPlan($year);

            $actor = $actorId ? User::query()->find($actorId) : null;
            if ($actor) {
                $allowed = $this->branchScope->allowedBranchIds($actor);
                if (array_diff(array_map('intval', array_keys($plan)), $allowed) !== []) {
                    throw new AuthorizationException('Closing a year needs access to every branch that has activity in it.');
                }
            }

            foreach ($plan as $branchId => $item) {
                $lines = array_map(
                    fn (array $line) => $line['debit_minor'] > 0
                        ? PostingLine::debit($line['account'], $line['debit_minor'], null, null, $line['description'])
                        : PostingLine::credit($line['account'], $line['credit_minor'], null, null, $line['description']),
                    $item['lines'],
                );

                $this->posting->post(PostingRequest::make(
                    entryDate: $end,
                    branch: $item['branch'],
                    currency: $base,
                    lines: $lines,
                    memo: 'Year-end close '.$year->name,
                    sourceType: self::SOURCE_TYPE,
                    sourceId: $year->id,
                    event: "close:{$round}:{$branchId}",
                    actorId: $actorId,
                ));
            }

            FiscalPeriod::query()->where('fiscal_year_id', $year->id)->update(['status' => 'closed']);
            $year->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actorId, 'close_count' => $round]);

            $this->audit->log('fiscal_year.closed', $year, ['status' => 'open'], [
                'status' => 'closed',
                'closing_entries' => count($plan),
            ], $actorId);

            return $year->refresh();
        });
    }

    /** See lastPreviousPeriodStatus() for the period statuses before the reopen. */
    public function reopen(FiscalYear $year, string $reason, ?int $actorId, bool $restorePeriodStatus = false): FiscalYear
    {
        return DB::transaction(function () use ($year, $reason, $actorId, $restorePeriodStatus) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            if (! $year->isClosed()) {
                throw new DomainException('Only a closed fiscal year can be reopened.');
            }

            $previous = FiscalPeriod::query()->where('fiscal_year_id', $year->id)->orderBy('starts_on')->get()
                ->mapWithKeys(fn (FiscalPeriod $period) => [(string) $period->id => $period->status])->all();

            // Reversals post on the original date, so the periods must accept postings first.
            FiscalPeriod::query()->where('fiscal_year_id', $year->id)->update(['status' => 'open']);

            $closings = JournalEntry::query()
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $year->id)
                ->where('status', 'posted')
                ->where('event', 'like', "close:{$year->close_count}:%")
                ->whereNull('reversal_of_id')
                ->get();

            foreach ($closings as $entry) {
                $this->posting->reverse($entry, 'Reopen '.$year->name.': '.$reason, $actorId);
            }

            // Restore only after the reversals have posted, so a restored
            // `closed`/`soft_closed` period can never block them. Open stays open.
            $restored = [];
            if ($restorePeriodStatus) {
                foreach ($previous as $periodId => $status) {
                    if (in_array($status, ['soft_closed', 'closed'], true)) {
                        FiscalPeriod::query()->whereKey($periodId)->update(['status' => $status]);
                        $restored[$periodId] = $status;
                    }
                }
            }

            $year->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

            $this->audit->log('fiscal_year.reopened', $year, ['status' => 'closed'], [
                'status' => 'open',
                'reason' => $reason,
                'reversed_entries' => $closings->count(),
                'previous_period_status' => $previous,
                'restore_period_status' => $restorePeriodStatus,
                'restored_period_status' => $restored,
            ], $actorId);

            $this->lastPreviousPeriodStatus = $previous;

            return $year->refresh();
        });
    }

    /**
     * Period statuses as they were just before the latest reopen() call.
     *
     * @return array<string, string>
     */
    public function lastPreviousPeriodStatus(): array
    {
        return $this->lastPreviousPeriodStatus;
    }

    /** @return array<string, mixed> */
    public function present(FiscalYear $year): array
    {
        return [
            'id' => (string) $year->id,
            'name' => $year->name,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'status' => $year->status,
            'closed_at' => $year->closed_at?->toIso8601String(),
            'close_count' => $year->close_count,
        ];
    }

    /** @return array<string, mixed> */
    private function check(string $code, bool $passed, ?string $message = null, ?int $count = null, ?array $details = null): array
    {
        return ['code' => $code, 'passed' => $passed, 'message' => $passed ? null : $message, 'count' => $count]
            + ($details !== null ? ['details' => $details] : []);
    }
}
