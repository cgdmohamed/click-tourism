<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only ledger integrity check, used by the release gate before and after
 * a backup/restore or an upgrade. It uses raw queries on the journal tables
 * only, so it also runs against a schema one release behind.
 *
 * Exit code 0 = every check passed; 1 = at least one check failed.
 * `--json` prints a machine-readable report whose `fingerprint` section must
 * be identical before and after a restore or an upgrade.
 */
class VerifyLedgerCommand extends Command
{
    protected $signature = 'erp:verify-ledger
        {--json : Print the report as JSON}
        {--connection= : Database connection to check (default: the application default)}';

    protected $description = 'Verify journal entries are balanced, the trial balance is zero and no line is orphaned (read-only)';

    private const SAMPLE_LIMIT = 10;

    public function handle(): int
    {
        $db = DB::connection($this->option('connection') ?: null);

        $checks = [
            'entries_balanced_base' => $this->unbalancedBaseEntries($db),
            'entries_balanced_transaction_currency' => $this->unbalancedSingleCurrencyEntries($db),
            'trial_balance_zero' => $this->trialBalanceDifference($db),
            'no_orphan_lines' => $this->orphanLines($db),
            'no_empty_posted_entries' => $this->emptyPostedEntries($db),
            'no_invalid_lines' => $this->invalidLines($db),
            'no_dangling_reversals' => $this->danglingReversals($db),
        ];

        $failed = array_keys(array_filter($checks, static fn (array $check): bool => ! $check['passed']));
        $report = [
            'passed' => $failed === [],
            'failed_checks' => $failed,
            'checks' => $checks,
            'fingerprint' => $this->fingerprint($db),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $name => $check) {
                $line = sprintf('%-40s %s', $name, $check['passed'] ? 'PASS' : 'FAIL');
                $check['passed'] ? $this->line($line) : $this->error($line.' - '.$check['detail']);
            }
            $print = $report['fingerprint'];
            $this->line(sprintf(
                'entries=%d lines=%d debit=%s credit=%s accounts_digest=%s',
                $print['entries'], $print['lines'], $print['base_debit_minor'], $print['base_credit_minor'], substr($print['accounts_digest'], 0, 16),
            ));
            $report['passed'] ? $this->info('LEDGER OK') : $this->error('LEDGER VERIFICATION FAILED');
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{passed: bool, detail: string, samples: list<mixed>} */
    private function result(int $count, string $what, array $samples = []): array
    {
        return [
            'passed' => $count === 0,
            'detail' => $count === 0 ? 'none' : "{$count} {$what}",
            'samples' => array_slice($samples, 0, self::SAMPLE_LIMIT),
        ];
    }

    private function unbalancedBaseEntries(ConnectionInterface $db): array
    {
        $rows = $db->table('journal_lines')
            ->selectRaw('journal_entry_id')
            ->groupBy('journal_entry_id')
            ->havingRaw('SUM(base_debit_minor) <> SUM(base_credit_minor)')
            ->orderBy('journal_entry_id')
            ->pluck('journal_entry_id')->all();

        return $this->result(count($rows), 'journal entries whose base debits differ from base credits', $rows);
    }

    /** Entries made of one currency only must also balance in that currency. */
    private function unbalancedSingleCurrencyEntries(ConnectionInterface $db): array
    {
        $rows = $db->table('journal_lines')
            ->selectRaw('journal_entry_id')
            ->groupBy('journal_entry_id')
            ->havingRaw('COUNT(DISTINCT currency_code) = 1 AND SUM(debit_minor) <> SUM(credit_minor)')
            ->orderBy('journal_entry_id')
            ->pluck('journal_entry_id')->all();

        return $this->result(count($rows), 'single-currency entries whose debits differ from credits', $rows);
    }

    /** Sum of all posted base debits minus credits must be exactly zero. */
    private function trialBalanceDifference(ConnectionInterface $db): array
    {
        $row = $db->table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as d, COALESCE(SUM(jl.base_credit_minor), 0) as c')
            ->first();

        $difference = (int) $row->d - (int) $row->c;
        $result = $this->result($difference === 0 ? 0 : 1, "trial balance out by {$difference} minor units");
        $result['samples'] = $difference === 0 ? [] : [$difference];

        return $result;
    }

    private function orphanLines(ConnectionInterface $db): array
    {
        $missing = [
            'entry' => $db->table('journal_lines as jl')->leftJoin('journal_entries as x', 'x.id', '=', 'jl.journal_entry_id')->whereNull('x.id'),
            'account' => $db->table('journal_lines as jl')->leftJoin('accounts as x', 'x.id', '=', 'jl.account_id')->whereNull('x.id'),
            'branch' => $db->table('journal_lines as jl')->leftJoin('branches as x', 'x.id', '=', 'jl.branch_id')->whereNull('x.id'),
        ];

        $total = 0;
        $samples = [];
        foreach ($missing as $kind => $query) {
            $ids = $query->orderBy('jl.id')->pluck('jl.id')->all();
            $total += count($ids);
            foreach (array_slice($ids, 0, self::SAMPLE_LIMIT) as $id) {
                $samples[] = "line {$id}: missing {$kind}";
            }
        }

        return $this->result($total, 'journal lines referencing a missing entry, account or branch', $samples);
    }

    private function emptyPostedEntries(ConnectionInterface $db): array
    {
        $ids = $db->table('journal_entries as je')
            ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->whereNull('jl.id')
            ->orderBy('je.id')->pluck('je.id')->all();

        return $this->result(count($ids), 'posted entries without lines', $ids);
    }

    private function invalidLines(ConnectionInterface $db): array
    {
        $ids = $db->table('journal_lines')
            ->where(function ($query): void {
                $query->where('debit_minor', '<', 0)->orWhere('credit_minor', '<', 0)
                    ->orWhere('base_debit_minor', '<', 0)->orWhere('base_credit_minor', '<', 0)
                    ->orWhereRaw('debit_minor > 0 AND credit_minor > 0')
                    ->orWhereRaw('base_debit_minor > 0 AND base_credit_minor > 0')
                    ->orWhereRaw('debit_minor = 0 AND credit_minor = 0 AND base_debit_minor = 0 AND base_credit_minor = 0');
            })->orderBy('id')->pluck('id')->all();

        return $this->result(count($ids), 'journal lines with negative, two-sided or all-zero amounts', $ids);
    }

    private function danglingReversals(ConnectionInterface $db): array
    {
        $ids = $db->table('journal_entries as je')
            ->leftJoin('journal_entries as original', 'original.id', '=', 'je.reversal_of_id')
            ->whereNotNull('je.reversal_of_id')
            ->whereNull('original.id')
            ->orderBy('je.id')->pluck('je.id')->all();

        return $this->result(count($ids), 'reversal entries pointing at a missing original', $ids);
    }

    /**
     * Content digest of the ledger. Identical before and after a restore or an
     * upgrade (ids, numbers, statuses and per-account balances).
     *
     * @return array<string, int|string>
     */
    private function fingerprint(ConnectionInterface $db): array
    {
        $totals = $db->table('journal_lines')
            ->selectRaw('COUNT(*) as lines, COALESCE(SUM(base_debit_minor), 0) as d, COALESCE(SUM(base_credit_minor), 0) as c')
            ->first();

        $accounts = $db->table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->groupBy('a.code')
            ->selectRaw('a.code as code, SUM(jl.base_debit_minor) as d, SUM(jl.base_credit_minor) as c')
            ->orderBy('a.code')->get()
            ->map(static fn ($row): string => "{$row->code}|{$row->d}|{$row->c}")->all();

        $entries = $db->table('journal_entries as je')
            ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->groupBy('je.id', 'je.public_id', 'je.number', 'je.status')
            ->selectRaw('je.public_id as public_id, je.number as number, je.status as status, COALESCE(SUM(jl.base_debit_minor), 0) as d, COALESCE(SUM(jl.base_credit_minor), 0) as c')
            ->orderBy('je.public_id')->get()
            ->map(static fn ($row): string => "{$row->public_id}|{$row->number}|{$row->status}|{$row->d}|{$row->c}")->all();

        return [
            'entries' => count($entries),
            'lines' => (int) $totals->lines,
            'base_debit_minor' => (string) $totals->d,
            'base_credit_minor' => (string) $totals->c,
            'accounts_digest' => hash('sha256', implode("\n", $accounts)),
            'entries_digest' => hash('sha256', implode("\n", $entries)),
        ];
    }
}
