<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Services\Account1150SplitService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Migrate an existing install from the shared account 1150 to 1151-1154.
 * Dry-run by default; --apply writes. Idempotent.
 */
class Split1150Account extends Command
{
    protected $signature = 'accounting:split-1150
        {--dry-run : Show what would change without writing (default)}
        {--apply : Create accounts, post reclassification entries and update settings}
        {--actor= : User id recorded on the postings and audit event}';

    protected $description = 'Split account 1150 into employee advances, custody, supplier advances and prepaid expenses';

    public function handle(Account1150SplitService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        try {
            $result = $service->run($apply, $this->option('actor') !== null ? (int) $this->option('actor') : null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($apply ? 'APPLIED' : 'DRY RUN (nothing written)');

        $this->table(
            ['Branch', 'Currency', 'Party', 'To', 'Net (minor)', 'Base net (minor)'],
            array_map(fn ($m) => [
                $m['branch_id'], $m['currency'],
                $m['party_type'] ? $m['party_type'].'#'.$m['party_id'] : '-',
                $m['target'], $m['net_minor'], $m['base_net_minor'],
            ], $result['moves']),
        );

        $settings = [];
        foreach ($result['settings'] as $key => $value) {
            $settings[] = "{$key}={$value}";
        }

        $this->table(['Item', 'Detail'], [
            ['Accounts '.($apply ? 'created' : 'to create'), implode(', ', $apply ? $result['created_accounts'] : $result['missing_accounts']) ?: '-'],
            ['Reclassification entries', $apply ? (implode(', ', $result['entries']) ?: '-') : count($result['moves']).' line group(s)'],
            ['Settings '.($apply ? 'updated' : 'to update'), implode(', ', $settings) ?: '-'],
            ['1150 made non-postable', $apply ? ($result['made_non_postable'] ? 'yes' : 'no') : 'on apply, if no blockers'],
            ['Blockers for non-postable', implode('; ', $result['non_postable_blockers']) ?: '-'],
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
