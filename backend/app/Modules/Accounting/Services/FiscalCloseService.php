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

    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{year: array<string, mixed>, can_close: bool, checks: array<int, array<string, mixed>>}
     */
    public function checks(FiscalYear $year): array
    {
        $start = $year->starts_on->toDateString();
        $end = $year->ends_on->toDateString();

        $drafts = JournalEntry::query()->where('status', 'draft')
            ->whereDate('entry_date', '>=', $start)->whereDate('entry_date', '<=', $end)->count();

        $pendingExpenses = Expense::query()->where('status', 'submitted')
            ->whereDate('expense_date', '>=', $start)->whereDate('expense_date', '<=', $end)->count();

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

        $checks = [
            $this->check('year_open', ! $year->isClosed(), $year->isClosed() ? 'The fiscal year is already closed.' : null),
            $this->check('draft_entries', $drafts === 0, $drafts > 0 ? "{$drafts} draft journal entries must be posted or discarded." : null, $drafts),
            $this->check('submitted_expenses', $pendingExpenses === 0, $pendingExpenses > 0 ? "{$pendingExpenses} submitted expenses await approval." : null, $pendingExpenses),
            $this->check('trial_balance', (int) $totals->debit === (int) $totals->credit, 'Posted debits and credits of the year differ.'),
            $this->check('retained_earnings_account', $retained, 'The retained earnings account is missing, inactive, not postable or not an equity account.'),
            $this->check('last_period_postable', $lastPeriod !== null && $lastPeriod->status !== 'closed', 'The final period is closed; reopen it before closing the year so the closing entries can post.'),
        ];

        return [
            'year' => $this->present($year),
            'can_close' => collect($checks)->every(fn ($check) => $check['passed']),
            'checks' => $checks,
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

            $start = $year->starts_on->toDateString();
            $end = $year->ends_on->toDateString();
            $base = $this->settings->baseCurrency();
            $retained = $this->posting->account((string) $this->settings->get('accounting', 'retained_earnings_account_code'));
            $round = $year->close_count + 1;

            $perBranch = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('je.status', 'posted')
                ->whereIn('a.type', ['revenue', 'expense'])
                ->whereDate('je.entry_date', '>=', $start)->whereDate('je.entry_date', '<=', $end)
                ->groupBy('jl.branch_id', 'a.id')
                ->selectRaw('jl.branch_id, a.id as account_id, COALESCE(SUM(jl.base_debit_minor),0) - COALESCE(SUM(jl.base_credit_minor),0) as net')
                ->havingRaw('COALESCE(SUM(jl.base_debit_minor),0) - COALESCE(SUM(jl.base_credit_minor),0) <> 0')
                ->get()
                ->groupBy('branch_id');

            $actor = $actorId ? User::query()->find($actorId) : null;
            if ($actor) {
                $allowed = $this->branchScope->allowedBranchIds($actor);
                if (array_diff($perBranch->keys()->map(fn ($id) => (int) $id)->all(), $allowed) !== []) {
                    throw new AuthorizationException('Closing a year needs access to every branch that has activity in it.');
                }
            }

            $accounts = Account::query()
                ->whereIn('id', $perBranch->flatten()->pluck('account_id')->unique())->get()->keyBy('id');

            foreach ($perBranch as $branchId => $rows) {
                $lines = [];
                $profit = 0;

                foreach ($rows as $row) {
                    $net = (int) $row->net;
                    $profit -= $net;
                    $account = $accounts[$row->account_id];
                    $lines[] = $net > 0
                        ? PostingLine::credit($account, $net, null, null, 'Close '.$account->code)
                        : PostingLine::debit($account, abs($net), null, null, 'Close '.$account->code);
                }

                if ($profit > 0) {
                    $lines[] = PostingLine::credit($retained, $profit, null, null, 'Net profit to retained earnings');
                } elseif ($profit < 0) {
                    $lines[] = PostingLine::debit($retained, abs($profit), null, null, 'Net loss to retained earnings');
                }

                $this->posting->post(PostingRequest::make(
                    entryDate: $end,
                    branch: Branch::query()->findOrFail($branchId),
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
                'closing_entries' => $perBranch->count(),
            ], $actorId);

            return $year->refresh();
        });
    }

    public function reopen(FiscalYear $year, string $reason, ?int $actorId): FiscalYear
    {
        return DB::transaction(function () use ($year, $reason, $actorId) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            if (! $year->isClosed()) {
                throw new DomainException('Only a closed fiscal year can be reopened.');
            }

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

            $year->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

            $this->audit->log('fiscal_year.reopened', $year, ['status' => 'closed'], [
                'status' => 'open',
                'reason' => $reason,
                'reversed_entries' => $closings->count(),
            ], $actorId);

            return $year->refresh();
        });
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
    private function check(string $code, bool $passed, ?string $message = null, ?int $count = null): array
    {
        return ['code' => $code, 'passed' => $passed, 'message' => $passed ? null : $message, 'count' => $count];
    }
}
