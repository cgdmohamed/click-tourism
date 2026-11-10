<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'true');
    }

    private function createBooking(array $overrides = [])
    {
        return app(BookingService::class)->create(array_merge([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'owner_user_id' => $this->user->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'end_date' => Carbon::now()->addDays(9)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [[
                'service_type' => 'hotel',
                'description' => 'Hotel nights',
                'quantity' => 2,
                'unit_cost_minor' => 50000,
                'unit_sell_minor' => 100000,
            ]],
        ], $overrides), $this->user->id);
    }

    public function test_it_creates_a_draft_booking_with_totals_and_history(): void
    {
        $booking = $this->createBooking();

        $this->assertSame('draft', $booking->status);
        $this->assertSame(200000, $booking->total_sell_minor);
        $this->assertSame(100000, $booking->total_cost_minor);
        $this->assertSame(100000, $booking->marginMinor());
        $this->assertCount(1, $booking->items);
        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $booking->id,
            'to_status' => 'draft',
        ]);
    }

    public function test_confirming_a_booking_posts_revenue_and_completion_posts_cost(): void
    {
        $bookings = app(BookingService::class);
        $booking = $this->createBooking();

        $bookings->transition($booking, 'confirmed', 'Deposit received', $this->user->id);

        $revenue = JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'revenue')
            ->firstOrFail();

        $this->assertSame(200000, (int) $revenue->lines()->sum('debit_minor'));
        $this->assertSame(200000, (int) $revenue->lines()->sum('credit_minor'));
        $this->assertSame(
            200000,
            (int) $revenue->lines()->whereHas('account', fn ($query) => $query->where('code', '1100'))->sum('debit_minor'),
        );

        $bookings->transition($booking->refresh(), 'completed', 'Trip finished', $this->user->id);

        $cost = JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'cost')
            ->firstOrFail();

        $this->assertSame(100000, (int) $cost->lines()->sum('debit_minor'));
        $this->assertSame(
            100000,
            (int) $cost->lines()->whereHas('account', fn ($query) => $query->where('code', '2100'))->sum('credit_minor'),
        );
    }

    public function test_cancelling_reverses_posted_revenue(): void
    {
        $bookings = app(BookingService::class);
        $booking = $this->createBooking();

        $bookings->transition($booking, 'confirmed', null, $this->user->id);
        $bookings->cancel($booking->refresh(), 'Customer changed plans', 0, $this->user->id);

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);

        $revenue = JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'revenue')
            ->firstOrFail();

        $reversal = JournalEntry::query()->where('reversal_of_id', $revenue->id)->firstOrFail();

        $this->assertSame('revenue.reversal', $reversal->event);
        $this->assertSame(
            (int) $revenue->lines()->sum('debit_minor'),
            (int) $reversal->lines()->sum('credit_minor'),
        );
    }

    public function test_it_rejects_an_invalid_lifecycle_transition(): void
    {
        $booking = $this->createBooking();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot move a booking');

        app(BookingService::class)->transition($booking, 'closed', null, $this->user->id);
    }

    public function test_negative_margin_requires_segregated_manager_approval_before_confirmation(): void
    {
        $service = app(BookingService::class);
        $booking = $this->createBooking(['items' => [[
            'service_type' => 'hotel',
            'description' => 'Loss-making hotel package',
            'quantity' => 1,
            'unit_cost_minor' => 120000,
            'unit_sell_minor' => 100000,
        ]]]);

        try {
            $service->transition($booking, 'confirmed', null, $this->user->id);
            $this->fail('Confirmation should have required approval.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('manager approval', $exception->getMessage());
        }

        $service->requestMarginApproval($booking, 'Strategic customer recovery', $this->user->id);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('requester cannot approve');
        $service->approveMargin($booking->refresh(), $this->user->id);
    }

    public function test_a_different_manager_can_approve_negative_margin_and_the_approval_is_audited(): void
    {
        $service = app(BookingService::class);
        $booking = $this->createBooking(['items' => [[
            'service_type' => 'hotel', 'description' => 'Approved loss', 'quantity' => 1,
            'unit_cost_minor' => 120000, 'unit_sell_minor' => 100000,
        ]]]);
        $manager = User::factory()->create();
        $manager->branches()->attach($this->branch->id);

        $service->requestMarginApproval($booking, 'Documented exception', $this->user->id);
        $approved = $service->approveMargin($booking->refresh(), $manager->id);
        $confirmed = $service->transition($approved, 'confirmed', 'Approved exception', $this->user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('approved', $confirmed->margin_approval_status);
        $this->assertSame($manager->id, $confirmed->margin_approved_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'booking.margin_approved', 'actor_id' => $manager->id]);
    }
}
