<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions for a single installation (ADR-0005).
 *
 * Permission names are part of the API contract: routes/api.php checks them
 * by name, so any change here must be reflected in the route table.
 */
class RolePermissionSeeder extends Seeder
{
    /** @var array<string, string[]> */
    private const ROLES = [
        'admin' => ['*'],
        'accountant' => [
            'branches.view',
            'settings.view', 'settings.update',
            'accounts.view', 'accounts.create', 'accounts.update', 'accounts.post',
            'periods.view', 'periods.manage', 'periods.post_soft_closed',
            'journal.view', 'journal.create', 'journal.reverse',
            'customers.view', 'customers.update',
            'suppliers.view', 'suppliers.update',
            'supplier_obligations.view', 'supplier_obligations.create',
            'supplier_payments.view', 'supplier_payments.create',
            'bookings.view',
            'bookings.approve_margin',
            'payments.view', 'payments.create', 'payments.manage',
            'refunds.create', 'refunds.approve',
            'reports.view', 'reports.export',
            'cash_accounts.manage', 'transfers.view', 'transfers.create', 'transfers.reverse',
            'expense_categories.manage', 'expenses.view', 'expenses.create', 'expenses.approve',
            'opening_balances.view', 'opening_balances.manage', 'opening_balances.post', 'opening_balances.reverse',
            'periods.close', 'periods.reopen',
            'invoices.view', 'invoices.create', 'invoices.approve',
            'cost_centers.view', 'cost_centers.manage',
            'documents.view', 'documents.create', 'documents.approve',
            'reconciliation.view', 'reconciliation.manage', 'reconciliation.approve',
            'schedules.view', 'schedules.manage', 'schedules.approve',
            'assets.view', 'assets.manage', 'assets.approve',
            'employees.view', 'employees.manage',
            'customers.create',
            'suppliers.create',
        ],
        'sales' => [
            'expenses.view', 'expenses.create',
            'branches.view',
            'accounts.view',
            'customers.view', 'customers.create', 'customers.update',
            'suppliers.view', 'suppliers.create', 'suppliers.update',
            'supplier_obligations.view', 'supplier_payments.view',
            'bookings.view', 'bookings.create', 'bookings.transition', 'bookings.cancel',
            'payments.view', 'payments.create',
            'refunds.create',
            'reports.view',
            'leads.view', 'leads.create', 'leads.update', 'leads.convert', 'leads.manage_rules',
        ],
        'viewer' => [
            'branches.view',
            'accounts.view',
            'periods.view',
            'journal.view',
            'customers.view',
            'suppliers.view',
            'supplier_obligations.view', 'supplier_payments.view',
            'bookings.view',
            'payments.view',
            'reports.view',
            'leads.view',
            'transfers.view', 'expenses.view', 'opening_balances.view',
            'reconciliation.view',
            'schedules.view',
            'assets.view',
        ],
    ];

    private const PERMISSIONS = [
        'branches.view', 'branches.create', 'branches.view_all',
        'settings.view', 'settings.update',
        'accounts.view', 'accounts.create', 'accounts.update', 'accounts.post',
        'periods.view', 'periods.manage', 'periods.post_soft_closed',
        'journal.view', 'journal.create', 'journal.reverse',
        'customers.view', 'customers.create', 'customers.update',
        'suppliers.view', 'suppliers.create', 'suppliers.update',
        'supplier_obligations.view', 'supplier_obligations.create',
        'supplier_payments.view', 'supplier_payments.create',
        'bookings.view', 'bookings.create', 'bookings.transition', 'bookings.cancel', 'bookings.approve_margin',
        'payments.view', 'payments.create', 'payments.manage',
        'refunds.create', 'refunds.approve',
        'reports.view', 'reports.export',
        'leads.view', 'leads.create', 'leads.update', 'leads.convert', 'leads.manage_rules',
        'cash_accounts.manage', 'transfers.view', 'transfers.create', 'transfers.reverse',
        'expense_categories.manage', 'expenses.view', 'expenses.create', 'expenses.approve',
        'opening_balances.view', 'opening_balances.manage', 'opening_balances.post', 'opening_balances.reverse',
        'periods.close', 'periods.reopen',
        'invoices.view', 'invoices.create', 'invoices.approve',
        'cost_centers.view', 'cost_centers.manage',
        'employees.view', 'employees.manage',
        'documents.view', 'documents.create', 'documents.approve',
        'reconciliation.view', 'reconciliation.manage', 'reconciliation.approve',
        'schedules.view', 'schedules.manage', 'schedules.approve',
        'assets.view', 'assets.manage', 'assets.approve',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if ($permissions === ['*']) {
                $role->syncPermissions(self::PERMISSIONS);

                continue;
            }

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
