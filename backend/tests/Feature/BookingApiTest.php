<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permissions);
    }

    private function taxProfile(string $rate = '14.0000', bool $active = true): TaxProfile
    {
        return TaxProfile::query()->create([
            'name' => 'VAT '.$rate,
            'rate' => $rate,
            'is_inclusive' => false,
            'effective_from' => Carbon::now()->startOfYear()->toDateString(),
            'is_active' => $active,
        ]);
    }

    private function booking(array $item = []): Booking
    {
        return app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [array_merge([
                'service_type' => 'hotel',
                'description' => 'Hotel nights',
                'quantity' => 2,
                'unit_cost_minor' => 50000,
                'unit_sell_minor' => 100000,
            ], $item)],
        ], $this->user->id);
    }

    public function test_show_returns_history_allowed_transitions_and_linked_counts(): void
    {
        $this->grant('bookings.view');
        $booking = $this->booking();
        app(BookingService::class)->transition($booking, 'quoted', 'sent to customer', $this->user->id);
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/bookings/'.$booking->public_id)->assertOk();

        $response->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.allowed_transitions', ['confirmed', 'cancelled', 'no_show'])
            ->assertJsonPath('data.balance_minor', 200000)
            ->assertJsonPath('data.paid_minor', 0)
            ->assertJsonPath('data.payments_count', 0)
            ->assertJsonPath('data.invoices_count', 0)
            ->assertJsonCount(2, 'data.status_history')
            ->assertJsonPath('data.status_history.1.from', 'draft')
            ->assertJsonPath('data.status_history.1.to', 'quoted')
            ->assertJsonPath('data.status_history.1.reason', 'sent to customer');
        $this->assertNotEmpty($response->json('data.status_history.1.at'));
    }

    public function test_allowed_transitions_follow_the_service_table(): void
    {
        $this->assertSame(['quoted', 'confirmed', 'cancelled'], BookingService::allowedTransitionsFor('draft'));
        $this->assertSame([], BookingService::allowedTransitionsFor('cancelled'));
        $this->assertSame([], BookingService::allowedTransitionsFor('unknown'));
    }

    public function test_tax_profiles_endpoint_lists_active_profiles_with_public_ids(): void
    {
        $this->grant('bookings.view');
        $active = $this->taxProfile('14.0000');
        $this->taxProfile('5.0000', false);
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/tax-profiles')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->public_id)
            ->assertJsonPath('data.0.rate', '14.0000')
            ->assertJsonPath('data.0.is_inclusive', false);
        $this->assertNotEmpty($active->public_id);
    }

    public function test_tax_profiles_endpoint_requires_permission(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/tax-profiles')->assertStatus(403);
    }

    public function test_booking_items_accept_a_tax_profile_public_id(): void
    {
        $profile = $this->taxProfile('14.0000');
        $booking = $this->booking(['tax_profile_id' => $profile->public_id]);

        $this->assertSame(28000, $booking->tax_minor);
        $this->assertSame(228000, $booking->total_sell_minor);
        $this->assertSame($profile->id, $booking->items->first()->tax_profile_id);
    }

    public function test_booking_items_still_accept_the_internal_numeric_id(): void
    {
        $profile = $this->taxProfile('14.0000');
        $booking = $this->booking(['tax_profile_id' => $profile->id]);

        $this->assertSame(28000, $booking->tax_minor);
    }

    public function test_an_unknown_tax_profile_is_rejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->booking(['tax_profile_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    }

    public function test_item_payload_exposes_the_tax_profile(): void
    {
        $this->grant('bookings.view');
        $profile = $this->taxProfile('14.0000');
        $booking = $this->booking(['tax_profile_id' => $profile->public_id]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/bookings/'.$booking->public_id)
            ->assertOk()
            ->assertJsonPath('data.tax_minor', 28000)
            ->assertJsonPath('data.items.0.tax_profile_id', $profile->public_id)
            ->assertJsonPath('data.items.0.tax_rate', '14.0000')
            ->assertJsonPath('data.items.0.tax_minor', 28000);
    }

    public function test_store_endpoint_accepts_a_tax_profile_public_id(): void
    {
        $this->grant('bookings.view', 'bookings.create');
        $profile = $this->taxProfile('14.0000');
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/bookings', [
            'customer_id' => $this->customer()->public_id,
            'branch_id' => $this->branch->public_id,
            'start_date' => now()->addDays(3)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [[
                'service_type' => 'visa',
                'description' => 'Visa',
                'quantity' => 1,
                'unit_cost_minor' => 1000,
                'unit_sell_minor' => 10000,
                'tax_profile_id' => $profile->public_id,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.tax_minor', 1400)
            ->assertJsonPath('data.total_sell_minor', 11400);
    }

    public function test_cancel_endpoint_records_reason_and_fee_and_exposes_them(): void
    {
        $this->grant('bookings.view', 'bookings.cancel');
        $booking = $this->booking();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/bookings/'.$booking->public_id.'/cancel', [
            'reason' => 'Customer changed plans',
            'cancellation_fee_minor' => 5000,
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Customer changed plans')
            ->assertJsonPath('data.cancellation_fee_minor', 5000)
            ->assertJsonPath('data.allowed_transitions', []);
    }
}
