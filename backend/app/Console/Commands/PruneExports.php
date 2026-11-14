<?php

namespace App\Console\Commands;

use App\Modules\Reports\Services\ExportJobService;
use Illuminate\Console\Command;

class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete background export files past their expiry (ADR-0025)';

    public function handle(ExportJobService $exports): int
    {
        $this->info('Exports deleted: '.$exports->prune());

        return self::SUCCESS;
    }
}
