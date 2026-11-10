<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Read-only 360° party profiles (Prompt 27).
 *
 * The profile payload must expose the party master record, operational
 * documents and the genuine subsidiary-ledger statement without changing
 * balances or existing interfaces.
 */
class PartyProfileTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'customers.view', 'suppliers.view', 'employees.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    public function test_customer_profile_includes_statement_and_operational_lists(): void
    {
        $customer = $this->customer();

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/customers/{$customer->public_id}")
            ->assertOk()
            ->assertJsonPath('data.party_type', 'customer')
            ->assertJsonPath('data.party.id', $customer->public_id)
            ->assertJsonStructure([
                'data' => [
                    'party',
                    'metrics' => ['bookings_count', 'invoices_count', 'payments_count', 'last_activity_on'],
                    'bookings',
                    'invoices',
                    'payments',
                    'statement' => [
                        'party', 'currency', 'opening_minor', 'closing_minor',
                        'reconciliation', 'subaccounts', 'lines', 'total',
                    ],
                ],
            ]);
    }

    public function test_supplier_profile_includes_obligations_and_statement(): void
    {
        $supplier = $this->supplier();

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/suppliers/{$supplier->public_id}")
            ->assertOk()
            ->assertJsonPath('data.party_type', 'supplier')
            ->assertJsonPath('data.party.id', $supplier->public_id)
            ->assertJsonStructure([
                'data' => [
                    'party',
                    'metrics' => ['invoices_count', 'obligations_count', 'open_obligations_count', 'payments_count'],
                    'invoices',
                    'obligations',
                    'payments',
                    'statement' => ['party', 'currency', 'reconciliation', 'lines'],
                ],
            ]);
    }

    public function test_employee_profile_includes_financial_activity_only(): void
    {
        $employee = Employee::query()->create([
            'internal_code' => 'EMP-360',
            'name_ar' => 'موظف 360',
            'name_en' => 'Employee 360',
            'category' => Employee::CATEGORY_STAFF,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/employees/{$employee->public_id}")
            ->assertOk()
            ->assertJsonPath('data.party_type', 'employee')
            ->assertJsonPath('data.party.id', $employee->public_id)
            ->assertJsonStructure([
                'data' => [
                    'party',
                    'employee' => ['id', 'internal_code', 'balance_minor'],
                    'metrics' => ['advances_count', 'custodies_count', 'transactions_count'],
                    'advances',
                    'custodies',
                    'transactions',
                    'statement' => ['party', 'currency', 'reconciliation', 'lines'],
                ],
            ]);
    }

    public function test_profile_rejects_a_customer_from_another_branch(): void
    {
        $other = Branch::query()->create([
            'code' => 'BR2',
            'name_ar' => 'فرع آخر',
            'name_en' => 'Other branch',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $foreign = Customer::query()->create([
            'type' => 'individual',
            'name_ar' => 'عميل فرع آخر',
            'name_en' => 'Foreign customer',
            'branch_id' => $other->id,
            'credit_limit_minor' => 0,
            'currency_code' => 'EGP',
            'is_active' => true,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/customers/{$foreign->public_id}")
            ->assertForbidden();
    }
}
