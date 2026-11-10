<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Services\AccountingScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Post every accrual/prepayment event that is due (Prompt 24).
 *
 * Safe to run repeatedly: posted events are skipped and the ledger rejects a
 * duplicate idempotency key. Events in closed periods are recorded in the
 * exception queue and retried from the UI after the period is reopened.
 */
class PostDueSchedules extends Command
{
    protected $signature = 'schedules:post-due
        {--date= : Post events due on or before this date (Y-m-d, defaults to today)}
        {--actor= : User id recorded as the poster}';

    protected $description = 'Post due accrual, prepayment and deferred revenue schedule events';

    public function handle(AccountingScheduleService $schedules): int
    {
        $asOf = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : Carbon::today();

        $actor = $this->option('actor') !== null ? (int) $this->option('actor') : null;

        $result = $schedules->runDue($actor, $asOf);

        $this->info(sprintf(
            'Schedules as of %s: %d processed, %d posted, %d failed.',
            $result['as_of'],
            $result['processed'],
            $result['posted'],
            $result['failed'],
        ));

        foreach ($result['results'] as $row) {
            if (! $row['posted']) {
                $this->warn(sprintf(
                    '%s %s due %s: %s',
                    $row['schedule_id'],
                    $row['kind'],
                    $row['due_date'] ?? '—',
                    $row['reason'] ?? 'failed',
                ));
            }
        }

        return self::SUCCESS;
    }
}
