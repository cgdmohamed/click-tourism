<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Role directory, permission catalog and custom role lifecycle (work order 01). */
class RoleManagementTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);
    }

    private function grant(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo($permissions);
    }

    public function test_roles_view_lists_the_nine_system_roles(): void
    {
        $response = $this->getJson('/api/v1/roles')->assertOk();

        $roles = collect($response->json('data'));
        $this->assertCount(9, $roles);
        $this->assertTrue($roles->every(fn (array $role) => $role['is_system'] === true));

        $names = $roles->pluck('name')->values()->all();
        $this->assertSame(collect($names)->sort()->values()->all(), $names);

        $admin = $roles->firstWhere('name', 'admin');
        $this->assertSame('web', $admin['guard']);
        $this->assertSame(1, $admin['users_count']); // the acting admin
        $this->assertContains('users.manage', $admin['permissions']);
        $this->assertContains('roles.manage', $admin['permissions']);

        $financeManager = $roles->firstWhere('name', 'finance_manager');
        $this->assertContains('users.view', $financeManager['permissions']);
        $this->assertNotContains('users.manage', $financeManager['permissions']);
    }

    public function test_the_role_directory_and_permission_catalog_require_roles_view(): void
    {
        $viewer = User::factory()->create();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->getJson('/api/v1/permissions')->assertForbidden();
    }

    public function test_the_permission_catalog_lists_every_name_sorted(): void
    {
        $response = $this->getJson('/api/v1/permissions')->assertOk();

        $names = $response->json('data');
        $this->assertSame(Permission::query()->where('guard_name', 'web')->count(), count($names));
        $this->assertSame(collect($names)->sort()->values()->all(), $names);
        $this->assertContains('users.view', $names);
        $this->assertContains('users.manage', $names);
        $this->assertContains('roles.view', $names);
        $this->assertContains('roles.manage', $names);
    }

    public function test_roles_manage_creates_custom_roles(): void
    {
        $response = $this->postJson('/api/v1/roles', [
            'name' => 'night_auditor',
            'permissions' => ['customers.view', 'bookings.view'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'night_auditor')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.users_count', 0);
        $this->assertCount(2, $response->json('data.permissions'));

        $role = Role::findByName('night_auditor', 'web');
        $this->assertTrue($role->hasPermissionTo('customers.view'));
        $this->assertTrue($role->hasPermissionTo('bookings.view'));
        $this->assertFalse($role->hasPermissionTo('journal.view'));

        $this->assertNotEmpty(AuditEvent::query()->where('action', 'role.created')->get());

        $this->postJson('/api/v1/roles', ['name' => 'night_auditor', 'permissions' => ['customers.view']])->assertStatus(422);
        $this->postJson('/api/v1/roles', ['name' => 'Night Auditor', 'permissions' => ['customers.view']])->assertStatus(422);
        $this->postJson('/api/v1/roles', ['name' => 'unknown_role', 'permissions' => ['customers.view', 'no.such_permission']])->assertStatus(422);
    }

    public function test_custom_roles_cannot_exceed_the_actors_privileges(): void
    {
        $manager = User::factory()->create();
        $this->grant($manager, ['roles.view', 'roles.manage', 'customers.view']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/roles', [
            'name' => 'over_reach',
            'permissions' => ['bookings.view'],
        ])->assertStatus(403)->assertJsonPath('message', 'You may not assign privileges you do not hold.');

        $this->assertDatabaseMissing('roles', ['name' => 'over_reach']);

        $this->postJson('/api/v1/roles', [
            'name' => 'scoped_clerk',
            'permissions' => ['customers.view'],
        ])->assertCreated();
    }

    public function test_custom_roles_can_be_updated_and_deleted(): void
    {
        $role = Role::query()->create(['name' => 'night_auditor', 'guard_name' => 'web']);
        $role->syncPermissions(['customers.view']);

        $response = $this->patchJson('/api/v1/roles/'.$role->id, [
            'name' => 'night_clerk',
            'permissions' => ['customers.view', 'bookings.view'],
        ])->assertOk()->assertJsonPath('data.name', 'night_clerk');
        $this->assertCount(2, $response->json('data.permissions'));

        $this->assertTrue(Role::findByName('night_clerk', 'web')->hasPermissionTo('bookings.view'));
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'role.updated')->get());

        $holder = User::factory()->create();
        $holder->assignRole('night_clerk');

        $this->deleteJson('/api/v1/roles/'.$role->id)->assertStatus(422)
            ->assertJsonPath('message', 'This role is assigned to users and cannot be deleted.');

        $holder->syncRoles([]);
        $this->deleteJson('/api/v1/roles/'.$role->id)->assertOk();
        $this->assertDatabaseMissing('roles', ['name' => 'night_clerk']);
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'role.deleted')->get());
    }

    public function test_system_roles_cannot_be_modified_or_deleted(): void
    {
        $viewer = Role::findByName('viewer', 'web');
        $admin = Role::findByName('admin', 'web');

        $this->patchJson('/api/v1/roles/'.$viewer->id, ['permissions' => ['customers.view']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This role is a system role and cannot be modified or deleted.');

        $this->deleteJson('/api/v1/roles/'.$viewer->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This role is a system role and cannot be modified or deleted.');

        $this->deleteJson('/api/v1/roles/'.$admin->id)->assertStatus(422);

        $this->assertNotNull(Role::findByName('viewer', 'web'));
        $this->assertNotNull(Role::findByName('admin', 'web'));
    }
}
