<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\TourDeparture;
use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Money\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class TourPackageTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        foreach (['bookings.view', 'bookings.create', 'bookings.update', 'bookings.transition'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['bookings.view', 'bookings.create', 'bookings.update', 'bookings.transition']);
        Sanctum::actingAs($this->user);
    }

    private function package(): TourPackage
    {
        return TourPackage::query()->create([
            'branch_id' => $this->branch->id,
            'code' => 'TEST-TRIP',
            'name' => 'Winter tour',
            'category' => 'domestic',
            'execution_type' => 'fixed',
            'currency_code' => 'EGP',
            'base_price_minor' => 100000,
            'estimated_cost_minor' => 70000,
        ]);
    }

    public function test_can_create_a_generic_program_and_departure(): void
    {
        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->postJson('/api/v1/tour-packages', [
                'name' => 'Egypt winter trip',
                'category' => 'domestic',
                'execution_type' => 'fixed',
                'currency' => 'EGP',
                'base_price_minor' => 150000,
                'estimated_cost_minor' => 100000,
            ])->assertCreated()->assertJsonPath('data.name', 'Egypt winter trip');
        $this->assertStringStartsWith('TOUR-', $response->json('data.code'));
        $id = $response->json('data.id');
        $this->postJson("/api/v1/tour-packages/{$id}/departures", [
            'starts_on' => now()->addWeek()->toDateString(),
            'ends_on' => now()->addWeeks(2)->toDateString(),
            'capacity' => 4,
        ])->assertCreated()->assertJsonPath('data.available_seats', 4);
        $this->getJson("/api/v1/tour-packages/{$id}")
            ->assertOk()->assertJsonCount(1, 'data.departures');
    }

    public function test_new_packages_cannot_use_a_deactivated_currency(): void
    {
        Currency::query()->updateOrCreate(['code' => 'SAR'], ['name' => 'Saudi Riyal', 'exponent' => 2, 'is_active' => false]);

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->postJson('/api/v1/tour-packages', [
                'name' => 'Inactive currency trip', 'category' => 'domestic', 'execution_type' => 'fixed',
                'currency' => 'SAR', 'base_price_minor' => 1000, 'estimated_cost_minor' => 500,
            ])->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_package_code_is_generated_unique_and_cannot_be_supplied_by_client(): void
    {
        $payload = [
            'code' => 'USER-SUPPLIED-CODE',
            'name' => 'Custom trip',
            'category' => 'international',
            'execution_type' => 'private',
            'currency' => 'EGP',
            'base_price_minor' => 50000,
            'estimated_cost_minor' => 40000,
        ];

        $first = $this->postJson('/api/v1/tour-packages', $payload)->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/tour-packages', $payload)->assertCreated()->json('data');
        $this->assertStringStartsWith('TOUR-', $first['code']);
        $this->assertNotSame($first['code'], $second['code']);
        $this->assertDatabaseMissing('tour_packages', ['code' => 'USER-SUPPLIED-CODE']);
    }

    public function test_confirmed_seats_are_counted_and_overbooking_is_rejected(): void
    {
        $package = $this->package();
        $departure = TourDeparture::query()->create([
            'tour_package_id' => $package->id,
            'branch_id' => $this->branch->id,
            'starts_on' => now()->addWeek()->toDateString(),
            'capacity' => 2,
            'status' => 'open',
        ]);
        $service = app(BookingService::class);
        $newBooking = function () use ($departure, $service) {
            return $service->create([
                'customer_id' => $this->customer()->public_id,
                'branch_id' => $this->branch->public_id,
                'tour_departure_id' => $departure->public_id,
                'start_date' => $departure->starts_on->toDateString(),
                'currency' => 'EGP',
                'pax_adults' => 2,
                'items' => [[
                    'service_type' => 'program',
                    'description' => 'Winter tour package',
                    'quantity' => 2,
                    'unit_cost_minor' => 2000,
                    'unit_sell_minor' => 3000,
                    'cost_currency' => 'EGP',
                    'sell_currency' => 'EGP',
                ]],
            ], $this->user->id);
        };
        $first = $newBooking();
        $service->transition($first, 'confirmed', null, $this->user->id);
        $this->assertSame(0, $departure->occupiedSeats() - 2);
        $this->assertSame(0, $departure->toApiArray()['available_seats']);

        $second = $newBooking();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Not enough seats remain');
        $service->transition($second, 'confirmed', null, $this->user->id);
    }

    public function test_departure_cannot_be_linked_to_booking_from_another_branch(): void
    {
        $package = $this->package();
        $departure = TourDeparture::query()->create([
            'tour_package_id' => $package->id,
            'branch_id' => $this->branch->id,
            'starts_on' => now()->addWeek()->toDateString(),
            'capacity' => 2,
            'status' => 'open',
        ]);
        $other = Branch::query()->create([
            'code' => 'OTHER-TRAVEL',
            'name_ar' => 'فرع آخر',
            'name_en' => 'Another branch',
            'is_active' => true,
        ]);
        $this->user->branches()->attach($other->id);
        $this->expectException(\DomainException::class);
        app(BookingService::class)->create([
            'customer_id' => $this->customer()->public_id,
            'branch_id' => $other->public_id,
            'tour_departure_id' => $departure->public_id,
            'start_date' => $departure->starts_on->toDateString(),
            'currency' => 'EGP',
            'items' => [[
                'service_type' => 'other',
                'description' => 'Travel service',
                'quantity' => 1,
                'unit_cost_minor' => 0,
                'unit_sell_minor' => 1000,
                'cost_currency' => 'EGP',
                'sell_currency' => 'EGP',
            ]],
        ], $this->user->id);
    }
}
