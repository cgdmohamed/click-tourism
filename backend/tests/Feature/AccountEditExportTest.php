<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class AccountEditExportTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        $permissions = ['accounts.view', 'accounts.update', 'periods.manage'];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permissions);
        Sanctum::actingAs($this->user);
    }

    public function test_chart_of_accounts_export_works(): void
    {
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->get('/api/v1/accounts/export')
            ->assertOk()
            ->assertSee('code,name_ar,name_en,type,parent_code,is_postable,is_active,currency_code,classification');
    }

    public function test_account_with_history_can_be_renamed_but_not_retyped(): void
    {
        $cash = Account::query()->where('code', '1110')->firstOrFail();
        $this->seedCash(1000);

        $this->patchJson("/api/v1/accounts/{$cash->public_id}", [
            'name_en' => 'Petty cash',
            'is_postable' => true,
        ])->assertOk()->assertJsonPath('data.name_en', 'Petty cash');

        $this->patchJson("/api/v1/accounts/{$cash->public_id}", ['type' => 'expense'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_has_history');
    }

    public function test_account_used_by_settings_cannot_be_deactivated_retyped_or_made_non_postable(): void
    {
        $ar = Account::query()->where('code', '1100')->firstOrFail();

        $this->patchJson("/api/v1/accounts/{$ar->public_id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonPath('code', 'account_used_by_settings');
        $this->patchJson("/api/v1/accounts/{$ar->public_id}", ['type' => 'revenue'])
            ->assertStatus(422)->assertJsonPath('code', 'account_used_by_settings');
        $this->patchJson("/api/v1/accounts/{$ar->public_id}", ['is_postable' => false])
            ->assertStatus(422)->assertJsonPath('code', 'account_used_by_settings');

        // Renaming is still allowed: the mapping is by code, not by name.
        $this->patchJson("/api/v1/accounts/{$ar->public_id}", ['name_en' => 'Trade receivables'])
            ->assertOk()->assertJsonPath('data.name_en', 'Trade receivables');
    }

    public function test_overlapping_fiscal_year_is_rejected(): void
    {
        $this->postJson('/api/v1/fiscal-periods', [
            'name' => 'Overlap',
            'starts_on' => now()->startOfYear()->addMonths(6)->toDateString(),
            'ends_on' => now()->startOfYear()->addMonths(18)->toDateString(),
        ])->assertStatus(422);
    }

    private function seedCash(int $amount): void
    {
        $this->postingService()->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account('1110'), $amount),
                PostingLine::credit($this->account('3200'), $amount),
            ],
        ));
    }

    private function postingService(): PostingService
    {
        return app(PostingService::class);
    }
}
