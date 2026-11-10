<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class ApiAccessTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function grant(string $permission): void
    {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permission);
    }

    public function test_a_user_can_log_in_and_receive_a_token_and_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', $this->user->email)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions', 'branches']]]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_a_disabled_account_cannot_use_an_existing_token(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;
        $this->user->update(['is_active' => false]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_disabled');
    }

    public function test_me_returns_the_authenticated_profile(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->user->email)
            ->assertJsonCount(1, 'data.branches');
    }

    public function test_a_user_cannot_request_a_branch_they_are_not_assigned_to(): void
    {
        $this->grant('bookings.view');

        $other = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);

        $this->withHeader('X-Branch-Id', $other->public_id)
            ->getJson('/api/v1/bookings')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_a_user_without_the_permission_cannot_create_a_booking(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/bookings', [
            'customer_id' => 'whatever',
            'branch_id' => $this->branch->public_id,
            'start_date' => now()->toDateString(),
            'currency' => 'EGP',
            'items' => [],
        ])->assertStatus(403);
    }

    public function test_the_dashboard_endpoint_returns_the_expected_shape(): void
    {
        $this->grant('reports.view');
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'customers_count',
                    'bookings_count',
                    'invoices_count',
                    'tickets_count',
                    'open_bookings',
                    'confirmed_bookings',
                    'collected_minor',
                    'overdue_minor',
                    'currency',
                    'recent_bookings',
                    'recent_payments',
                ],
            ]);
    }

    public function test_an_unknown_route_returns_json_404_for_api_requests(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/does-not-exist')->assertStatus(404);
    }

    public function test_branch_list_only_returns_assigned_branches(): void
    {
        $this->grant('branches.view');

        Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'HQ');
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        $inactive = User::factory()->inactive()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $inactive->email,
            'password' => 'password',
        ])->assertStatus(403)->assertJsonPath('code', 'account_disabled');
    }
}
