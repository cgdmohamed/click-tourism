<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Services\OpeningBalanceBackfillService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings opening-balance batches posted before party subaccounts and opening
 * documents existed up to date. Dry-run by default; --apply writes. Idempotent.
 */
class BackfillOpeningDocuments extends Command
{
    protected $signature = 'accounting:backfill-opening-documents
        {--dry-run : Show what would change without writing (default)}
        {--apply : Post the reclassification entries and create the opening documents}
        {--branch= : Only batches of this branch code}
        {--limit= : Process at most this many batches}';

    protected $description = 'Move legacy opening party balances to party subaccounts/advance accounts and create their opening documents';

    public function handle(OpeningBalanceBackfillService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $result = $service->run(
                $apply,
                $this->option('branch') !== null ? (string) $this->option('branch') : null,
                $limit !== null ? (int) $limit : null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($apply ? 'APPLIED' : 'DRY RUN (nothing written)');

        $rows = [];
        foreach ($result['batches'] as $batch) {
            foreach ($batch['lines'] as $line) {
                $rows[] = [
                    $batch['id'], $batch['branch'], $batch['entry_date'], $line['party'], $line['currency'],
                    $line['side'], $line['amount_minor'], $line['kind'], $line['from'].' > '.$line['to'], $line['record'],
                ];
            }
        }
        $this->table(['Batch', 'Branch', 'Entry date', 'Party', 'Currency', 'Side', 'Amount (minor)', 'Kind', 'Move', 'Record'], $rows);

        $this->table(['Item', 'Detail'], [
            ['Batches '.($apply ? 'updated' : 'to update'), count($result['batches'])],
            ['Reclassification entries', $apply ? (implode(', ', $result['entries']) ?: '-') : 'on apply'],
            ['Opening records '.($apply ? 'created' : 'to create'), $result['records']],
        ]);

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
