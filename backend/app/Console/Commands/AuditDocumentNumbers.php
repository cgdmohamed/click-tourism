<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit that runs before a unique index on document numbers can be
 * added to the tables that still lack one.
 *
 * The document sequence already locks a per-branch row, so new numbers are
 * unique; the risk is legacy rows written before the branch code was part of
 * the number. This command only reports: it never deletes, merges, or
 * renumbers anything, because renaming a posted document number is an
 * accounting decision.
 *
 * Tables that already have a database uniqueness guarantee are skipped:
 * bookings, payments, invoices (`branch_id, series, number`),
 * accounting_schedules and fixed_asset_depreciation_runs.
 */
class AuditDocumentNumbers extends Command
{
    protected $signature = 'accounting:audit-document-numbers
        {--json : Print a machine-readable report}';

    protected $description = 'Report duplicate document numbers before unique indexes are added (read-only)';

    /** Table => human label. Every audited table has a branch_id column. */
    private const TABLES = [
        'journal_entries' => 'Journal entries',
        'refunds' => 'Refunds',
        'transfers' => 'Transfers',
        'expenses' => 'Expenses',
        'accounting_documents' => 'Accounting documents',
    ];

    public function handle(): int
    {
        $report = [];
        $duplicatesFound = 0;

        foreach (self::TABLES as $table => $label) {
            $groups = DB::table($table)
                ->select('branch_id', 'number', DB::raw('COUNT(*) as occurrences'))
                ->groupBy('branch_id', 'number')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('branch_id')
                ->orderBy('number')
                ->get();

            $duplicates = [];
            foreach ($groups as $group) {
                $duplicates[] = [
                    'branch_id' => (int) $group->branch_id,
                    'number' => (string) $group->number,
                    'occurrences' => (int) $group->occurrences,
                    'ids' => DB::table($table)
                        ->where('branch_id', $group->branch_id)
                        ->where('number', $group->number)
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all(),
                ];
            }

            $duplicatesFound += count($duplicates);
            $report[] = [
                'table' => $table,
                'label' => $label,
                'rows' => DB::table($table)->count(),
                'duplicate_groups' => $duplicates,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'duplicates_found' => $duplicatesFound,
                'tables' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderText($report, $duplicatesFound);
        }

        return $duplicatesFound === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<int, array<string, mixed>>  $report */
    private function renderText(array $report, int $duplicatesFound): void
    {
        $this->info('Document number audit (read-only; nothing is changed).');

        foreach ($report as $table) {
            $count = count($table['duplicate_groups']);
            $this->line(sprintf(
                '%s (%s): %d rows, %s.',
                $table['label'],
                $table['table'],
                $table['rows'],
                $count === 0
                    ? 'no duplicate (branch, number) pairs'
                    : $count.' duplicate (branch, number) '.($count === 1 ? 'pair' : 'pairs'),
            ));

            foreach ($table['duplicate_groups'] as $duplicate) {
                $this->warn(sprintf(
                    '  branch #%d number %s appears %d times (ids: %s).',
                    $duplicate['branch_id'],
                    $duplicate['number'],
                    $duplicate['occurrences'],
                    implode(', ', $duplicate['ids']),
                ));
            }
        }

        if ($duplicatesFound === 0) {
            $this->comment('No duplicates. A unique index on (branch_id, number) can be added to these tables.');

            return;
        }

        $this->warn(sprintf(
            '%d duplicate group(s) must be resolved by an accountant before a unique index is added. '
            .'This command never renumbers posted documents.',
            $duplicatesFound,
        ));
    }
}
