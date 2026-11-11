<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DecisionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class DecisionResolutionTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_resolving_requires_settings_update(): void
    {
        $this->as('viewer');

        $this->postJson('/api/v1/decisions/DEC-001/resolve', ['status' => 'approved', 'note' => 'ok'])->assertStatus(403);
    }

    public function test_unknown_decision_and_missing_note_are_rejected(): void
    {
        $this->as('accountant');

        $this->postJson('/api/v1/decisions/DEC-999/resolve', ['status' => 'approved', 'note' => 'ok'])->assertStatus(404);
        $this->postJson('/api/v1/decisions/DEC-001/resolve', ['status' => 'approved'])->assertStatus(422);
        $this->postJson('/api/v1/decisions/DEC-001/resolve', ['status' => 'approved', 'note' => str_repeat('x', 1001)])->assertStatus(422);
        $this->postJson('/api/v1/decisions/DEC-001/resolve', ['status' => 'maybe', 'note' => 'ok'])->assertStatus(422);
    }

    public function test_an_approved_decision_leaves_the_unresolved_list_and_health_counts(): void
    {
        $user = $this->as('accountant');

        $this->postJson('/api/v1/decisions/DEC-001/resolve', ['status' => 'approved', 'option' => 'on_confirm', 'note' => 'Agreed with the accountant'])
            ->assertOk()
            ->assertJsonPath('data.resolution.status', 'approved')
            ->assertJsonPath('data.resolution.decided_by', $user->public_id)
            ->assertJsonPath('data.resolution.decided_by_name', $user->name);

        $this->assertDatabaseHas('settings', ['group' => 'decisions', 'key' => 'DEC-001']);
        $this->assertDatabaseHas('audit_events', ['action' => 'decision.resolved']);

        $ids = array_column($this->getJson('/api/v1/decisions')->assertOk()->json('data'), 'id');
        $this->assertNotContains('DEC-001', $ids);
        $this->assertNotContains('DEC-001', array_column((new DecisionRegistry)->unresolved(), 'id'));
        $this->assertNotContains('DEC-001', array_column($this->getJson('/api/v1/health')->json('data.unresolved_decisions') ?? [], 'id'));

        $all = collect($this->getJson('/api/v1/decisions?all=1')->json('data'))->keyBy('id');
        $this->assertSame('approved', $all['DEC-001']['resolution']['status']);
        $this->assertNull($all['DEC-002']['resolution']);
    }

    public function test_rejected_and_deferred_responses_stay_in_the_unresolved_list(): void
    {
        $this->as('accountant');

        $this->postJson('/api/v1/decisions/DEC-002/resolve', ['status' => 'deferred', 'note' => 'Next quarter'])->assertOk();
        $this->postJson('/api/v1/decisions/DEC-003/resolve', ['status' => 'rejected', 'note' => 'Needs rework'])->assertOk();

        $list = collect($this->getJson('/api/v1/decisions')->json('data'))->keyBy('id');
        $this->assertSame('deferred', $list['DEC-002']['resolution']['status']);
        $this->assertSame('rejected', $list['DEC-003']['resolution']['status']);
    }
}
