<?php

namespace App\Jobs;

use App\Modules\Reports\Models\ExportJob;
use App\Modules\Reports\Services\ExportJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs one queued export (ADR-0025). A worker crash leaves the row `running`; the job is not retried. */
class RunReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $exportJobId)
    {
        $this->timeout = (int) config('exports.timeout');
    }

    public function handle(ExportJobService $exports): void
    {
        $job = ExportJob::query()->find($this->exportJobId);
        if ($job !== null) {
            $exports->run($job);
        }
    }

    public function failed(\Throwable $e): void
    {
        $job = ExportJob::query()->find($this->exportJobId);
        if ($job !== null && in_array($job->status, ['queued', 'running'], true)) {
            app(ExportJobService::class)->fail($job, 'The export stopped before it finished.');
        }
    }
}
