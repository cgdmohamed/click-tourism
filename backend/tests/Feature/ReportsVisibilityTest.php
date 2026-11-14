<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** W12 (owner decision 2026-11-14): reports are for the financial roles; sales, reservations and the cashier do not read them. */
class ReportsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $this->artisan('roles:sync')->assertSuccessful();
        $branch = Branch::query()->firstOrCreate(['code' => 'HQ'], ['name_ar' => 'الرئيسي', 'name_en' => 'HQ', 'accounting_mode' => 'dimension']);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'web'));
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function trialBalanceStatus(string $role): int
    {
        $user = $this->userWithRole($role);
        Sanctum::actingAs($user);
        $branch = $user->branches()->first();

        return $this->withHeaders(['X-Branch-Id' => $branch->public_id])->getJson('/api/v1/reports/trial-balance?from=2026-01-01&to=2026-12-31')->status();
    }

    public function test_financial_roles_and_the_viewer_keep_report_access(): void
    {
        foreach (['admin', 'finance_manager', 'senior_accountant', 'accountant', 'supervisor', 'viewer'] as $role) {
            $this->assertSame(200, $this->trialBalanceStatus($role), $role);
        }
    }

    public function test_sales_reservations_and_cashier_cannot_read_reports(): void
    {
        foreach (['sales', 'reservations', 'cashier'] as $role) {
            $this->assertSame(403, $this->trialBalanceStatus($role), $role);
        }
    }
}
