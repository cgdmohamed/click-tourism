<?php

namespace App\Console\Commands;

use App\Modules\Reports\Services\ReportScheduleService;
use Illuminate\Console\Command;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'E-mail the scheduled reports that are due (ADR-0026)';

    public function handle(ReportScheduleService $schedules): int
    {
        $this->info('Schedules processed: '.$schedules->runDue());

        return self::SUCCESS;
    }
}
