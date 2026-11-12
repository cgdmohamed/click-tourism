<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * `accounting:audit-document-numbers` is the prerequisite check for adding a
 * unique index on (branch_id, number) to the tables that do not have one yet.
 * It must never modify data.
 */
class DocumentNumberAuditTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->period = FiscalPeriod::query()->firstOrFail();
    }

    private function journalEntry(string $number, int $sequence): void
    {
        DB::table('journal_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'number' => $number,
            'entry_date' => '2026-01-15',
            'branch_id' => $this->branch->id,
            'fiscal_period_id' => $this->period->id,
            'currency_code' => 'EGP',
            'fx_rate' => 1,
            'fx_rate_date' => '2026-01-15',
            'fx_rate_source' => 'manual',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_audit_passes_when_numbers_are_unique(): void
    {
        $this->journalEntry('JE-HQ-2026-000001', 1);
        $this->journalEntry('JE-HQ-2026-000002', 2);

        $this->artisan('accounting:audit-document-numbers')
            ->expectsOutputToContain('Journal entries (journal_entries): 2 rows, no duplicate (branch, number) pairs.')
            ->expectsOutputToContain('No duplicates.')
            ->assertSuccessful();
    }

    public function test_audit_reports_duplicates_and_fails_without_changing_them(): void
    {
        $this->journalEntry('JE-HQ-2026-000001', 1);
        $this->journalEntry('JE-HQ-2026-000001', 2);

        $this->artisan('accounting:audit-document-numbers')
            ->expectsOutputToContain('1 duplicate (branch, number) pair')
            ->expectsOutputToContain('JE-HQ-2026-000001 appears 2 times')
            ->expectsOutputToContain('must be resolved by an accountant')
            ->assertFailed();

        $this->assertSame(2, DB::table('journal_entries')->where('number', 'JE-HQ-2026-000001')->count());
    }

    public function test_json_report_is_machine_readable(): void
    {
        $this->journalEntry('JE-HQ-2026-000001', 1);
        $this->journalEntry('JE-HQ-2026-000001', 2);

        $exitCode = Artisan::call('accounting:audit-document-numbers', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($report);
        $this->assertSame(1, $report['duplicates_found']);
        $journal = collect($report['tables'])->firstWhere('table', 'journal_entries');
        $this->assertIsArray($journal);
        $this->assertSame(2, $journal['rows']);
        $this->assertCount(1, $journal['duplicate_groups']);
        $this->assertSame('JE-HQ-2026-000001', $journal['duplicate_groups'][0]['number']);
        $this->assertSame(2, $journal['duplicate_groups'][0]['occurrences']);
        $this->assertCount(2, $journal['duplicate_groups'][0]['ids']);
    }
}
