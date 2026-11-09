<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    public function test_the_health_endpoint_is_public_and_reports_dependencies(): void
    {
        $response = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.app.status', 'ok')
            ->assertJsonPath('data.checks.database.status', 'ok')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'version',
                    'checks' => [
                        'app' => ['status'],
                        'database' => ['status', 'latency_ms'],
                        'cache' => ['status', 'driver'],
                        'queue' => ['status', 'driver'],
                    ],
                    'unresolved_decisions',
                ],
            ]);

        $decisions = $response->json('data.unresolved_decisions');

        $this->assertNotEmpty($decisions);
        $this->assertContains('DEC-001', array_column($decisions, 'id'));
    }

    public function test_the_decisions_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/decisions')->assertStatus(401);
    }

    public function test_the_decisions_endpoint_requires_the_settings_view_permission(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/decisions')->assertStatus(403);
    }

    public function test_an_admin_can_list_unresolved_decisions(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/decisions')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'DEC-001')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'title_en', 'title_ar', 'blocks', 'status'],
                ],
            ]);
    }
}
