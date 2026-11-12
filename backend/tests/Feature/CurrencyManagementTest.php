<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\Money\Models\Currency;
use App\Modules\Money\Models\FxRate;
use App\Modules\Money\Services\CurrencyService;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class CurrencyManagementTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        Money::flushExponentCache();
    }

    private function actAs(?string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->branches()->attach($this->branch->id);
        if ($role !== null) {
            $user->assignRole($role);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function make(string $code = 'TND', int $exponent = 3): void
    {
        $this->postJson('/api/v1/currencies', ['code' => $code, 'name' => 'Tunisian Dinar', 'symbol' => 'DT', 'exponent' => $exponent])
            ->assertCreated();
    }

    private function useIt(string $code = 'TND'): void
    {
        FxRate::query()->create(['currency_code' => $code, 'rate' => '3.2', 'rate_date' => '2026-01-01', 'source' => 'manual']);
    }

    public function test_create_uppercases_the_code_and_is_audited(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/currencies', ['code' => ' tnd ', 'name' => 'Tunisian Dinar', 'symbol' => 'DT', 'exponent' => 3])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TND')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.in_use', false);

        $this->assertDatabaseHas('currencies', ['code' => 'TND', 'exponent' => 3]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'currency.created')->count());
        // The stored exponent is honoured by money maths for codes that are not built in.
        $this->assertSame(3, Money::exponent('TND'));
    }

    public function test_create_validates_code_and_rejects_duplicates(): void
    {
        $this->actAs();

        foreach (['US', 'USDX', 'U1D', '12$'] as $bad) {
            $this->postJson('/api/v1/currencies', ['code' => $bad, 'name' => 'X', 'exponent' => 2])
                ->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/v1/currencies', ['code' => 'usd', 'name' => 'Dup', 'exponent' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/currencies', ['code' => 'ABC', 'name' => 'X', 'exponent' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('exponent');
    }

    public function test_code_is_immutable_but_name_and_symbol_are_editable(): void
    {
        $this->actAs();
        $this->make();

        $this->patchJson('/api/v1/currencies/TND', ['code' => 'TNX'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('currencies', ['code' => 'TNX']);

        $this->patchJson('/api/v1/currencies/tnd', ['code' => 'TND', 'name' => 'Dinar', 'symbol' => 'TD'])
            ->assertOk()->assertJsonPath('data.name', 'Dinar')->assertJsonPath('data.symbol', 'TD');
        $this->assertSame(1, AuditEvent::query()->where('action', 'currency.updated')->count());
    }

    public function test_built_in_currencies_keep_their_fixed_exponent(): void
    {
        $this->actAs();

        // KWD is built in with 3 decimals: the UI formatter, Money, imports and exports all agree on it.
        Currency::query()->updateOrCreate(['code' => 'KWD'], ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'exponent' => 3, 'is_active' => true]);
        Money::flushExponentCache();

        $this->patchJson('/api/v1/currencies/KWD', ['exponent' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('exponent');
        $this->assertSame(3, (int) Currency::query()->find('KWD')->exponent);
        $this->patchJson('/api/v1/currencies/KWD', ['exponent' => 3, 'name' => 'Kuwaiti Dinar (KWD)'])->assertOk();

        Currency::query()->where('code', 'BHD')->delete();
        $this->postJson('/api/v1/currencies', ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'exponent' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('exponent');
        $this->postJson('/api/v1/currencies', ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'exponent' => 3])->assertCreated();
        $this->assertSame(3, Money::exponent('KWD'));
    }

    public function test_exponent_is_editable_only_while_unused(): void
    {
        $this->actAs();
        $this->make('TND', 3);

        $this->patchJson('/api/v1/currencies/TND', ['exponent' => 2])->assertOk()->assertJsonPath('data.exponent', 2);

        $this->useIt();

        $this->patchJson('/api/v1/currencies/TND', ['exponent' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('exponent');
        $this->assertSame(2, (int) Currency::query()->find('TND')->exponent);
        // Re-sending the unchanged exponent while editing other fields is fine.
        $this->patchJson('/api/v1/currencies/TND', ['exponent' => 2, 'name' => 'Renamed'])->assertOk();
    }

    public function test_unused_currency_can_be_deleted_and_is_audited(): void
    {
        $this->actAs();
        $this->make();

        $this->deleteJson('/api/v1/currencies/TND')->assertOk();

        $this->assertDatabaseMissing('currencies', ['code' => 'TND']);
        $this->assertSame(1, AuditEvent::query()->where('action', 'currency.deleted')->count());
    }

    public function test_used_currency_cannot_be_deleted_but_can_be_deactivated_and_reactivated(): void
    {
        $this->actAs();
        $this->make();
        $this->useIt();

        $this->deleteJson('/api/v1/currencies/TND')
            ->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->assertDatabaseHas('currencies', ['code' => 'TND']);

        $this->patchJson('/api/v1/currencies/TND', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        // Deactivated currencies stay in the list (old documents keep their currency).
        $codes = collect($this->getJson('/api/v1/currencies')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('TND', $codes->all());
        $this->patchJson('/api/v1/currencies/TND', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertSame(1, AuditEvent::query()->where('action', 'currency.deactivated')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'currency.activated')->count());
    }

    public function test_is_used_detects_references_in_other_tables(): void
    {
        $service = app(CurrencyService::class);
        $this->assertFalse($service->isUsed('KWD'));

        DB::table('accounts')->where('id', Account::query()->value('id'))->update(['currency_code' => 'KWD']);

        $this->assertTrue($service->isUsed('KWD'));
        $this->assertContains('accounts.currency_code', $service->usedBy('KWD'));
    }

    public function test_base_currency_cannot_be_deleted_or_deactivated(): void
    {
        $this->actAs();

        $this->deleteJson('/api/v1/currencies/EGP')->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->patchJson('/api/v1/currencies/EGP', ['is_active' => false])->assertStatus(422)->assertJsonValidationErrors('is_active');
        $this->assertTrue((bool) Currency::query()->find('EGP')->is_active);
    }

    public function test_write_endpoints_need_settings_update_and_index_is_unchanged(): void
    {
        $this->actAs(null);

        $this->postJson('/api/v1/currencies', ['code' => 'TND', 'name' => 'X', 'exponent' => 2])->assertForbidden();
        $this->patchJson('/api/v1/currencies/USD', ['name' => 'X'])->assertForbidden();
        $this->deleteJson('/api/v1/currencies/USD')->assertForbidden();
        $this->getJson('/api/v1/currencies/USD')->assertForbidden();

        $row = $this->getJson('/api/v1/currencies')->assertOk()->json('data.0');
        $keys = array_keys($row);
        sort($keys);
        $this->assertSame(['code', 'exponent', 'is_active', 'name', 'symbol'], $keys);
    }

    public function test_unknown_currency_is_404(): void
    {
        $this->actAs();
        $this->deleteJson('/api/v1/currencies/ZZZ')->assertNotFound();
        $this->patchJson('/api/v1/currencies/ZZZ', ['name' => 'X'])->assertNotFound();
    }

    public function test_base_currency_setting_must_be_an_active_currency(): void
    {
        $this->actAs();
        $this->make('TND', 3);
        $this->patchJson('/api/v1/currencies/TND', ['is_active' => false])->assertOk();

        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'general', 'key' => 'base_currency', 'value' => 'TND']]])
            ->assertStatus(422);
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'general', 'key' => 'base_currency', 'value' => 'QQQ']]])
            ->assertStatus(422);
    }
}
