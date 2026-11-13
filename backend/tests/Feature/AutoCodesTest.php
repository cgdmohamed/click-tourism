<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Core\Models\Branch;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use App\Support\CodeGenerator;
use App\Support\Money;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Master-data codes are generated: a blank/omitted code is filled in, a supplied
 * one is respected and still validated (duplicates stay 422).
 */
class AutoCodesTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'employees.manage', 'cost_centers.manage', 'branches.create', 'expense_categories.manage', 'expenses.view',
        'leads.view', 'leads.manage_rules', 'commission_rules.manage', 'commissions.view', 'assets.manage', 'assets.view',
        'accounts.create', 'accounts.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);
        Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    // ---- generator ---------------------------------------------------------

    public function test_generator_pads_increments_and_skips_taken_codes(): void
    {
        $this->assertSame('EMP-0001', CodeGenerator::next('employees', 'internal_code', 'EMP', 4));
        $this->assertSame('BR-01', CodeGenerator::next('branches', 'code', 'BR', 2), 'HQ does not match the prefix');

        Branch::query()->create(['code' => 'br-05', 'name_ar' => 'a', 'name_en' => 'a', 'accounting_mode' => 'dimension', 'is_active' => true]);
        // The highest typed number wins (case-insensitive), so earlier hand-typed codes are never reused.
        $this->assertSame('BR-06', CodeGenerator::next('branches', 'code', 'BR', 2));
    }

    public function test_generator_never_returns_an_existing_code_and_ignores_other_shapes(): void
    {
        foreach (['CC-001', 'CC-002', 'CC-ABC', 'CCX-009', 'CC-7'] as $code) {
            CostCenter::query()->create(['code' => $code, 'name_ar' => 'x', 'name_en' => 'x']);
        }

        $this->assertSame('CC-008', CodeGenerator::next('cost_centers', 'code', 'CC', 3));
    }

    public function test_generator_create_uses_supplied_code_or_generates_inside_a_transaction(): void
    {
        $this->assertSame('MINE', CodeGenerator::create(' MINE ', 'branches', 'code', 'BR', 2, fn (string $code) => $code));
        $this->assertSame('BR-01', CodeGenerator::create('', 'branches', 'code', 'BR', 2, fn (string $code) => $code));
        $outer = DB::transactionLevel();
        $this->assertSame('BR-01', CodeGenerator::create(null, 'branches', 'code', 'BR', 2, function (string $code) use ($outer) {
            $this->assertGreaterThan($outer, DB::transactionLevel());

            return $code;
        }));
    }

    public function test_generator_retries_when_the_unique_index_rejects_the_insert(): void
    {
        $attempts = 0;
        $code = CodeGenerator::create(null, 'branches', 'code', 'BR', 2, function (string $code) use (&$attempts) {
            $attempts++;
            $row = ['code' => $code, 'name_ar' => 'a', 'name_en' => 'a', 'accounting_mode' => 'dimension', 'is_active' => true];
            if ($attempts === 1) {
                // Stands in for a concurrent request that took the same code: the second insert hits the
                // unique index, the attempt is rolled back and the generator tries again.
                Branch::query()->create($row);
            }
            Branch::query()->create($row);

            return $code;
        });

        $this->assertSame(2, $attempts);
        $this->assertSame('BR-01', $code);
        $this->assertSame(1, Branch::query()->where('code', 'BR-01')->count());
    }

    // ---- employees ---------------------------------------------------------

    public function test_employee_without_a_code_gets_one_and_a_supplied_code_is_respected(): void
    {
        $body = ['name_ar' => 'موظف', 'name_en' => 'Staff'];

        $first = $this->withHeaders($this->headers())->postJson('/api/v1/employees', $body)->assertCreated();
        $this->assertSame('EMP-0001', $first->json('data.internal_code'));
        $second = $this->withHeaders($this->headers())->postJson('/api/v1/employees', $body + ['internal_code' => ''])->assertCreated();
        $this->assertSame('EMP-0002', $second->json('data.internal_code'));

        $this->withHeaders($this->headers())->postJson('/api/v1/employees', $body + ['internal_code' => 'my-emp'])
            ->assertCreated()->assertJsonPath('data.internal_code', 'MY-EMP');
        $this->withHeaders($this->headers())->postJson('/api/v1/employees', $body + ['internal_code' => 'EMP-0001'])->assertUnprocessable();
        // The next generated code skips what users typed.
        $this->withHeaders($this->headers())->postJson('/api/v1/employees', $body + ['internal_code' => 'EMP-0010'])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/v1/employees', $body)->assertCreated()->assertJsonPath('data.internal_code', 'EMP-0011');
    }

    // ---- cost centers / branches / expense categories ---------------------

    public function test_cost_center_branch_and_expense_category_codes(): void
    {
        $h = $this->headers();

        $this->withHeaders($h)->postJson('/api/v1/cost-centers', ['name_ar' => 'ع', 'name_en' => 'A'])
            ->assertCreated()->assertJsonPath('data.code', 'CC-001');
        $this->withHeaders($h)->postJson('/api/v1/cost-centers', ['name_ar' => 'ع', 'name_en' => 'B', 'code' => 'mkt'])
            ->assertCreated()->assertJsonPath('data.code', 'MKT');
        $this->withHeaders($h)->postJson('/api/v1/cost-centers', ['name_ar' => 'ع', 'name_en' => 'C', 'code' => 'MKT'])->assertUnprocessable();
        $this->withHeaders($h)->postJson('/api/v1/cost-centers', ['name_ar' => 'ع', 'name_en' => 'D'])
            ->assertCreated()->assertJsonPath('data.code', 'CC-002');

        $this->withHeaders($h)->postJson('/api/v1/branches', ['name_ar' => 'فرع', 'name_en' => 'Branch'])
            ->assertCreated()->assertJsonPath('data.code', 'BR-01');
        $this->withHeaders($h)->postJson('/api/v1/branches', ['name_ar' => 'فرع', 'name_en' => 'Branch', 'code' => 'cai'])
            ->assertCreated()->assertJsonPath('data.code', 'CAI');
        $this->withHeaders($h)->postJson('/api/v1/branches', ['name_ar' => 'فرع', 'name_en' => 'Branch', 'code' => 'HQ'])->assertUnprocessable();

        $account = $this->withHeaders($h)->getJson('/api/v1/accounts?search=6100')->json('data.0.id');
        $category = ['name_ar' => 'فئة', 'name_en' => 'Category', 'account_id' => $account];
        $this->withHeaders($h)->postJson('/api/v1/expense-categories', $category)
            ->assertCreated()->assertJsonPath('data.code', 'EXP-001');
        $this->withHeaders($h)->postJson('/api/v1/expense-categories', $category + ['code' => 'fuel'])
            ->assertCreated()->assertJsonPath('data.code', 'FUEL');
        $this->withHeaders($h)->postJson('/api/v1/expense-categories', $category + ['code' => 'FUEL'])->assertUnprocessable();
    }

    // ---- lead sources / stages ---------------------------------------------

    public function test_new_lead_sources_and_stages_are_generated_and_seeded_codes_stay(): void
    {
        $h = $this->headers();
        $seededSources = LeadSource::query()->orderBy('id')->pluck('code')->all();
        $seededStages = LeadStage::query()->orderBy('id')->pluck('code')->all();
        $this->assertContains('other', $seededSources);
        $this->assertContains('new', $seededStages);

        $this->withHeaders($h)->postJson('/api/v1/lead-sources', ['name_ar' => 'مصدر', 'name_en' => 'Source'])
            ->assertCreated()->assertJsonPath('data.code', 'src-001');
        $this->withHeaders($h)->postJson('/api/v1/lead-sources', ['name_ar' => 'مصدر', 'name_en' => 'Source 2', 'code' => 'Roadshow'])
            ->assertCreated()->assertJsonPath('data.code', 'roadshow');
        $this->withHeaders($h)->postJson('/api/v1/lead-sources', ['name_ar' => 'مصدر', 'name_en' => 'Source 3', 'code' => 'other'])->assertUnprocessable();
        $this->withHeaders($h)->postJson('/api/v1/lead-sources', ['name_ar' => 'مصدر', 'name_en' => 'Source 4'])
            ->assertCreated()->assertJsonPath('data.code', 'src-002');

        $this->withHeaders($h)->postJson('/api/v1/lead-stages', ['name_ar' => 'مرحلة', 'name_en' => 'Stage'])
            ->assertCreated()->assertJsonPath('data.code', 'stg-001');
        $this->withHeaders($h)->postJson('/api/v1/lead-stages', ['name_ar' => 'مرحلة', 'name_en' => 'Stage', 'code' => 'new'])->assertUnprocessable();

        $this->assertSame($seededSources, LeadSource::query()->orderBy('id')->limit(count($seededSources))->pluck('code')->all());
        $this->assertSame($seededStages, LeadStage::query()->orderBy('id')->limit(count($seededStages))->pluck('code')->all());
    }

    // ---- commission rules / fixed asset categories -------------------------

    public function test_commission_rule_and_fixed_asset_category_codes(): void
    {
        $h = $this->headers();
        $rule = [
            'name_ar' => 'قاعدة', 'name_en' => 'Rule', 'beneficiary_type' => 'employee', 'basis' => 'percent_margin',
            'rate_bp' => 250, 'effective_from' => now()->toDateString(), 'service_type' => 'hotel',
        ];
        $this->withHeaders($h)->postJson('/api/v1/commission-rules', $rule)->assertCreated()->assertJsonPath('data.code', 'RULE-001');
        $this->withHeaders($h)->postJson('/api/v1/commission-rules', $rule + ['code' => 'vip'])->assertCreated()->assertJsonPath('data.code', 'VIP');
        $this->withHeaders($h)->postJson('/api/v1/commission-rules', $rule + ['code' => 'VIP'])->assertUnprocessable();
        $this->withHeaders($h)->postJson('/api/v1/commission-rules', $rule)->assertCreated()->assertJsonPath('data.code', 'RULE-002');

        $category = ['name_ar' => 'تصنيف', 'name_en' => 'Category', 'default_useful_life_months' => 12];
        $this->withHeaders($h)->postJson('/api/v1/fixed-asset-categories', $category)->assertCreated()->assertJsonPath('data.code', 'FAC-001');
        $this->withHeaders($h)->postJson('/api/v1/fixed-asset-categories', $category + ['code' => 'VEH'])->assertCreated()->assertJsonPath('data.code', 'VEH');
        $this->withHeaders($h)->postJson('/api/v1/fixed-asset-categories', $category + ['code' => 'VEH'])->assertUnprocessable();
        $this->withHeaders($h)->postJson('/api/v1/fixed-asset-categories', $category)->assertCreated()->assertJsonPath('data.code', 'FAC-002');
    }

    public function test_fixed_asset_without_a_code_still_gets_a_document_number(): void
    {
        $h = $this->headers();
        $category = $this->withHeaders($h)->postJson('/api/v1/fixed-asset-categories', ['name_ar' => 'ت', 'name_en' => 'C', 'default_useful_life_months' => 12])->json('data.id');

        $asset = $this->withHeaders($h)->postJson('/api/v1/fixed-assets', [
            'category_id' => $category, 'description' => 'Van', 'capitalization_date' => now()->toDateString(), 'acquisition_value_minor' => 100000,
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^FA-HQ-\d{4}-\d{6}$/', $asset->json('data.code'));
    }

    // ---- accounts ------------------------------------------------------------

    private function accountId(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->public_id;
    }

    private function createAccount(array $body): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/accounts', $body + ['name_ar' => 'حساب', 'name_en' => 'Account', 'type' => 'asset']);
    }

    public function test_account_code_follows_the_sibling_pattern_under_a_parent(): void
    {
        // Seeded 1150 has children 1151..1154: the next one continues the pattern.
        $this->createAccount(['parent_id' => $this->accountId('1150')])->assertCreated()->assertJsonPath('data.code', '1155');

        // Wide sibling pattern: 1100 -> 11000001, 11000002 gives 11000003.
        $parent = Account::query()->where('code', '1100')->firstOrFail();
        foreach (['11000001', '11000002'] as $code) {
            Account::query()->create(['code' => $code, 'name_ar' => 'x', 'name_en' => 'x', 'type' => 'asset', 'parent_id' => $parent->id, 'is_postable' => true, 'is_active' => true]);
        }
        $this->createAccount(['parent_id' => $parent->public_id])->assertCreated()->assertJsonPath('data.code', '11000003');

        // Step between siblings (10) is followed: 1110, 1120 style.
        $group = Account::query()->create(['code' => '1500', 'name_ar' => 'g', 'name_en' => 'g', 'type' => 'asset', 'is_postable' => false, 'is_active' => true]);
        foreach (['1510', '1520'] as $code) {
            Account::query()->create(['code' => $code, 'name_ar' => 'x', 'name_en' => 'x', 'type' => 'asset', 'parent_id' => $group->id, 'is_postable' => true, 'is_active' => true]);
        }
        $this->createAccount(['parent_id' => $group->public_id])->assertCreated()->assertJsonPath('data.code', '1530');
    }

    public function test_account_code_falls_back_to_parent_code_plus_counter_and_skips_taken(): void
    {
        $parent = Account::query()->create(['code' => '1600', 'name_ar' => 'g', 'name_en' => 'g', 'type' => 'asset', 'is_postable' => false, 'is_active' => true]);
        $this->createAccount(['parent_id' => $parent->public_id])->assertCreated()->assertJsonPath('data.code', '160001');

        // A code taken elsewhere (not as a child) is skipped.
        Account::query()->create(['code' => '160002', 'name_ar' => 'x', 'name_en' => 'x', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
        $this->createAccount(['parent_id' => $parent->public_id])->assertCreated()->assertJsonPath('data.code', '160003');
    }

    public function test_root_account_code_and_explicit_override_and_duplicates(): void
    {
        $this->createAccount(['type' => 'equity'])->assertCreated()->assertJsonPath('data.code', '3300');
        $this->createAccount(['type' => 'equity'])->assertCreated()->assertJsonPath('data.code', '3400');

        $this->createAccount(['code' => '9999', 'type' => 'equity'])->assertCreated()->assertJsonPath('data.code', '9999');
        $this->createAccount(['code' => '9999', 'type' => 'equity'])->assertUnprocessable();
    }

    public function test_account_next_code_endpoint_suggests_without_creating(): void
    {
        $h = $this->headers();
        $before = Account::query()->count();

        $this->withHeaders($h)->getJson('/api/v1/accounts/next-code?parent_id='.$this->accountId('1150'))
            ->assertOk()->assertJsonPath('data.code', '1155');
        $this->withHeaders($h)->getJson('/api/v1/accounts/next-code?type=equity')->assertOk()->assertJsonPath('data.code', '3300');
        $this->withHeaders($h)->getJson('/api/v1/accounts/next-code')->assertUnprocessable();
        $this->assertSame($before, Account::query()->count());
    }

    public function test_account_code_beyond_twenty_characters_is_a_validation_error(): void
    {
        $parent = Account::query()->create(['code' => str_repeat('A', 19), 'name_ar' => 'g', 'name_en' => 'g', 'type' => 'asset', 'is_postable' => false, 'is_active' => true]);
        $this->createAccount(['parent_id' => $parent->public_id])->assertUnprocessable();
    }

    // ---- currencies ----------------------------------------------------------

    public function test_currency_catalogue_exponents_match_the_built_in_money_table(): void
    {
        $file = base_path('../web/src/lib/currencyCatalogue.ts');
        if (! is_file($file)) {
            $this->markTestSkipped('The web sources are not available next to the backend.');
        }

        preg_match_all("/code: '([A-Z]{3})'.*?exponent: (\d)/", (string) file_get_contents($file), $rows, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(35, count($rows));

        $builtin = 0;
        foreach ($rows as [, $code, $exponent]) {
            if (Money::builtinExponent($code) !== null) {
                $builtin++;
                $this->assertSame(Money::builtinExponent($code), (int) $exponent, "{$code} exponent differs from Money::builtinExponent");
            }
        }
        $this->assertGreaterThanOrEqual(10, $builtin);
    }
}
