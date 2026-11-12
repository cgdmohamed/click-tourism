<?php

namespace Tests\Feature;

use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class TaxSettingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function settings(bool $enabled, string $rate = '0', bool $inclusive = false): void
    {
        $settings = app(SettingService::class);
        $settings->set('tax', 'enabled', $enabled ? 'true' : 'false');
        $settings->set('tax', 'default_rate', $rate);
        $settings->set('tax', 'inclusive', $inclusive ? 'true' : 'false');
    }

    private function booking(array $item = [])
    {
        return app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [array_merge([
                'service_type' => 'hotel',
                'description' => 'Hotel',
                'quantity' => 1,
                'unit_cost_minor' => 50000,
                'unit_sell_minor' => 100000,
            ], $item)],
        ], $this->user->id);
    }

    public function test_disabled_tax_adds_nothing(): void
    {
        $this->settings(false, '10');

        $booking = $this->booking();

        $this->assertSame(0, $booking->tax_minor);
        $this->assertSame(100000, $booking->total_sell_minor);
        $this->assertSame(0, $booking->items->first()->tax_minor);
    }

    public function test_exclusive_rate_is_added_on_top(): void
    {
        $this->settings(true, '10');

        $booking = $this->booking();

        $this->assertSame(10000, $booking->tax_minor);
        $this->assertSame(110000, $booking->total_sell_minor);
        $this->assertSame(10.0, (float) $booking->items->first()->tax_rate_snapshot);
    }

    public function test_inclusive_rate_is_extracted_from_the_price(): void
    {
        $this->settings(true, '14', true);

        $booking = $this->booking();

        // 100000 * 14 / 114 = 12280.7 -> 12281
        $this->assertSame(12281, $booking->tax_minor);
        $this->assertSame(100000, $booking->total_sell_minor);
    }

    public function test_enabled_with_zero_rate_adds_no_tax(): void
    {
        $this->settings(true, '0');

        $this->assertSame(0, $this->booking()->tax_minor);
    }

    public function test_a_legacy_tax_profile_id_is_ignored(): void
    {
        $this->settings(true, '10');
        $profile = TaxProfile::query()->create(['name' => 'Old 14', 'effective_from' => '2026-01-01', 'rate' => '14.0000', 'is_inclusive' => false, 'is_active' => true]);

        $item = $this->booking(['tax_profile_id' => $profile->public_id])->items->first();

        $this->assertSame(10000, $item->tax_minor);
        $this->assertNull($item->tax_profile_id);
    }

    public function test_settings_api_validates_the_tax_rate_and_flags(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'settings.update', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permission);
        Sanctum::actingAs($this->user);

        foreach (['101', '-1', 'abc', ''] as $bad) {
            $this->putJson('/api/v1/settings', ['settings' => [['group' => 'tax', 'key' => 'default_rate', 'value' => $bad]]])
                ->assertStatus(422);
        }
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'tax', 'key' => 'inclusive', 'value' => 'maybe']]])
            ->assertStatus(422);

        $this->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'tax', 'key' => 'default_rate', 'value' => '14'],
            ['group' => 'tax', 'key' => 'inclusive', 'value' => 'true'],
        ]])->assertOk();
        $this->assertSame('14', app(SettingService::class)->get('tax', 'default_rate'));
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.updated']);
    }
}
