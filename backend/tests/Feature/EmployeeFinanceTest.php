<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\EmployeeAdvance;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class EmployeeFinanceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'employees.view', 'employees.manage',
        'journal.view', 'journal.create',
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

        $this->seed(TreasurySeeder::class);

        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function employee(array $overrides = []): array
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/employees', $overrides + [
            'internal_code' => 'EMP-1',
            'name_ar' => 'موظف تجريبي',
            'name_en' => 'Test Employee',
        ])->assertCreated()->json('data');
    }

    private function balance(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    public function test_employee_can_be_created_and_deactivated_only_when_balance_is_zero(): void
    {
        $emp = $this->employee();

        $this->withHeaders($this->headers())->postJson("/api/v1/employees/{$emp['id']}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Reactivate then create an advance -> deactivation blocked.
        $this->withHeaders($this->headers())->patchJson("/api/v1/employees/{$emp['id']}", ['is_active' => true])
            ->assertOk();

        $advance = $this->requestAdvance($emp['id'], 100000)->assertCreated()->json('data');
        $this->approveAdvance($emp['id'], $advance['id'])->assertOk();
        $this->disburseAdvance($emp['id'], $advance['id'])->assertOk();

        $this->withHeaders($this->headers())->postJson("/api/v1/employees/{$emp['id']}/deactivate")
            ->assertStatus(422);

        $this->assertTrue(Employee::query()->where('public_id', $emp['id'])->firstOrFail()->is_active);
    }

    public function test_advance_disburse_settle_expense_and_cash_return_leaves_zero_outstanding(): void
    {
        $emp = $this->employee();
        $advance = $this->requestAdvance($emp['id'], 100000)->assertCreated()->json('data');
        $this->approveAdvance($emp['id'], $advance['id'])->assertOk();
        $this->disburseAdvance($emp['id'], $advance['id'])->assertOk()->assertJsonPath('data.status', 'disbursed');

        // GL: employee advance debited 100,000, cash credited 100,000.
        $advanceAccount = (string) app(SettingService::class)->get('accounting', 'employee_advance_account_code', '1150');
        $this->assertSame(100000, $this->balance($advanceAccount));
        $this->assertSame(-100000, $this->balance('1110')); // cash on hand

        // Settle 60,000 via expense receipt + return 40,000 cash.
        $settled = $this->withHeaders($this->headers())->postJson(
            "/api/v1/employees/{$emp['id']}/advances/{$advance['id']}/settle",
            [
                'currency' => 'EGP',
                'settlement_amount_minor' => 60000,
                'expense_lines' => [
                    ['account_id' => $this->account('6600')->public_id, 'amount_minor' => 60000, 'description' => 'Travel receipts'],
                ],
                'cash_return_minor' => 40000,
                'cash_account_id' => $this->cashAccountPublicId(),
            ]
        )->assertOk()->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.outstanding_minor', 0);

        // GL check: advance cleared, transport expense debited 60,000, cash debited 40,000.
        $this->assertSame(0, $this->balance($advanceAccount));
        $this->assertSame(60000, $this->balance('6600'));
        $this->assertSame(-60000, $this->balance('1110')); // 100k out, 40k back

        // Employee subledger: outstanding = 0.
        $statement = $this->withHeaders($this->headers())->getJson(
            "/api/v1/employees/{$emp['id']}/statement?from=".now()->startOfMonth()->toDateString().
            '&to='.now()->endOfMonth()->toDateString()
        )->assertOk()->json('data');

        $this->assertSame(0, $statement['closing_balance_minor']);
    }

    public function test_advance_lifecycle_states_and_cancel(): void
    {
        $emp = $this->employee();
        $advance = $this->requestAdvance($emp['id'], 50000)->assertCreated()->json('data');
        $this->assertSame('requested', $advance['status']);

        $this->approveAdvance($emp['id'], $advance['id'])->assertOk()->assertJsonPath('data.status', 'approved');

        // Cancel after disbursement is rejected.
        $this->disburseAdvance($emp['id'], $advance['id'])->assertOk();
        $this->cancelAdvance($emp['id'], $advance['id'])->assertStatus(422);

        // Cancel a plain requested advance.
        $advance2 = $this->requestAdvance($emp['id'], 20000)->assertCreated()->json('data');
        $this->cancelAdvance($emp['id'], $advance2['id'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_several_custodies_and_partial_clearings_can_be_posted(): void
    {
        $emp = $this->employee();
        $issue = fn () => $this->withHeaders($this->headers())->postJson('/api/v1/employees/'.$emp['id'].'/custody', [
            'amount_minor' => 50000,
            'currency' => 'EGP',
            'cash_account_id' => $this->cashAccountPublicId(),
        ])->assertCreated()->json('data');

        $first = $issue();
        $second = $issue();
        $this->assertNotSame($first['id'], $second['id']);

        foreach ([10000, 15000] as $amount) {
            $this->withHeaders($this->headers())->postJson(
                "/api/v1/employees/{$emp['id']}/custody/{$first['id']}/clear",
                ['currency' => 'EGP', 'cleared_amount_minor' => $amount,
                    'expense_lines' => [['account_id' => $this->account('6600')->public_id, 'amount_minor' => $amount, 'description' => 'Fuel']]]
            )->assertOk();
        }

        $this->assertSame(25000, $this->balance('6600'));
    }

    public function test_custody_issue_clear_return(): void
    {
        $emp = $this->employee();
        $custody = $this->withHeaders($this->headers())->postJson('/api/v1/employees/'.$emp['id'].'/custody', [
            'amount_minor' => 50000,
            'currency' => 'EGP',
            'cash_account_id' => $this->cashAccountPublicId(),
            'item_description' => 'Petty cash float',
        ])->assertCreated()->assertJsonPath('data.status', 'issued')->json('data');

        $custodyAccount = (string) app(SettingService::class)->get('accounting', 'employee_custody_account_code', '1150');
        $this->assertSame(50000, $this->balance($custodyAccount));
        $this->assertSame(-50000, $this->balance('1110'));

        // Clear 30,000 of custody against expense (status becomes cleared, outstanding 20,000).
        $this->withHeaders($this->headers())->postJson(
            "/api/v1/employees/{$emp['id']}/custody/{$custody['id']}/clear",
            ['currency' => 'EGP', 'cleared_amount_minor' => 30000,
                'expense_lines' => [['account_id' => $this->account('6600')->public_id, 'amount_minor' => 30000, 'description' => 'Fuel']]]
        )->assertOk()->assertJsonPath('data.status', 'cleared')
            ->assertJsonPath('data.outstanding_minor', 20000);

        $this->assertSame(20000, $this->balance($custodyAccount));
        $this->assertSame(30000, $this->balance('6600'));

        // Return remaining 20,000 cash.
        $this->withHeaders($this->headers())->postJson(
            "/api/v1/employees/{$emp['id']}/custody/{$custody['id']}/return",
            ['currency' => 'EGP', 'cash_account_id' => $this->cashAccountPublicId()]
        )->assertOk()->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.outstanding_minor', 0);

        $this->assertSame(0, $this->balance($custodyAccount));
        $this->assertSame(-30000, $this->balance('1110')); // 50k out, 20k back
    }

    public function test_two_employees_share_gl_account_but_statements_reconcile_separately(): void
    {
        $e1 = $this->employee(['internal_code' => 'EMP-A', 'name_en' => 'Employee A']);
        $e2 = $this->employee(['internal_code' => 'EMP-B', 'name_en' => 'Employee B']);

        $a1 = $this->requestAdvance($e1['id'], 100000)->assertCreated()->json('data');
        $this->approveAdvance($e1['id'], $a1['id'])->assertOk();
        $this->disburseAdvance($e1['id'], $a1['id'])->assertOk();

        $a2 = $this->requestAdvance($e2['id'], 40000)->assertCreated()->json('data');
        $this->approveAdvance($e2['id'], $a2['id'])->assertOk();
        $this->disburseAdvance($e2['id'], $a2['id'])->assertOk();

        $advanceAccount = (string) app(SettingService::class)->get('accounting', 'employee_advance_account_code', '1150');
        $this->assertSame(140000, $this->balance($advanceAccount));

        $s1 = $this->withHeaders($this->headers())->getJson(
            "/api/v1/employees/{$e1['id']}/statement?from=".now()->startOfMonth()->toDateString().
            '&to='.now()->endOfMonth()->toDateString()
        )->assertOk()->json('data');
        $s2 = $this->withHeaders($this->headers())->getJson(
            "/api/v1/employees/{$e2['id']}/statement?from=".now()->startOfMonth()->toDateString().
            '&to='.now()->endOfMonth()->toDateString()
        )->assertOk()->json('data');

        $this->assertSame(100000, $s1['closing_balance_minor']);
        $this->assertSame(40000, $s2['closing_balance_minor']);
    }

    public function test_settlement_exceeds_outstanding_is_rejected(): void
    {
        $emp = $this->employee();
        $advance = $this->requestAdvance($emp['id'], 100000)->assertCreated()->json('data');
        $this->approveAdvance($emp['id'], $advance['id'])->assertOk();
        $this->disburseAdvance($emp['id'], $advance['id'])->assertOk();

        $this->withHeaders($this->headers())->postJson(
            "/api/v1/employees/{$emp['id']}/advances/{$advance['id']}/settle",
            ['currency' => 'EGP', 'settlement_amount_minor' => 150000,
                'expense_lines' => [['account_id' => $this->account('6600')->public_id, 'amount_minor' => 150000, 'description' => 'x']]]
        )->assertStatus(422);

        $this->assertSame(100000, EmployeeAdvance::query()->where('public_id', $advance['id'])->firstOrFail()->outstanding_minor);
    }

    private function requestAdvance(string $employeeId, int $amount): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/employees/'.$employeeId.'/advances', [
            'employee_id' => $employeeId,
            'amount_minor' => $amount,
        ]);
    }

    private function advanceUrl(string $employeeId, string $advanceId, string $action): string
    {
        return "/api/v1/employees/{$employeeId}/advances/{$advanceId}/{$action}";
    }

    private function approveAdvance(string $employeeId, string $advanceId): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson($this->advanceUrl($employeeId, $advanceId, 'approve'));
    }

    private function disburseAdvance(string $employeeId, string $advanceId): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson($this->advanceUrl($employeeId, $advanceId, 'disburse'), [
            'cash_account_id' => $this->cashAccountPublicId(),
        ]);
    }

    private function cancelAdvance(string $employeeId, string $advanceId): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson($this->advanceUrl($employeeId, $advanceId, 'cancel'));
    }

    private function cashAccountPublicId(): string
    {
        return CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->first()->public_id;
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }
}
