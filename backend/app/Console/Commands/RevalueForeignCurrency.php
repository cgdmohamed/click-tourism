<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\RevaluationService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Support\Money;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Period-end unrealized FX revaluation (DEC-008). Dry-run by default; --apply
 * posts the entry (and its automatic reversal when due). Both are no-ops
 * unless the setting is enabled and the accounting policy is approved.
 */
class RevalueForeignCurrency extends Command
{
    protected $signature = 'accounting:revalue
        {--period= : Fiscal month YYYY-MM (defaults to the current month)}
        {--branch= : Branch code (defaults to all active branches)}
        {--dry-run : Show the revaluation without posting (default)}
        {--apply : Post the revaluation entry}
        {--reversals-only : Only post pending automatic reversals}
        {--actor= : User id recorded as the poster}';

    protected $description = 'Revalue foreign-currency balances at the period-end closing rate';

    public function handle(RevaluationService $service): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $actor = $this->option('actor') !== null ? (int) $this->option('actor') : null;

        if ($apply && (! $service->enabled() || ! $service->policyApproved())) {
            $this->info('FX revaluation is disabled or the accounting policy is not approved; nothing posted.');

            return self::SUCCESS;
        }

        $failed = false;

        if ($apply) {
            try {
                foreach ($service->postPendingReversals($actor) as $reversal) {
                    $this->info("Posted revaluation reversal {$reversal->number} dated {$reversal->entry_date->toDateString()}.");
                }
            } catch (DomainException $e) {
                $this->error($e->getMessage());
                $failed = true;
            }
            if ($this->option('reversals-only')) {
                return $failed ? self::FAILURE : self::SUCCESS;
            }
        }

        try {
            $period = $this->period();
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $branches = Branch::query()->where('is_active', true)
            ->when($this->option('branch'), fn ($query, $code) => $query->where('code', $code))
            ->orderBy('id')->get();
        if ($branches->isEmpty()) {
            $this->error('No matching branch.');

            return self::FAILURE;
        }

        $base = app(SettingService::class)->baseCurrency();
        foreach ($branches as $branch) {
            $this->line("Branch {$branch->code} — period {$period->name} (".$period->ends_on->toDateString().')');
            try {
                $rows = $service->preview($period->ends_on->toDateString(), $branch);
                $this->table(
                    ['Account', 'Currency', 'Rate', 'Native balance', "Carrying {$base}", "Revalued {$base}", 'Difference'],
                    array_map(fn (array $r) => [
                        $r['account_code'].' '.$r['account_name'],
                        $r['currency'],
                        $r['rate'],
                        (new Money($r['native_minor'], $r['currency']))->toDecimal(),
                        (new Money($r['carrying_base_minor'], $base))->toDecimal(),
                        (new Money($r['revalued_base_minor'], $base))->toDecimal(),
                        (new Money($r['difference_minor'], $base))->toDecimal(),
                    ], $rows),
                );
                if (! $apply) {
                    continue;
                }
                $result = $service->revalue($period, $branch, $actor);
                $this->info("Branch {$branch->code}: {$result['status']}".($result['entry'] ? " ({$result['entry']->number})" : '').'.');
            } catch (DomainException $e) {
                $this->error("Branch {$branch->code}: ".$e->getMessage());
                $failed = true;
            }
        }

        if (! $apply) {
            $this->comment('Dry run only. Re-run with --apply to post.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function period(): FiscalPeriod
    {
        $option = $this->option('period');
        $month = $option ? Carbon::createFromFormat('!Y-m', (string) $option) : Carbon::today();
        if ($month === false) {
            throw new DomainException('Use --period=YYYY-MM.');
        }
        $date = $option ? $month->copy()->endOfMonth()->toDateString() : $month->toDateString();

        $period = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
        if (! $period) {
            throw new DomainException("No fiscal period covers {$date}.");
        }

        return $period;
    }
}
