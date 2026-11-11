<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `roles:sync` is the upgrade entry point for installations created before a
 * release introduced new permissions (README upgrade runbook).
 */
class RoleSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_sync_creates_and_reattaches_missing_permissions(): void
    {
        $this->artisan('roles:sync')
            ->expectsOutputToContain('Roles and permissions synchronised')
            ->assertSuccessful();

        $admin = Role::findByName('admin', 'web');
        $this->assertTrue($admin->hasPermissionTo('reports.export'));
        $permissionCount = Permission::query()->count();
        $roleCount = Role::query()->count();
        $this->assertGreaterThan(0, $permissionCount);

        // Simulate an older install: the release permission is absent.
        Permission::query()->where('name', 'reports.export')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::query()->where('name', 'reports.export')->exists());

        $this->artisan('roles:sync')->assertSuccessful();

        $this->assertTrue(Permission::query()->where('name', 'reports.export')->exists());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('reports.export'));

        // Idempotent: re-running never duplicates rows.
        $this->artisan('roles:sync')->assertSuccessful();
        $this->assertSame($permissionCount, Permission::query()->count());
        $this->assertSame($roleCount, Role::query()->count());
    }
}
