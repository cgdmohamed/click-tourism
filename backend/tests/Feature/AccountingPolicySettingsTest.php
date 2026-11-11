<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class AccountingPolicySettingsTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        // Fresh installation: policy not approved.
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'false');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create(['name' => 'Policy Actor']);
        $user->branches()->attach($this->branch->id);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function putSettings(array $settings)
    {
        return $this->putJson('/api/v1/settings', ['settings' => $settings]);
    }

    private function row(string $group, string $key, string $value): array
    {
        return ['group' => $group, 'key' => $key, 'value' => $value];
    }

    public function test_valid_mapping_is_saved(): void
    {
        $this->actAs('accountant');

        $this->putSettings([$this->row('accounting', 'ar_account_code', '1120')])->assertOk();

        $this->assertSame('1120', app(SettingService::class)->get('accounting', 'ar_account_code'));
    }

    public function test_mapping_rejects_unknown_wrong_type_inactive_and_non_postable(): void
    {
        $this->actAs('admin');
        Account::query()->where('code', '1154')->update(['is_active' => false]);
        Account::query()->where('code', '1160')->update(['is_postable' => false]);

        $this->putSettings([$this->row('accounting', 'ar_account_code', '9999')])
            ->assertStatus(422)->assertJsonValidationErrors('ar_account_code');
        $this->putSettings([$this->row('accounting', 'ar_account_code', '2100')])
            ->assertStatus(422)->assertJsonValidationErrors('ar_account_code');
        $this->putSettings([$this->row('accounting', 'prepaid_expense_account_code', '1154')])
            ->assertStatus(422)->assertJsonValidationErrors('prepaid_expense_account_code');
        // 1150 is the non-postable prepayment group header since the 1150 split.
        $this->putSettings([$this->row('accounting', 'prepaid_expense_account_code', '1150')])
            ->assertStatus(422)->assertJsonValidationErrors('prepaid_expense_account_code');
        $this->putSettings([$this->row('accounting', 'input_tax_account_code', '1160')])
            ->assertStatus(422)->assertJsonValidationErrors('input_tax_account_code');
        $this->putSettings([$this->row('accounting', 'ar_account_code', '')])
            ->assertStatus(422)->assertJsonValidationErrors('ar_account_code');
    }

    public function test_assets_group_mapping_is_type_checked(): void
    {
        $this->actAs('admin');

        $this->putSettings([$this->row('assets', 'depreciation_expense_account_code', '1300')])
            ->assertStatus(422)->assertJsonValidationErrors('depreciation_expense_account_code');
        $this->putSettings([$this->row('assets', 'depreciation_expense_account_code', '6800')])->assertOk();
    }

    public function test_every_default_mapping_passes_with_seeded_chart(): void
    {
        $this->actAs('admin');
        $rows = [];
        foreach (SettingService::ACCOUNT_TYPES as $group => $keys) {
            foreach (array_keys($keys) as $key) {
                $rows[] = $this->row($group, $key, SettingService::DEFAULTS[$group][$key]);
            }
        }

        $this->putSettings($rows)->assertOk();
    }

    public function test_invalid_mapping_rejects_whole_request_atomically(): void
    {
        $this->actAs('admin');

        $this->putSettings([
            $this->row('general', 'company_name_en', 'Changed'),
            $this->row('accounting', 'ar_account_code', '9999'),
        ])->assertStatus(422);

        $this->assertNotSame('Changed', app(SettingService::class)->get('general', 'company_name_en'));
    }

    public function test_policy_change_requires_permission(): void
    {
        $user = $this->actAs('accountant');
        $user->syncRoles([]);
        $user->givePermissionTo('settings.update');

        $this->putSettings([$this->row('accounting', 'accounting_policy_approved', 'true')])->assertStatus(403);
        $this->putSettings([$this->row('accounting', 'auto_post_bookings', 'true')])->assertStatus(403);

        $this->assertFalse(app(SettingService::class)->bool('accounting', 'accounting_policy_approved'));
        $this->assertFalse(app(SettingService::class)->bool('accounting', 'auto_post_bookings'));
    }

    public function test_unchanged_policy_value_does_not_need_permission(): void
    {
        $user = $this->actAs('accountant');
        $user->syncRoles([]);
        $user->givePermissionTo('settings.update');

        $this->putSettings([$this->row('accounting', 'accounting_policy_approved', 'false')])->assertOk();
    }

    public function test_seeded_roles_hold_the_permission(): void
    {
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('accounting.approve_policy'));
        $this->assertTrue(Role::findByName('accountant')->hasPermissionTo('accounting.approve_policy'));
        $this->assertFalse(Role::findByName('sales')->hasPermissionTo('accounting.approve_policy'));
    }

    public function test_approve_and_revoke_are_audited_with_actor(): void
    {
        $user = $this->actAs('accountant');
        $settings = app(SettingService::class);

        $this->putSettings([$this->row('accounting', 'accounting_policy_approved', 'true')])->assertOk();

        $this->assertTrue($settings->bool('accounting', 'accounting_policy_approved'));
        $this->assertSame('Policy Actor', $settings->get('accounting', 'approved_by'));
        $this->assertNotEmpty($settings->get('accounting', 'approved_at'));

        $event = AuditEvent::query()->where('action', 'accounting.policy_changed')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('false', $event->before['value']);
        $this->assertSame('true', $event->after['value']);
        $this->assertSame('accounting_policy_approved', $event->after['key']);

        $this->putSettings([$this->row('accounting', 'accounting_policy_approved', 'false')])->assertOk();
        $this->assertSame('', $settings->get('accounting', 'approved_by'));
        $this->assertSame(2, AuditEvent::query()->where('action', 'accounting.policy_changed')->count());
    }

    public function test_auto_post_change_is_audited(): void
    {
        $this->actAs('admin');

        $this->putSettings([$this->row('accounting', 'auto_post_bookings', 'true')])->assertOk();

        $event = AuditEvent::query()->where('action', 'accounting.policy_changed')->firstOrFail();
        $this->assertSame('auto_post_bookings', $event->after['key']);
        $this->assertSame('false', $event->before['value']);
    }

    public function test_policy_value_must_be_boolean_string(): void
    {
        $this->actAs('admin');

        $this->putSettings([$this->row('accounting', 'accounting_policy_approved', 'maybe')])
            ->assertStatus(422)->assertJsonValidationErrors('accounting_policy_approved');
    }

    public function test_get_settings_shape_is_unchanged(): void
    {
        $this->actAs('accountant');

        $row = $this->getJson('/api/v1/settings')->assertOk()->json('data.0');

        $this->assertSame(['group', 'key', 'value', 'is_secret'], array_keys($row));
    }
}
