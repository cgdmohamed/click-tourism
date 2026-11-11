<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FxRevaluationRun;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Period-end unrealized FX revaluation (DEC-008).
 *
 * Foreign-currency balances of receivables, payables, customer/supplier
 * advances and cash/bank accounts are revalued at the closing rate. The native
 * balance is the sum of the lines in that currency (history is never converted
 * twice); only the base carrying amount is adjusted, through base-only lines
 * with a zero transaction amount. The entry is reversed on the first day of
 * the next period so realized differences are not double counted.
 */
class RevaluationService
{
    public const SOURCE_TYPE = 'fx_revaluation';

    public function __construct(
        private readonly SettingService $settings,
        private readonly PostingService $posting,
        private readonly FxService $fx,
        private readonly AuditService $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('accounting', 'fx_revaluation_enabled', false);
    }

    public function policyApproved(): bool
    {
        return $this->settings->bool('accounting', 'accounting_policy_approved', false);
    }

    public function autoReverse(): bool
    {
        return $this->settings->bool('accounting', 'fx_revaluation_auto_reverse', true);
    }

    /**
     * Revaluation rows for a branch at a period end date. Rows with a zero
     * difference are omitted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(string $endDate, Branch $branch): array
    {
        $base = $this->settings->baseCurrency();
        $ids = $this->eligibleAccountIds();
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $endDate)
            ->where('jl.branch_id', $branch->id)
            ->whereIn('jl.account_id', $ids)
            ->where('jl.currency_code', '!=', $base)
            ->groupBy('jl.account_id', 'jl.currency_code', 'jl.party_type', 'jl.party_id')
            ->orderBy('jl.account_id')
            ->orderBy('jl.currency_code')
            ->selectRaw('jl.account_id, jl.currency_code, jl.party_type, jl.party_id,
                COALESCE(SUM(jl.debit_minor - jl.credit_minor),0) as native_minor,
                COALESCE(SUM(jl.base_debit_minor - jl.base_credit_minor),0) as carrying_minor')
            ->get();

        $accounts = Account::query()->whereIn('id', $rows->pluck('account_id')->unique())->get()->keyBy('id');
        $rates = [];
        $result = [];

        foreach ($rows as $row) {
            $currency = strtoupper((string) $row->currency_code);
            $rates[$currency] ??= $this->fx->rateFor($currency, $endDate, $base, 'any');
            $native = (int) $row->native_minor;
            $carrying = (int) $row->carrying_minor;
            $revalued = (new Money($native, $currency))->convertTo($base, $rates[$currency])->amountMinor;
            $difference = $revalued - $carrying;

            if ($difference === 0) {
                continue;
            }

            /** @var Account $account */
            $account = $accounts[$row->account_id];
            $result[] = [
                'account' => $account,
                'account_code' => $account->code,
                'account_name' => $account->name_en,
                'currency' => $currency,
                'party_type' => $row->party_type,
                'party_id' => $row->party_id !== null ? (int) $row->party_id : null,
                'rate' => $rates[$currency],
                'native_minor' => $native,
                'carrying_base_minor' => $carrying,
                'revalued_base_minor' => $revalued,
                'difference_minor' => $difference,
            ];
        }

        return $result;
    }

    /**
     * Post (once) the revaluation of a period for a branch.
     *
     * @return array{status: string, entry: ?JournalEntry, reversal: ?JournalEntry, rows: int}
     */
    public function revalue(FiscalPeriod $period, Branch $branch, ?int $actorId = null): array
    {
        $this->assertAllowed();

        $existing = FxRevaluationRun::query()
            ->where('fiscal_period_id', $period->id)->where('branch_id', $branch->id)->first();
        if ($existing) {
            $existing = $this->syncRun($existing);
            if ($existing->status !== FxRevaluationRun::STATUS_REVERSED) {
                return $this->outcome('already_posted', $existing, 0);
            }
        }

        // An earlier revaluation whose reversal is still pending would be
        // counted as carrying value and then reversed later: block it.
        $blocking = FxRevaluationRun::query()
            ->where('branch_id', $branch->id)
            ->where('status', FxRevaluationRun::STATUS_REVERSAL_PENDING)
            ->where('fiscal_period_id', '!=', $period->id)
            ->whereHas('period', fn ($query) => $query->whereDate('ends_on', '<', $period->ends_on->toDateString()))
            ->exists();
        if ($blocking) {
            throw new DomainException('A previous revaluation reversal is still pending for this branch; post it before revaluing this period.');
        }

        $end = $period->ends_on->toDateString();
        $rows = $this->preview($end, $branch);
        if ($rows === []) {
            return ['status' => 'nothing_to_revalue', 'entry' => null, 'reversal' => null, 'rows' => 0];
        }

        $base = $this->settings->baseCurrency();
        $lines = [];
        $gain = 0;
        $loss = 0;
        foreach ($rows as $row) {
            $d = $row['difference_minor'];
            $lines[] = PostingLine::baseOnly(
                $row['account'],
                $d > 0 ? $d : 0,
                $d < 0 ? -$d : 0,
                $row['currency'],
                $row['rate'],
                $row['party_type'],
                $row['party_id'],
                "Unrealized FX revaluation {$row['currency']} @ {$row['rate']}",
            );
            $d > 0 ? $gain += $d : $loss += -$d;
        }
        if ($gain > 0) {
            $lines[] = PostingLine::baseOnly(
                $this->fxAccount('fx_unrealized_gain_account_code', '7901'),
                0, $gain, $base, '1', null, null, 'Unrealized FX gain',
            );
        }
        if ($loss > 0) {
            $lines[] = PostingLine::baseOnly(
                $this->fxAccount('fx_unrealized_loss_account_code', '6901'),
                $loss, 0, $base, '1', null, null, 'Unrealized FX loss',
            );
        }

        $attempt = $existing ? $existing->attempt + 1 : 1;
        $suffix = $attempt > 1 ? ":{$attempt}" : '';

        return DB::transaction(function () use ($period, $branch, $actorId, $lines, $end, $suffix, $attempt, $existing, $rows) {
            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $end,
                branch: $branch,
                currency: $this->settings->baseCurrency(),
                lines: $lines,
                memo: "Unrealized FX revaluation {$period->name}",
                sourceType: self::SOURCE_TYPE,
                sourceId: $period->id,
                event: "reval:{$branch->id}{$suffix}",
                fxRate: '1',
                fxRateDate: $end,
                fxRateSource: 'revaluation',
                actorId: $actorId,
            ));

            $status = $this->autoReverse()
                ? FxRevaluationRun::STATUS_REVERSAL_PENDING
                : FxRevaluationRun::STATUS_POSTED;

            $payload = [
                'revaluation_entry_id' => $entry->id,
                'reversal_entry_id' => null,
                'attempt' => $attempt,
                'status' => $status,
                'run_by' => $actorId,
            ];
            $run = $existing
                ? tap($existing)->update($payload)
                : FxRevaluationRun::query()->create([
                    'fiscal_period_id' => $period->id,
                    'branch_id' => $branch->id,
                ] + $payload);

            $this->audit->log('fx_revaluation.posted', $run, null, [
                'period' => $period->name,
                'branch_id' => $branch->id,
                'entry' => $entry->number,
                'accounts' => count($rows),
                'auto_reverse' => $status === FxRevaluationRun::STATUS_REVERSAL_PENDING,
            ], $actorId);

            $reversal = null;
            if ($status === FxRevaluationRun::STATUS_REVERSAL_PENDING) {
                $reversal = $this->postReversalIfDue($run, $actorId);
            }

            return [
                'status' => 'posted',
                'entry' => $entry,
                'reversal' => $reversal,
                'rows' => count($rows),
            ];
        });
    }

    /**
     * Post every pending automatic reversal whose date has come and whose
     * period is open. Returns the reversal entries posted.
     *
     * @return array<int, JournalEntry>
     */
    public function postPendingReversals(?int $actorId = null): array
    {
        $this->assertAllowed();

        $posted = [];
        $runs = FxRevaluationRun::query()
            ->where('status', FxRevaluationRun::STATUS_REVERSAL_PENDING)
            ->with('period')->orderBy('id')->get();

        foreach ($runs as $run) {
            $run = $this->syncRun($run);
            if ($run->status !== FxRevaluationRun::STATUS_REVERSAL_PENDING) {
                continue;
            }
            $entry = $this->postReversalIfDue($run, $actorId);
            if ($entry) {
                $posted[] = $entry;
            }
        }

        return $posted;
    }

    /** Reversal date: first day of the period that follows the revalued one. */
    public function reversalDate(FiscalPeriod $period): string
    {
        return $period->ends_on->copy()->addDay()->toDateString();
    }

    public function runFor(FiscalPeriod $period, Branch $branch): ?FxRevaluationRun
    {
        return FxRevaluationRun::query()
            ->where('fiscal_period_id', $period->id)->where('branch_id', $branch->id)->first();
    }

    private function postReversalIfDue(FxRevaluationRun $run, ?int $actorId): ?JournalEntry
    {
        $period = $run->period ?? FiscalPeriod::query()->findOrFail($run->fiscal_period_id);
        $date = $this->reversalDate($period);

        if (Carbon::today()->toDateString() < $date) {
            return null;
        }

        $next = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
        if (! $next || $next->status !== 'open') {
            return null; // stays pending until the next period exists and is open
        }

        $entry = JournalEntry::query()->findOrFail($run->revaluation_entry_id);
        $suffix = $run->attempt > 1 ? ":{$run->attempt}" : '';
        $reversal = $this->posting->reverse(
            $entry,
            "FX revaluation {$period->name} auto-reversal",
            $actorId,
            $date,
            "reval_reversal:{$run->branch_id}{$suffix}",
        );

        $run->update([
            'reversal_entry_id' => $reversal->id,
            'status' => FxRevaluationRun::STATUS_REVERSED,
        ]);
        $this->audit->log('fx_revaluation.reversed', $run, null, [
            'reversal' => $reversal->number,
            'reversal_date' => $date,
        ], $actorId);

        return $reversal;
    }

    /** Pick up a reversal posted manually so the run reflects the ledger. */
    private function syncRun(FxRevaluationRun $run): FxRevaluationRun
    {
        if ($run->status === FxRevaluationRun::STATUS_REVERSED) {
            return $run;
        }
        $reversal = JournalEntry::query()->where('reversal_of_id', $run->revaluation_entry_id)->first();
        if ($reversal) {
            $run->update(['reversal_entry_id' => $reversal->id, 'status' => FxRevaluationRun::STATUS_REVERSED]);
        }

        return $run;
    }

    /** @return array{status: string, entry: ?JournalEntry, reversal: ?JournalEntry, rows: int} */
    private function outcome(string $status, FxRevaluationRun $run, int $rows): array
    {
        return [
            'status' => $status,
            'entry' => JournalEntry::query()->find($run->revaluation_entry_id),
            'reversal' => $run->reversal_entry_id ? JournalEntry::query()->find($run->reversal_entry_id) : null,
            'rows' => $rows,
        ];
    }

    private function assertAllowed(): void
    {
        if (! $this->enabled()) {
            throw new DomainException('FX revaluation is disabled (accounting.fx_revaluation_enabled).');
        }
        if (! $this->policyApproved()) {
            throw new DomainException('FX revaluation requires the accounting policy to be approved by an accountant.');
        }
    }

    private function fxAccount(string $setting, string $default): Account
    {
        $code = (string) $this->settings->get('accounting', $setting, $default);
        $account = Account::query()->where('code', $code)->first();
        if (! $account) {
            throw new DomainException("FX revaluation account {$code} does not exist. Create or import it in the chart of accounts (or run `php artisan accounting:ensure-fx-accounts`).");
        }

        return $account;
    }

    /** @return array<int, int> */
    private function eligibleAccountIds(): array
    {
        $codes = array_unique([
            (string) $this->settings->get('accounting', 'ar_account_code', '1100'),
            (string) $this->settings->get('accounting', 'ap_account_code', '2100'),
            (string) $this->settings->get('accounting', 'advance_account_code', '2300'),
            (string) $this->settings->get('accounting', 'supplier_advance_account_code', '1150'),
        ]);

        $ids = DB::table('cash_accounts')->pluck('account_id')->map(fn ($id) => (int) $id)->all();
        $frontier = Account::query()->whereIn('code', $codes)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_merge($ids, $frontier);
        while ($frontier !== []) {
            $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = array_merge($ids, $frontier);
        }

        return array_values(array_unique($ids));
    }
}
