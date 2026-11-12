<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Money\Services\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class VerifyLedgerCommandTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function postEntry(string $currency = 'EGP', string $rate = '1', ?array $lines = null, int $sourceId = 1): void
    {
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: Carbon::now()->toDateString(),
            branch: $this->branch,
            currency: $currency,
            lines: $lines ?? [
                PostingLine::debit($this->account('1110'), 100000),
                PostingLine::credit($this->account('4100'), 100000),
            ],
            memo: 'Ledger check',
            sourceType: 'test',
            sourceId: $sourceId,
            event: 'sale',
            fxRate: $rate,
            fxRateDate: Carbon::now()->toDateString(),
            fxRateSource: 'manual',
            actorId: $this->user->id,
        ));
    }

    /** Lets a test write rows that violate foreign keys (rolled back with the test transaction). */
    private function allowDanglingReferences(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Dangling-reference fixtures are only built on SQLite.');
        }

        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        Artisan::call('erp:verify-ledger', ['--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function insertRawEntry(array $lines, string $status = 'posted'): int
    {
        $period = FiscalPeriod::query()->firstOrFail();
        $entryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'number' => 'RAW-'.random_int(1000, 999999),
            'entry_date' => Carbon::now()->toDateString(),
            'branch_id' => $this->branch->id,
            'fiscal_period_id' => $period->id,
            'currency_code' => 'EGP',
            'fx_rate_date' => Carbon::now()->toDateString(),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as [$account, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'journal_entry_id' => $entryId,
                'account_id' => $this->account($account)->id,
                'branch_id' => $this->branch->id,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'base_debit_minor' => $debit,
                'base_credit_minor' => $credit,
                'currency_code' => 'EGP',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $entryId;
    }

    public function test_empty_ledger_passes(): void
    {
        $this->artisan('erp:verify-ledger')->expectsOutputToContain('LEDGER OK')->assertSuccessful();
    }

    public function test_balanced_entries_including_fx_rounding_pass_and_fingerprint_is_stable(): void
    {
        $this->postEntry();
        app(FxService::class)->upsertRate('USD', '48.5', Carbon::now()->toDateString());
        $this->postEntry('USD', '48.5', [
            PostingLine::debit($this->account('1110'), 10000),
            PostingLine::credit($this->account('4100'), 3333),
            PostingLine::credit($this->account('4100'), 3333),
            PostingLine::credit($this->account('4100'), 3334),
        ], 2);

        $first = $this->report();
        $this->assertTrue($first['passed'], json_encode($first['checks']));
        $this->assertSame(2, $first['fingerprint']['entries']);
        $this->assertSame($first['fingerprint']['base_debit_minor'], $first['fingerprint']['base_credit_minor']);
        $this->assertSame($first['fingerprint'], $this->report()['fingerprint']);
    }

    public function test_fingerprint_changes_when_ledger_content_changes(): void
    {
        $this->postEntry();
        $before = $this->report()['fingerprint'];
        $this->postEntry(sourceId: 2);

        $this->assertNotSame($before['accounts_digest'], $this->report()['fingerprint']['accounts_digest']);
    }

    public function test_unbalanced_entry_fails_with_nonzero_exit(): void
    {
        $this->postEntry();
        $entryId = $this->insertRawEntry([['1110', 5000, 0], ['4100', 0, 4000]]);

        $report = $this->report();
        $this->assertFalse($report['passed']);
        $this->assertContains('entries_balanced_base', $report['failed_checks']);
        $this->assertContains('entries_balanced_transaction_currency', $report['failed_checks']);
        $this->assertContains('trial_balance_zero', $report['failed_checks']);
        $this->assertSame([$entryId], $report['checks']['entries_balanced_base']['samples']);
        $this->artisan('erp:verify-ledger')->assertFailed();
    }

    public function test_orphan_lines_are_detected(): void
    {
        $this->postEntry();
        $this->allowDanglingReferences();
        DB::table('journal_lines')->insert([
            'journal_entry_id' => 987654,
            'account_id' => $this->account('1110')->id,
            'branch_id' => $this->branch->id,
            'debit_minor' => 100, 'credit_minor' => 0, 'base_debit_minor' => 100, 'base_credit_minor' => 0,
            'currency_code' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->report();
        $this->assertContains('no_orphan_lines', $report['failed_checks']);
        $this->assertStringContainsString('missing entry', $report['checks']['no_orphan_lines']['samples'][0]);
    }

    public function test_empty_posted_entry_and_invalid_lines_are_detected(): void
    {
        $this->insertRawEntry([]);
        $this->insertRawEntry([['1110', 100, 100], ['4100', 0, 0]]);

        $report = $this->report();
        $this->assertContains('no_empty_posted_entries', $report['failed_checks']);
        $this->assertContains('no_invalid_lines', $report['failed_checks']);
    }

    public function test_dangling_reversal_is_detected(): void
    {
        $this->postEntry();
        $this->allowDanglingReferences();
        DB::table('journal_entries')->update(['reversal_of_id' => 424242]);

        $this->assertContains('no_dangling_reversals', $this->report()['failed_checks']);
    }

    public function test_command_is_read_only(): void
    {
        $this->postEntry();
        $counts = [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('audit_events')->count()];
        $this->report();

        $this->assertSame($counts, [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('audit_events')->count()]);
    }
}
