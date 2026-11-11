<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountantRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_accountant_role_has_people_and_party_management_permissions_idempotently(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $accountant = Role::findByName('accountant');

        foreach (['employees.view', 'employees.manage', 'customers.create', 'customers.update', 'suppliers.create', 'suppliers.update', 'bookings.view'] as $permission) {
            $this->assertTrue($accountant->hasPermissionTo($permission), $permission);
        }

        $this->assertSame(1, Role::query()->where('name', 'accountant')->count());
        $this->assertFalse(Role::findByName('viewer')->hasPermissionTo('customers.create'));
        $this->assertFalse(Role::findByName('sales')->hasPermissionTo('employees.view'));
    }
}
