<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HealthController;
use App\Modules\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
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
                        'redis' => ['status'],
                        'queue' => ['status', 'driver', 'failed_jobs'],
                        'scheduler' => ['status', 'last_run_at'],
                    ],
                    'unresolved_decisions',
                ],
            ]);

        $decisions = $response->json('data.unresolved_decisions');

        $this->assertNotEmpty($decisions);
        $this->assertContains('DEC-001', array_column($decisions, 'id'));
    }

    public function test_the_version_comes_from_configuration(): void
    {
        config(['app.version' => '9.8.7']);

        $this->getJson('/api/v1/health')->assertJsonPath('data.version', '9.8.7');
    }

    public function test_redis_is_not_configured_when_neither_cache_nor_queue_use_it(): void
    {
        config(['cache.default' => 'array', 'queue.default' => 'sync']);

        $this->getJson('/api/v1/health')->assertJsonPath('data.checks.redis.status', 'not_configured');
    }

    public function test_an_unreachable_redis_marks_the_system_degraded_but_not_down(): void
    {
        config(['cache.default' => 'array', 'queue.default' => 'redis']);
        Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('refused'));

        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSame('down', $response->json('data.checks.redis.status'));
        $this->assertSame('degraded', $response->json('data.status'));
    }

    public function test_the_scheduler_is_unknown_until_it_reports_and_stale_when_it_stops(): void
    {
        $this->getJson('/api/v1/health')->assertJsonPath('data.checks.scheduler.status', 'unknown');

        Cache::put(HealthController::HEARTBEAT_KEY, now()->toIso8601String(), 600);
        $this->getJson('/api/v1/health')->assertJsonPath('data.checks.scheduler.status', 'ok');

        Cache::put(HealthController::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String(), 600);
        $this->getJson('/api/v1/health')
            ->assertJsonPath('data.checks.scheduler.status', 'stale')
            ->assertJsonPath('data.status', 'degraded');
    }

    public function test_a_malformed_scheduler_heartbeat_does_not_break_the_endpoint(): void
    {
        Cache::put(HealthController::HEARTBEAT_KEY, 'not-a-date', 600);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.checks.scheduler.status', 'unknown');
    }

    public function test_the_scheduler_heartbeat_is_registered_and_writes_the_cache_key(): void
    {
        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertIsString(Cache::get(HealthController::HEARTBEAT_KEY));
    }

    public function test_failed_jobs_are_counted_in_the_queue_check(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->getJson('/api/v1/health')->assertJsonPath('data.checks.queue.failed_jobs', 1);
    }

    public function test_the_ready_endpoint_is_public_and_reports_readiness(): void
    {
        $this->getJson('/api/v1/ready')
            ->assertOk()
            ->assertExactJson(['data' => ['ready' => true]]);
    }

    public function test_the_ready_endpoint_returns_503_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('select')->andThrow(new RuntimeException('no database'));

        $this->getJson('/api/v1/ready')
            ->assertStatus(503)
            ->assertExactJson(['data' => ['ready' => false]]);
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
