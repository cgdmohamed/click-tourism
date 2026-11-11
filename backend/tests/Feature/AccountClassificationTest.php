<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Explicit per-account balance-sheet classification (Odoo / Zoho Books model).
 */
class AccountClassificationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $permissions = ['accounts.view', 'accounts.create', 'accounts.update', 'reports.view', 'reports.export'];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permissions);
        Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    private function make(string $code, string $type, ?string $classification = null): Account
    {
        return Account::query()->create([
            'code' => $code, 'name_ar' => "حساب {$code}", 'name_en' => "Account {$code}",
            'type' => $type, 'is_postable' => true, 'is_active' => true, 'classification' => $classification,
        ]);
    }

    private function book(string $debit, string $credit, int $amount): void
    {
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account($debit), $amount),
                PostingLine::credit($this->account($credit), $amount),
            ],
        ));
    }

    private function sheet(): array
    {
        return $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/balance-sheet?as_of='.now()->toDateString())
            ->assertOk()->json('data');
    }

    public function test_seeder_classifies_every_balance_sheet_account(): void
    {
        $expected = [
            '1100' => 'current_asset', '1110' => 'current_asset', '1120' => 'current_asset', '1160' => 'current_asset',
            '1150' => 'prepayment', '1151' => 'prepayment', '1152' => 'prepayment', '1153' => 'prepayment', '1154' => 'prepayment',
            '1210' => 'fixed_asset', '1300' => 'fixed_asset',
            '2100' => 'current_liability', '2200' => 'current_liability', '2300' => 'current_liability', '2400' => 'current_liability',
            '3100' => 'equity', '3200' => 'equity',
            '4100' => null, '5100' => null, '6100' => null,
        ];
        foreach ($expected as $code => $classification) {
            $this->assertSame($classification, Account::query()->where('code', $code)->value('classification'), $code);
        }
        $this->assertSame(0, Account::query()->whereIn('type', ['asset', 'liability', 'equity'])->whereNull('classification')->count());
    }

    public function test_migration_backfills_existing_rows_from_the_old_code_rule_and_rolls_back(): void
    {
        $this->make('1190', 'asset');
        $this->make('1400', 'asset');
        $this->make('2550', 'liability');
        $this->make('2150', 'liability');
        $this->make('3900', 'equity');
        $this->make('4900', 'revenue');
        $this->make('1155', 'asset');

        $migration = require database_path('migrations/2026_11_18_002000_add_classification_to_accounts.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('accounts', 'classification'));
        $migration->up();

        $expected = [
            '1190' => 'current_asset', '1155' => 'current_asset', '1150' => 'prepayment', '1154' => 'prepayment',
            '1210' => 'fixed_asset', '1300' => 'fixed_asset', '1400' => 'non_current_asset',
            '2550' => 'non_current_liability', '2150' => 'current_liability', '2400' => 'current_liability',
            '3900' => 'equity', '4900' => null, '5100' => null,
        ];
        foreach ($expected as $code => $classification) {
            $this->assertSame($classification, DB::table('accounts')->where('code', $code)->value('classification'), $code);
        }
    }

    public function test_api_validates_classification_against_account_type(): void
    {
        $base = ['name_ar' => 'ب', 'name_en' => 'B'];

        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '1410', 'type' => 'asset', 'classification' => 'non_current_asset'])
            ->assertCreated()->assertJsonPath('data.classification', 'non_current_asset');
        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '1411', 'type' => 'asset'])
            ->assertCreated()->assertJsonPath('data.classification', null);

        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '1412', 'type' => 'asset', 'classification' => 'current_liability'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_classification');
        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '4910', 'type' => 'revenue', 'classification' => 'equity'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_classification');
        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '2910', 'type' => 'liability', 'classification' => 'bogus'])
            ->assertStatus(422);
        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '2911', 'type' => 'liability', 'classification' => 'non_current_liability'])
            ->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $base + ['code' => '3910', 'type' => 'equity', 'classification' => 'equity'])
            ->assertCreated();

        $listed = collect($this->withHeaders($this->headers())->getJson('/api/v1/accounts')->assertOk()->json('data'));
        $this->assertSame('non_current_asset', $listed->firstWhere('code', '1410')['classification']);
        $this->assertSame('current_asset', $listed->firstWhere('code', '1100')['classification']);
    }

    public function test_classification_is_editable_with_postings_clearable_and_audited(): void
    {
        $this->book('1110', '3200', 1000);
        $cash = $this->account('1110');

        $this->withHeaders($this->headers())->patchJson("/api/v1/accounts/{$cash->public_id}", ['classification' => 'non_current_asset'])
            ->assertOk()->assertJsonPath('data.classification', 'non_current_asset');
        $this->assertSame('non_current_asset', $cash->refresh()->classification);

        $audit = AuditEvent::query()->where('action', 'account.updated')->latest('id')->firstOrFail();
        $this->assertSame('current_asset', $audit->before['classification']);
        $this->assertSame('non_current_asset', $audit->after['classification']);

        $this->withHeaders($this->headers())->patchJson("/api/v1/accounts/{$cash->public_id}", ['classification' => 'current_liability'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_classification');

        $this->withHeaders($this->headers())->patchJson("/api/v1/accounts/{$cash->public_id}", ['classification' => null])
            ->assertOk()->assertJsonPath('data.classification', null);
        $this->assertNull($cash->refresh()->classification);

        // type/code stay locked once posted.
        $this->withHeaders($this->headers())->patchJson("/api/v1/accounts/{$cash->public_id}", ['type' => 'liability'])
            ->assertStatus(422)->assertJsonPath('code', 'account_has_history');
    }

    public function test_changing_type_without_postings_drops_a_stale_classification(): void
    {
        $account = $this->make('1420', 'asset', 'fixed_asset');

        $this->withHeaders($this->headers())->patchJson("/api/v1/accounts/{$account->public_id}", ['type' => 'expense'])
            ->assertOk()->assertJsonPath('data.classification', null);
    }

    public function test_balance_sheet_groups_by_classification_not_by_code(): void
    {
        // Long-term receivable under an 11xx code, short-term loan outside 21-24.
        $this->make('1190', 'asset', 'non_current_asset');
        $this->make('2900', 'liability', 'current_liability');
        $this->make('1170', 'asset', 'fixed_asset');
        $this->book('1190', '3200', 5000);
        $this->book('1110', '2900', 3000);
        $this->book('1170', '3200', 700);
        $this->book('1153', '3200', 400);

        $data = $this->sheet();

        $this->assertSame('by_account', $data['classification']);
        $this->assertSame([], $data['unclassified_accounts']);
        $this->assertContains('1190', array_column($data['non_current_assets'], 'code'));
        $this->assertNotContains('1190', array_column($data['current_assets'], 'code'));
        $this->assertContains('1170', array_column($data['non_current_assets'], 'code'));
        $this->assertContains('1153', array_column($data['current_assets'], 'code'));
        $this->assertContains('2900', array_column($data['current_liabilities'], 'code'));
        $this->assertNotContains('2900', array_column($data['non_current_liabilities'], 'code'));

        $this->assertSame(3400, $data['current_assets_minor']);
        $this->assertSame(5000 + 700, $data['non_current_assets_minor']);
        $this->assertSame(700, $data['fixed_assets_minor']);
        $this->assertSame(400, $data['prepayments_minor']);
        $this->assertSame(3000, $data['current_liabilities_minor']);
        $this->assertSame(0, $data['non_current_liabilities_minor']);
        $this->assertSame($data['total_assets_minor'], $data['current_assets_minor'] + $data['non_current_assets_minor']);
        $this->assertTrue($data['balanced']);
        $line = collect($data['assets'])->firstWhere('code', '1190');
        $this->assertSame('non_current_asset', $line['classification']);
    }

    public function test_unclassified_accounts_fall_back_to_code_rule_and_are_listed(): void
    {
        $this->make('1195', 'asset');            // 11xx -> current by fallback
        $this->make('2995', 'liability');        // outside 21-24 -> non-current by fallback
        $this->book('1195', '3200', 800);
        $this->book('1110', '2995', 600);

        $data = $this->sheet();

        $this->assertSame('mixed', $data['classification']);
        $this->assertEqualsCanonicalizing(['1195', '2995'], $data['unclassified_accounts']);
        $this->assertContains('1195', array_column($data['current_assets'], 'code'));
        $this->assertContains('2995', array_column($data['non_current_liabilities'], 'code'));
        $this->assertSame($data['total_liabilities_minor'], $data['current_liabilities_minor'] + $data['non_current_liabilities_minor']);
        $this->assertTrue($data['balanced']);

        // Fixing the classification clears the warning and moves the line.
        $account = $this->account('1195');
        $this->withHeaders($this->headers())->patchJson("/api/v1/accounts/{$account->public_id}", ['classification' => 'non_current_asset'])->assertOk();
        $fixed = $this->sheet();
        $this->assertSame(['2995'], $fixed['unclassified_accounts']);
        $this->assertContains('1195', array_column($fixed['non_current_assets'], 'code'));
    }

    public function test_balance_sheet_export_reports_unclassified_accounts(): void
    {
        $this->make('1195', 'asset');
        $this->book('1195', '3200', 800);

        $csv = $this->withHeaders($this->headers())
            ->get('/api/v1/reports/balance-sheet/export?as_of='.now()->toDateString())->assertOk()->getContent();

        $this->assertStringContainsString('Classification,mixed', $csv);
        $this->assertStringContainsString('"Unclassified accounts",1195', $csv);
    }

    public function test_import_export_round_trip_and_files_without_the_column(): void
    {
        $this->make('1430', 'asset', 'non_current_asset');

        $export = $this->withHeaders($this->headers())->get('/api/v1/accounts/export')->assertOk()->getContent();
        $this->assertStringContainsString('currency_code,classification', $export);
        $this->assertStringContainsString('1430,"حساب 1430","Account 1430",asset,,true,true,,non_current_asset', $export);

        $snapshot = fn () => Account::query()->orderBy('code')->pluck('classification', 'code')->all();
        $before = $snapshot();
        $this->importCsv($export)->assertOk();
        $this->assertSame($before, $snapshot());

        // Older files without the column leave classifications untouched.
        $this->importCsv("code,name_ar,name_en,type\n1430,حساب,Renamed,asset\n9440,جديد,New,asset\n")->assertOk();
        $this->assertSame('non_current_asset', Account::query()->where('code', '1430')->value('classification'));
        $this->assertNull(Account::query()->where('code', '9440')->value('classification'));

        // The column sets and clears values.
        $this->importCsv("code,name_ar,name_en,type,classification\n1430,حساب,Renamed,asset,fixed_asset\n9440,جديد,New,asset,\n")->assertOk();
        $this->assertSame('fixed_asset', Account::query()->where('code', '1430')->value('classification'));

        // Invalid for the type -> row error.
        $this->importCsv("code,name_ar,name_en,type,classification\n9450,أ,A,liability,fixed_asset\n9451,ب,B,revenue,equity\n", true)
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.code', 'invalid_classification')
            ->assertJsonPath('row_errors.1.code', 'invalid_classification');
    }

    private function importCsv(string $content, bool $preview = false)
    {
        return $this->post('/api/v1/accounts/import', [
            'file' => UploadedFile::fake()->createWithContent('accounts.csv', $content),
            'preview_only' => $preview ? '1' : '0',
        ], ['Accept' => 'application/json'] + $this->headers());
    }
}
