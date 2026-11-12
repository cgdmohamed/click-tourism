<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\ImportRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Background execution of an import. Uses the default queue so the standard
 * `queue:work` worker picks it up. Never retried: the runner claims the job
 * atomically, so a redelivered message is a no-op and a failure is recorded
 * on the import job itself.
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $importJobId)
    {
        $this->timeout = (int) config('imports.job_timeout');
    }

    public int $timeout = 900;

    public function handle(ImportRunner $runner): void
    {
        $runner->run($this->importJobId);
    }

    public function failed(Throwable $exception): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job) {
            app(ImportRunner::class)->fail($job, 'worker_failed', 'The background worker stopped before the import finished.');
        }
    }
}
