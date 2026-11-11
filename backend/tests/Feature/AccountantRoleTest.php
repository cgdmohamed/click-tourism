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

    /** Work order 01 — the four additional system roles seeded additively. */
    public function test_the_multi_user_foundation_roles_are_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(9, Role::query()->where('guard_name', 'web')->count());

        foreach (['finance_manager', 'reservations', 'supervisor', 'cashier'] as $name) {
            $this->assertSame(1, Role::query()->where('name', $name)->count(), $name);
        }

        // Only the admin holds the management permissions initially.
        $admin = Role::findByName('admin');
        $this->assertTrue($admin->hasPermissionTo('users.view'));
        $this->assertTrue($admin->hasPermissionTo('users.manage'));
        $this->assertTrue($admin->hasPermissionTo('roles.view'));
        $this->assertTrue($admin->hasPermissionTo('roles.manage'));

        $financeManager = Role::findByName('finance_manager');
        $this->assertTrue($financeManager->hasPermissionTo('users.view'));
        $this->assertTrue($financeManager->hasPermissionTo('roles.view'));
        $this->assertTrue($financeManager->hasPermissionTo('journal.post_own_drafts'));
        $this->assertTrue($financeManager->hasPermissionTo('reconciliation.approve'));
        $this->assertFalse($financeManager->hasPermissionTo('users.manage'));
        $this->assertFalse($financeManager->hasPermissionTo('journal.delete_drafts'));

        $reservations = Role::findByName('reservations');
        $this->assertTrue($reservations->hasPermissionTo('bookings.transition'));
        $this->assertTrue($reservations->hasPermissionTo('customers.create'));
        $this->assertFalse($reservations->hasPermissionTo('payments.reverse'));
        $this->assertFalse($reservations->hasPermissionTo('journal.view'));

        $supervisor = Role::findByName('supervisor');
        $this->assertTrue($supervisor->hasPermissionTo('employees.view'));
        $this->assertTrue($supervisor->hasPermissionTo('reports.export'));
        $this->assertTrue($supervisor->hasPermissionTo('users.view'));
        $this->assertTrue($supervisor->hasPermissionTo('customers.create')); // inherited from @sales
        $this->assertFalse($supervisor->hasPermissionTo('users.manage'));

        $cashier = Role::findByName('cashier');
        $this->assertTrue($cashier->hasPermissionTo('payments.reverse'));
        $this->assertTrue($cashier->hasPermissionTo('transfers.create'));
        $this->assertFalse($cashier->hasPermissionTo('journal.view'));
        $this->assertFalse($cashier->hasPermissionTo('customers.create'));
    }
}
