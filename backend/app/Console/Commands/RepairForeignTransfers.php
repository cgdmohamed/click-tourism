<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Treasury\Models\Transfer;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repair cross-currency transfers posted before each cash account was posted
 * in its own currency. The original entry is reversed and a correct
 * replacement is posted (immutable ledger: corrections are reversals).
 *
 * Dry-run by default; pass --apply to write. Safe to rerun: repaired
 * transfers are skipped.
 */
class RepairForeignTransfers extends Command
{
    protected $signature = 'accounting:repair-foreign-transfers
        {--dry-run : Show what would change without writing (default)}
        {--apply : Write the repairs}
        {--branch= : Only transfers of this branch code}
        {--limit= : Repair at most this many transfers}';

    protected $description = 'Repair legacy cross-currency transfers posted entirely in the base currency';

    public function handle(TreasuryService $treasury, SettingService $settings): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        if (! $settings->bool('accounting', 'accounting_policy_approved', false)) {
            $this->error('Refusing to run: the accounting policy has not been approved by an accountant (accounting_policy_approved is false).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        $branchId = null;
        if ($this->option('branch') !== null) {
            $branch = Branch::query()->where('code', (string) $this->option('branch'))->first();
            if (! $branch) {
                $this->error('Unknown branch code: '.$this->option('branch'));

                return self::FAILURE;
            }
            $branchId = $branch->id;
        }

        $candidates = collect();
        Transfer::query()
            ->with('from.account', 'to.account', 'branch')
            ->where('status', 'posted')
            ->whereNull('repaired_at')
            ->whereColumn('from_currency', '!=', 'to_currency')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($treasury, &$candidates, $limit) {
                foreach ($chunk as $transfer) {
                    if ($treasury->hasLegacyForeignShape($transfer)) {
                        $candidates->push($transfer);
                    }
                    if ($limit !== null && $candidates->count() >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        if ($limit !== null) {
            $candidates = $candidates->take($limit);
        }

        if ($candidates->isEmpty()) {
            $this->info('No legacy cross-currency transfers need repair.');

            return self::SUCCESS;
        }

        $this->table(
            ['Transfer', 'Date', 'From', 'Out', 'To', 'In', 'Fee'],
            $candidates->map(fn (Transfer $t) => [
                $t->number,
                $t->transfer_date->toDateString(),
                $t->from->account->code,
                $t->from_amount_minor.' '.$t->from_currency,
                $t->to->account->code,
                $t->to_amount_minor.' '.$t->to_currency,
                $t->fee_minor,
            ])->all(),
        );

        $before = $this->balances($candidates);
        $projected = $this->projected($candidates, $treasury, $settings, $before);

        if (! $apply) {
            $this->info('Dry run: balances per account and currency (minor units).');
            $this->printBalances($before, $projected);
            $this->info(sprintf('%d transfer(s) would be repaired. Rerun with --apply to write.', $candidates->count()));

            return self::SUCCESS;
        }

        $repaired = 0;
        $failed = 0;
        foreach ($candidates as $transfer) {
            try {
                $entry = $treasury->repairForeignTransfer($transfer, null);
                if ($entry) {
                    $repaired++;
                    $this->line("Repaired {$transfer->number} -> {$entry->number}");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Failed {$transfer->number}: {$e->getMessage()}");
            }
        }

        $this->info('Balances per account and currency (minor units).');
        $this->printBalances($before, $this->balances($candidates));
        $this->info(sprintf('Summary: %d candidate(s), %d repaired, %d failed.', $candidates->count(), $repaired, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Posted per-currency balances of every cash account touched by the transfers.
     *
     * @return array<string, int> keyed "accountId|currency"
     */
    private function balances($transfers): array
    {
        $accountIds = $transfers->flatMap(fn (Transfer $t) => [$t->from->account_id, $t->to->account_id])->unique()->values()->all();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $accountIds)
            ->groupBy('jl.account_id', 'jl.currency_code')
            ->selectRaw('jl.account_id, jl.currency_code, COALESCE(SUM(jl.debit_minor), 0) as d, COALESCE(SUM(jl.credit_minor), 0) as c')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[$row->account_id.'|'.strtoupper((string) $row->currency_code)] = (int) $row->d - (int) $row->c;
        }

        return $balances;
    }

    /**
     * @param  array<string, int>  $before
     * @return array<string, int>
     */
    private function projected($transfers, TreasuryService $treasury, SettingService $settings, array $before): array
    {
        $after = $before;
        $base = $settings->baseCurrency();

        foreach ($transfers as $t) {
            $accountIds = [$t->from->account_id, $t->to->account_id];

            // The reversal swaps debit/credit of the original, removing the legacy
            // base-currency movement; the replacement adds the movement per currency.
            foreach (DB::table('journal_lines')->where('journal_entry_id', $t->journal_entry_id)->whereIn('account_id', $accountIds)->get() as $line) {
                $key = $line->account_id.'|'.strtoupper((string) $line->currency_code);
                $after[$key] -= (int) $line->debit_minor - (int) $line->credit_minor;
            }

            ['lines' => $lines] = $treasury->crossCurrencyLines(
                $t->from->account, $t->to->account, strtoupper($t->from_currency), strtoupper($t->to_currency),
                (int) $t->from_amount_minor, (int) $t->fee_minor, (int) $t->to_amount_minor,
                $base, (string) $t->from_fx_rate, (string) $t->to_fx_rate,
            );
            foreach ($lines as $line) {
                if (! in_array($line->account->id, $accountIds, true)) {
                    continue;
                }
                $key = $line->account->id.'|'.strtoupper((string) ($line->currency ?? $base));
                $after[$key] = ($after[$key] ?? 0) + $line->debitMinor - $line->creditMinor;
            }
        }

        return $after;
    }

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    private function printBalances(array $before, array $after): void
    {
        $codes = DB::table('accounts')->whereIn('id', array_unique(array_map(
            fn ($key) => (int) explode('|', $key)[0], array_keys($before + $after),
        )))->pluck('code', 'id');

        $keys = array_keys($before + $after);
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            [$accountId, $currency] = explode('|', $key);
            $b = $before[$key] ?? 0;
            $a = $after[$key] ?? 0;
            $rows[] = [$codes[(int) $accountId] ?? $accountId, $currency, $b, $a, $a - $b];
        }

        $this->table(['Account', 'Currency', 'Before', 'After', 'Change'], $rows);
    }
}
