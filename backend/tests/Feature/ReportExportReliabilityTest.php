<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Financial export reliability (work order 10 / ADR-0014): XLSX totals equal
 * ledger totals exactly, formula-injection safety, `reports.export`
 * authorization, branch isolation and throttling.
 */
class ReportExportReliabilityTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Branch $otherBranch;

    private User $otherUser;

    private string $from;

    private string $to;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        $permissions = ['journal.view', 'journal.create', 'reports.view', 'reports.export', 'assets.view', 'assets.manage', 'assets.approve', 'accounts.view'];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permissions);

        $this->otherBranch = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'فرع ثان', 'name_en' => 'Second Branch', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->otherUser = User::factory()->create();
        $this->otherUser->branches()->attach($this->otherBranch->id);
        $this->otherUser->givePermissionTo(['journal.view', 'reports.view', 'reports.export', 'assets.view', 'accounts.view']);

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
        $this->day = now()->startOfMonth()->addDay()->toDateString();

        Sanctum::actingAs($this->user);
    }

    private function entry(string $debit, string $credit, int $amount, ?Branch $branch = null, string $memo = 'Entry'): void
    {
        $branch ??= $this->branch;

        $this->withHeaders(['X-Branch-Id' => $branch->public_id])
            ->postJson('/api/v1/journal-entries', [
                'entry_date' => $this->day,
                'branch_id' => $branch->public_id,
                'currency' => 'EGP',
                'fx_rate' => '1',
                'fx_rate_date' => $this->day,
                'fx_rate_source' => 'manual',
                'memo' => $memo,
                'post' => true,
                'lines' => [
                    ['account_id' => $this->account($debit)->public_id, 'debit_minor' => $amount, 'credit_minor' => 0],
                    ['account_id' => $this->account($credit)->public_id, 'debit_minor' => 0, 'credit_minor' => $amount],
                ],
            ])
            ->assertCreated();
    }

    /** @param array<string, mixed> $query */
    private function export(string $path, array $query = [], ?Branch $branch = null): TestResponse
    {
        return $this->withHeaders(['X-Branch-Id' => ($branch ?? $this->branch)->public_id])
            ->get('/api/v1/reports/'.$path.'/export?'.http_build_query($query));
    }

    /** @return array<string, array<string, mixed>> report path => query */
    private function allExports(): array
    {
        return [
            'trial-balance' => ['from' => $this->from, 'to' => $this->to],
            'general-ledger' => ['account_id' => $this->account('1110')->public_id, 'from' => $this->from, 'to' => $this->to],
            'income-statement' => ['from' => $this->from, 'to' => $this->to],
            'balance-sheet' => ['as_of' => $this->to],
            'aging' => ['type' => 'ar', 'as_of' => $this->to],
            'aging-reconciliation' => ['type' => 'ar', 'as_of' => $this->to],
            'party-statement' => ['party_type' => 'customer', 'party_id' => $this->customer()->public_id, 'from' => $this->from, 'to' => $this->to],
            'fixed-assets/roll-forward' => ['from' => now()->format('Y-m'), 'to' => now()->format('Y-m')],
            'fixed-assets/carrying-values' => ['as_of' => $this->to],
            'fixed-assets/movements' => ['from' => now()->format('Y-m'), 'to' => now()->format('Y-m')],
            'fixed-assets/reconciliation' => ['as_of' => $this->to],
            'fixed-assets/schedule' => [],
        ];
    }

    /** @return array<int, array<int, Cell>> */
    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->getCells();
            }
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }

    /** Spreadsheet decimal -> integer minor units (two decimals), without trusting float equality. */
    private function minor(mixed $cell): int
    {
        $this->assertInstanceOf(NumericCell::class, $cell, 'Money must be a numeric cell, not text.');

        return (int) round($cell->getValue() * 100);
    }

    // ---------------------------------------------------------------- totals

    public function test_trial_balance_xlsx_totals_equal_the_ledger_exactly(): void
    {
        // Awkward cents on purpose: float summation of these drifts, integer minor units do not.
        foreach ([10, 33, 1, 99999, 123457, 7, 70, 1999] as $amount) {
            $this->entry('1110', '4100', $amount);
        }
        $this->entry('5100', '1110', 12345);

        $json = $this->getJson('/api/v1/reports/trial-balance?'.http_build_query(['from' => $this->from, 'to' => $this->to]), ['X-Branch-Id' => $this->branch->public_id])
            ->assertOk();
        $ledgerDebit = $json->json('data.total_debit_minor');
        $ledgerCredit = $json->json('data.total_credit_minor');
        $this->assertSame($ledgerDebit, $ledgerCredit);
        $this->assertSame(10 + 33 + 1 + 99999 + 123457 + 7 + 70 + 1999 + 12345, $ledgerDebit);

        $rows = $this->readXlsx($this->export('trial-balance', ['from' => $this->from, 'to' => $this->to, 'format' => 'xlsx'])->assertOk()->getContent());

        $header = array_search('Debit', array_map(fn ($c) => $c->getValue(), $rows[0]), true);
        $this->assertSame(5, $header, 'Header row is first.');

        $sumDebit = $sumCredit = 0;
        $total = null;
        foreach (array_slice($rows, 1) as $row) {
            if ($row[1]->getValue() === 'Total') {
                $total = $row;
                break;
            }
            $sumDebit += $this->minor($row[5]);
            $sumCredit += $this->minor($row[6]);
        }

        $this->assertNotNull($total);
        $this->assertSame($ledgerDebit, $sumDebit, 'Sum of the account rows equals the ledger debit total.');
        $this->assertSame($ledgerCredit, $sumCredit);
        $this->assertSame($ledgerDebit, $this->minor($total[5]), 'The Total row equals the ledger total.');
        $this->assertSame($ledgerCredit, $this->minor($total[6]));

        // The CSV carries exactly the same decimal strings.
        $csv = $this->export('trial-balance', ['from' => $this->from, 'to' => $this->to])->assertOk()->getContent();
        $this->assertStringContainsString(number_format($ledgerDebit / 100, 2, '.', ''), $csv);
    }

    public function test_general_ledger_xlsx_totals_and_balances_equal_the_ledger(): void
    {
        foreach ([10, 33, 1, 99999, 123457] as $amount) {
            $this->entry('1110', '4100', $amount);
        }
        $this->entry('5100', '1110', 4242);

        $account = $this->account('1110')->public_id;
        $json = $this->getJson('/api/v1/reports/general-ledger?'.http_build_query(['account_id' => $account, 'from' => $this->from, 'to' => $this->to, 'per_page' => 200]), ['X-Branch-Id' => $this->branch->public_id])
            ->assertOk();

        $rows = $this->readXlsx($this->export('general-ledger', ['account_id' => $account, 'from' => $this->from, 'to' => $this->to, 'format' => 'xlsx'])->assertOk()->getContent());
        $totals = collect($rows)->first(fn ($row) => ($row[0]->getValue() ?? null) === 'Totals');

        $this->assertNotNull($totals);
        $this->assertSame($json->json('data.total_debit_minor'), $this->minor($totals[4]));
        $this->assertSame($json->json('data.total_credit_minor'), $this->minor($totals[5]));
        $this->assertSame($json->json('data.closing_minor'), $this->minor($totals[6]));

        // Transaction dates are real date cells.
        $dateRow = collect($rows)->first(fn ($row) => ($row[0] ?? null) instanceof DateTimeCell);
        $this->assertNotNull($dateRow);
        $this->assertSame($this->day, $dateRow[0]->getValue()->format('Y-m-d'));
    }

    // ------------------------------------------------------ formula injection

    public function test_text_that_looks_like_a_formula_is_neutralised_in_csv_and_xlsx(): void
    {
        $this->entry('1110', '4100', 1500, memo: '=HYPERLINK("http://evil.example","pay")');
        $this->entry('1110', '4100', 2500, memo: '@SUM(1+1)');
        $this->entry('1110', '4100', 3500, memo: "-2+3\t");

        $query = ['account_id' => $this->account('1110')->public_id, 'from' => $this->from, 'to' => $this->to];

        $csv = $this->export('general-ledger', $query)->assertOk()->getContent();
        foreach (['=HYPERLINK', '@SUM', '-2+3'] as $needle) {
            $this->assertMatchesRegularExpression('/[,"]\''.preg_quote($needle, '/').'/', $csv);
            $this->assertDoesNotMatchRegularExpression('/[,"]'.preg_quote($needle, '/').'/', $csv);
        }

        $content = $this->export('general-ledger', $query + ['format' => 'xlsx'])->assertOk()->getContent();
        foreach ($this->readXlsx($content) as $row) {
            foreach ($row as $cell) {
                $this->assertNotInstanceOf(FormulaCell::class, $cell);
                if (is_string($cell->getValue())) {
                    $this->assertDoesNotMatchRegularExpression('/^[=+@\-]/', $cell->getValue(), 'Text cells never start with a formula character.');
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $zip = new \ZipArchive;
        $zip->open($path);
        $this->assertStringNotContainsString('<f>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($path);
    }

    // ----------------------------------------------------------------- pdf

    public function test_pdf_exports_render_for_every_report_and_language(): void
    {
        $this->entry('1110', '4100', 123456);

        foreach (['en', 'ar', 'bilingual'] as $language) {
            foreach (['trial-balance', 'income-statement', 'balance-sheet', 'aging', 'fixed-assets/carrying-values', 'fixed-assets/reconciliation'] as $report) {
                Cache::flush(); // the PDF cap (6/min) has its own test
                $pdf = $this->export($report, $this->allExports()[$report] + ['format' => 'pdf', 'language' => $language])
                    ->assertOk()
                    ->assertHeader('content-type', 'application/pdf');
                $this->assertStringStartsWith('%PDF-', $pdf->getContent(), "{$report} {$language}");
                $this->assertStringContainsString('%%EOF', substr($pdf->getContent(), -64));
            }
        }
    }

    public function test_invalid_options_are_rejected_before_any_audit_event(): void
    {
        $before = AuditEvent::query()->where('action', 'report.exported')->count();

        $this->export('trial-balance', ['from' => $this->from, 'to' => $this->to, 'format' => 'docx'])->assertStatus(422);
        $this->export('trial-balance', ['from' => $this->from, 'to' => $this->to, 'language' => 'fr'])->assertStatus(422);

        $this->assertSame($before, AuditEvent::query()->where('action', 'report.exported')->count());
    }

    public function test_exports_are_audited_with_provenance_and_without_party_data(): void
    {
        $this->entry('1110', '4100', 5000, memo: 'Confidential memo text');

        $this->export('trial-balance', ['from' => $this->from, 'to' => $this->to, 'format' => 'xlsx', 'language' => 'ar'])->assertOk();

        $event = AuditEvent::query()->where('action', 'report.exported')->latest('id')->firstOrFail();
        $this->assertSame($this->user->id, $event->actor_id);
        $this->assertSame('trial-balance', $event->after['report']);
        $this->assertSame('xlsx', $event->after['format']);
        $this->assertSame('ar', $event->after['language']);
        $this->assertSame($this->branch->public_id, $event->after['branch']);
        $this->assertGreaterThan(0, $event->after['rows']);
        $this->assertStringNotContainsString('Confidential', json_encode($event->after));
    }

    // -------------------------------------------------------- authorization

    public function test_every_export_requires_the_export_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo(['reports.view', 'assets.view', 'journal.view']);
        Sanctum::actingAs($viewer);

        foreach ($this->allExports() as $report => $query) {
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                Cache::flush();
                $this->export($report, $query + ['format' => $format])->assertStatus(403);
            }
        }
        $this->assertSame(0, AuditEvent::query()->where('action', 'report.exported')->count());
    }

    public function test_fixed_asset_exports_also_require_the_asset_view_permission(): void
    {
        $exporter = User::factory()->create();
        $exporter->branches()->attach($this->branch->id);
        $exporter->givePermissionTo(['reports.view', 'reports.export']);
        Sanctum::actingAs($exporter);

        $this->export('fixed-assets/carrying-values', ['as_of' => $this->to])->assertStatus(403);
        $this->export('trial-balance', ['from' => $this->from, 'to' => $this->to])->assertOk();
    }

    public function test_unauthenticated_exports_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/reports/trial-balance/export?'.http_build_query(['from' => $this->from, 'to' => $this->to]))->assertStatus(401);
    }

    // ------------------------------------------------------ branch isolation

    public function test_exports_never_leak_another_branchs_records(): void
    {
        $secret = 7777701; // 77,777.01
        $this->entry('1110', '4100', $secret, memo: 'SECRET-BRANCH-ONE-MEMO');
        $customer = $this->customer();
        $category = $this->makeCategory();
        $asset = $this->makeAsset($category, 'SECRET-ASSET-BRANCH-ONE', 1_200_000);
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->postJson("/api/v1/fixed-assets/{$asset->public_id}/capitalize")->assertOk();

        // Control: the owner branch sees its own data in every format.
        $own = $this->export('trial-balance', ['from' => $this->from, 'to' => $this->to])->assertOk()->getContent();
        $this->assertStringContainsString('77777.01', $own);

        Sanctum::actingAs($this->otherUser);

        $leakNeedles = ['77777.01', 'SECRET-BRANCH-ONE-MEMO', 'SECRET-ASSET-BRANCH-ONE', '12000.00', $customer->name_en];
        $queries = $this->allExports() + [];
        $queries['general-ledger'] = ['account_id' => $this->account('1110')->public_id, 'from' => $this->from, 'to' => $this->to];
        unset($queries['party-statement']); // another branch's party is refused below

        foreach ($queries as $report => $query) {
            // Even with global=1 a user without branches.view_all stays on their own branch.
            $extra = str_starts_with($report, 'fixed-assets') ? ['global' => 1] : [];

            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                Cache::flush();
                $response = $this->export($report, $query + $extra + ['format' => $format], $this->otherBranch)->assertOk();
                $body = $response->getContent();
                if ($format === 'xlsx') {
                    $body = json_encode(array_map(fn (array $row) => array_map(fn ($c) => $c->getValue(), $row), $this->readXlsx($body)), JSON_UNESCAPED_UNICODE);
                } elseif ($format === 'pdf') {
                    $body = $this->pdfText($body);
                }
                foreach ($leakNeedles as $needle) {
                    $this->assertStringNotContainsString((string) $needle, $body, "{$report}.{$format} leaked '{$needle}'");
                }
            }
        }

        // Explicitly asking for the first branch is refused.
        foreach ($this->allExports() as $report => $query) {
            Cache::flush();
            $this->export($report, $query, $this->branch)->assertStatus(403);
        }
        Cache::flush();

        // A party that belongs to the first branch cannot be exported from the second.
        $this->export('party-statement', [
            'party_type' => 'customer', 'party_id' => $customer->public_id, 'from' => $this->from, 'to' => $this->to,
        ], $this->otherBranch)->assertStatus(403);
    }

    private function pdfText(string $pdf): string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return '';
        }
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
        @unlink($path);

        return $text;
    }

    // ---------------------------------------------------------- fixed assets

    public function test_fixed_asset_exports_are_available_numeric_and_equal_the_json_report(): void
    {
        $category = $this->makeCategory();
        $asset = $this->makeAsset($category, 'Delivery van', 1_200_033);
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->postJson("/api/v1/fixed-assets/{$asset->public_id}/capitalize")->assertOk();

        $json = $this->getJson('/api/v1/reports/fixed-assets/carrying-values?as_of='.$this->to, ['X-Branch-Id' => $this->branch->public_id])->assertOk();
        $rows = $this->readXlsx($this->export('fixed-assets/carrying-values', ['as_of' => $this->to, 'format' => 'xlsx'])->assertOk()->getContent());

        $total = collect($rows)->first(fn ($row) => ($row[0]->getValue() ?? null) === 'Total');
        $this->assertNotNull($total);
        $this->assertSame($json->json('data.total_cost_minor'), $this->minor($total[7]));
        $this->assertSame(1_200_033, $this->minor($total[7]));
        $this->assertSame($json->json('data.total_carrying_value_minor'), $this->minor($total[9]));

        foreach (['roll-forward', 'movements'] as $name) {
            $csv = $this->export('fixed-assets/'.$name, ['from' => now()->format('Y-m'), 'to' => now()->format('Y-m')])->assertOk();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        }

        $schedule = $this->export('fixed-assets/schedule', ['asset' => $asset->public_id])->assertOk()->getContent();
        $this->assertStringContainsString('Period', $schedule);

        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported']);
    }

    // -------------------------------------------------------------- throttling

    public function test_exports_are_throttled_per_user_and_not_by_other_routes(): void
    {
        $query = ['from' => $this->from, 'to' => $this->to];

        // Other throttled routes (search) must not consume the export budget.
        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/v1/search?q=abc', ['X-Branch-Id' => $this->branch->public_id]);
        }

        for ($i = 0; $i < 20; $i++) {
            $this->export('trial-balance', $query)->assertOk();
        }
        $this->export('trial-balance', $query)->assertStatus(429);

        // Another user is unaffected.
        Sanctum::actingAs($this->otherUser);
        $this->export('trial-balance', $query, $this->otherBranch)->assertOk();
    }

    public function test_pdf_exports_have_a_stricter_per_minute_cap(): void
    {
        $query = ['from' => $this->from, 'to' => $this->to, 'format' => 'pdf'];

        for ($i = 0; $i < 6; $i++) {
            $this->export('trial-balance', $query)->assertOk();
        }
        $this->export('trial-balance', $query)->assertStatus(429);
        // CSV is still allowed within the general budget.
        $this->export('trial-balance', ['format' => 'csv'] + $query)->assertOk();
    }

    // ----------------------------------------------------------------- helpers

    private function makeCategory(): FixedAssetCategory
    {
        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->postJson('/api/v1/fixed-asset-categories', [
            'code' => 'VEH', 'name_ar' => 'مركبات', 'name_en' => 'Vehicles', 'default_useful_life_months' => 12,
        ])->assertCreated();

        return FixedAssetCategory::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }

    private function makeAsset(FixedAssetCategory $category, string $description, int $value): FixedAsset
    {
        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->postJson('/api/v1/fixed-assets', [
            'category_id' => $category->public_id,
            'description' => $description,
            'capitalization_date' => $this->from,
            'in_service_date' => $this->from,
            'acquisition_value_minor' => $value,
            'residual_value_minor' => 0,
            'useful_life_months' => 12,
            'currency' => 'EGP',
        ])->assertCreated();

        return FixedAsset::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }
}
