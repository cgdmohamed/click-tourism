<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function customerNamed(string $nameEn, ?Branch $branch = null): Customer
    {
        return Customer::query()->create([
            'type' => 'individual',
            'name_ar' => $nameEn,
            'name_en' => $nameEn,
            'phone' => '01000000000',
            'branch_id' => ($branch ?? $this->branch)->id,
            'credit_limit_minor' => 0,
            'currency_code' => 'EGP',
            'is_active' => true,
        ]);
    }

    public function test_global_search_requires_authentication(): void
    {
        $this->getJson('/api/v1/search?q=test')->assertStatus(401);
    }

    public function test_global_search_returns_only_groups_the_user_can_view(): void
    {
        $this->grant('customers.view');

        $this->customerNamed('Nile Travel');
        Supplier::query()->create([
            'category' => 'hotel',
            'name_ar' => 'Nile Hotel',
            'name_en' => 'Nile Hotel',
            'branch_id' => $this->branch->id,
            'settlement_currency' => 'EGP',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/search?q=Nile')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.type', 'customers')
            ->assertJsonPath('data.groups.0.items.0.title_en', 'Nile Travel');
    }

    public function test_global_search_is_branch_scoped(): void
    {
        $this->grant('customers.view');

        $this->customerNamed('Atlas Head Office');

        $other = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->customerNamed('Atlas Alexandria', $other);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/search?q=Atlas')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.groups.0.items.0.title_en', 'Atlas Head Office');
    }

    public function test_global_search_ignores_terms_shorter_than_two_characters(): void
    {
        $this->grant('customers.view');
        $this->customerNamed('Short Term');

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/search?q=a')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.groups');
    }

    public function test_payment_list_can_be_searched_by_number_or_customer(): void
    {
        $this->grant('payments.view');

        $customer = $this->customerNamed('Searchable Payer');

        app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'method' => 'cash',
            'amount_minor' => 25000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->user->id);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/payments?search=Searchable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.name_en', 'Searchable Payer');

        $this->getJson('/api/v1/payments?search=does-not-exist')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
