<?php

namespace Tests\Feature;

use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    private function settings(bool $enabled, string $defaultRate = '0'): void
    {
        app(SettingService::class)->set('tax', 'enabled', $enabled ? 'true' : 'false');
        app(SettingService::class)->set('tax', 'default_rate', $defaultRate);
    }

    private function profile(string $rate = '14.0000'): TaxProfile
    {
        return TaxProfile::query()->create([
            'name' => 'VAT '.$rate,
            'rate' => $rate,
            'is_inclusive' => false,
            'effective_from' => Carbon::now()->startOfYear()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function booking(?int $profileId = null)
    {
        return app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [[
                'service_type' => 'hotel',
                'description' => 'Hotel',
                'quantity' => 1,
                'unit_cost_minor' => 50000,
                'unit_sell_minor' => 100000,
                'tax_profile_id' => $profileId,
            ]],
        ], $this->user->id);
    }

    public function test_disabled_tax_ignores_profile_and_default_rate(): void
    {
        $this->settings(false, '10');

        $booking = $this->booking($this->profile()->id);
        $item = $booking->items->first();

        $this->assertSame(0, $booking->tax_minor);
        $this->assertSame(100000, $booking->total_sell_minor);
        $this->assertNull($item->tax_profile_id);
        $this->assertSame(0, $item->tax_minor);
    }

    public function test_enabled_tax_applies_default_rate_when_no_profile(): void
    {
        $this->settings(true, '10');

        $booking = $this->booking();
        $item = $booking->items->first();

        $this->assertSame(10000, $booking->tax_minor);
        $this->assertSame(110000, $booking->total_sell_minor);
        $this->assertNull($item->tax_profile_id);
        $this->assertSame(10.0, (float) $item->tax_rate_snapshot);
    }

    public function test_enabled_with_zero_default_rate_adds_no_tax(): void
    {
        $this->settings(true, '0');

        $this->assertSame(0, $this->booking()->tax_minor);
    }

    public function test_profile_overrides_default_rate(): void
    {
        $this->settings(true, '10');

        $booking = $this->booking($this->profile('14.0000')->id);

        $this->assertSame(14000, $booking->tax_minor);
        $this->assertSame(114000, $booking->total_sell_minor);
        $this->assertSame(14.0, (float) $booking->items->first()->tax_rate_snapshot);
    }

    public function test_zero_rate_profile_means_explicitly_no_tax(): void
    {
        $this->settings(true, '10');

        $this->assertSame(0, $this->booking($this->profile('0.0000')->id)->tax_minor);
    }
}
