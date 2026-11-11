<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\AssignmentRule;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Payments\Models\SupplierObligation;
use App\Support\Like;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** `search` on the list endpoints that feed server-side pickers. */
class PickerSearchTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'customers.view', 'suppliers.view', 'employees.view', 'accounts.view',
        'cost_centers.view', 'supplier_obligations.view', 'leads.update', 'leads.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($this->user);
    }

    private function fetch(string $url)
    {
        return $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->getJson('/api/v1/'.$url);
    }

    private function newCustomer(string $nameEn): Customer
    {
        return Customer::query()->create([
            'type' => 'individual',
            'name_ar' => 'عميل '.$nameEn,
            'name_en' => $nameEn,
            'branch_id' => $this->branch->id,
            'credit_limit_minor' => 0,
            'currency_code' => 'EGP',
            'is_active' => true,
        ]);
    }

    public function test_like_escape_neutralises_wildcards(): void
    {
        $this->assertSame('100!%!_a\\b', Like::escape('100%_a\\b'));
        $this->assertNull(Like::term('   '));
        $this->assertNull(Like::term(['x']));
        $this->assertSame('abc', Like::term('  abc '));
    }

    public function test_customer_search_finds_rows_beyond_the_first_page_and_treats_wildcards_literally(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->newCustomer('Bulk '.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $this->newCustomer('Needle 100%');

        $this->fetch('customers?per_page=100&search=Bulk 119')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->fetch('customers?per_page=100&search='.urlencode('100%'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name_en', 'Needle 100%');

        $this->fetch('customers?search=%25')->assertOk()->assertJsonCount(1, 'data');
        $this->fetch('customers?search=_')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_supplier_employee_account_and_cost_center_search(): void
    {
        $this->supplier();
        $this->fetch('suppliers?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->fetch('suppliers?search=Test Supp')->assertOk()->assertJsonCount(1, 'data');

        Employee::query()->create([
            'internal_code' => 'EMP-77', 'name_ar' => 'موظف', 'name_en' => 'Zed Worker',
            'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $this->fetch('employees?search=emp-77')->assertOk()->assertJsonCount(1, 'data');
        $this->fetch('employees?search=nobody')->assertOk()->assertJsonCount(0, 'data');

        $this->fetch('accounts?search=1100')->assertOk();
        $this->fetch('accounts?search=%25')->assertOk()->assertJsonCount(0, 'data');

        CostCenter::query()->create(['code' => 'CC-A', 'name_ar' => 'ألف', 'name_en' => 'Alpha', 'is_active' => true]);
        CostCenter::query()->create(['code' => 'CC-B', 'name_ar' => 'باء', 'name_en' => 'Beta', 'is_active' => true]);
        $this->fetch('cost-centers?search=alph')->assertOk()->assertJsonCount(1, 'data');
        $this->fetch('cost-centers?search=Alpha')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CC-A');
        $this->fetch('cost-centers?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_supplier_obligation_search_matches_reference_or_supplier(): void
    {
        $supplier = $this->supplier();
        foreach (['REF-ALPHA', 'REF-BETA'] as $reference) {
            SupplierObligation::query()->create([
                'supplier_id' => $supplier->id,
                'branch_id' => $this->branch->id,
                'amount_minor' => 1000,
                'currency_code' => 'EGP',
                'invoice_reference' => $reference,
                'status' => 'open',
            ]);
        }

        $this->fetch('supplier-obligations?search=ALPHA')->assertOk()->assertJsonCount(1, 'data');
        $this->fetch('supplier-obligations?search=Test Supplier')->assertOk()->assertJsonCount(2, 'data');
        $this->fetch('supplier-obligations?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_assignable_users_lists_branch_users_with_search_and_minimal_fields(): void
    {
        $other = User::factory()->create(['name' => 'Searchable Agent', 'email' => 'agent@example.test']);
        $other->branches()->attach($this->branch->id);
        User::factory()->create(['name' => 'Outsider Agent']);

        $response = $this->fetch('users/assignable?search=Searchable')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->public_id);
        $this->assertSame(['id', 'name', 'email'], array_keys($response->json('data.0')));

        $this->fetch('users/assignable?search=Outsider')->assertOk()->assertJsonCount(0, 'data');
        $this->fetch('users/assignable?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_assignment_rules_expose_the_target_user_public_reference(): void
    {
        $agent = User::factory()->create(['name' => 'Rule Target']);
        AssignmentRule::query()->create([
            'priority' => 1, 'branch_id' => $this->branch->id, 'strategy' => 'manual',
            'target_user_id' => $agent->id, 'is_active' => true,
        ]);

        $this->fetch('assignment-rules')->assertOk()
            ->assertJsonPath('data.0.target_user.id', $agent->public_id)
            ->assertJsonPath('data.0.target_user.name', 'Rule Target');
    }

    public function test_assignable_users_requires_the_lead_update_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        Sanctum::actingAs($viewer);

        $this->fetch('users/assignable')->assertForbidden();
    }
}
