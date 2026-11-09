<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function confirmedBooking(int $sellMinor = 200000)
    {
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [[
                'service_type' => 'hotel',
                'description' => 'Hotel nights',
                'quantity' => 1,
                'unit_cost_minor' => 0,
                'unit_sell_minor' => $sellMinor,
            ]],
        ], $this->user->id);

        return app(BookingService::class)->transition($booking, 'confirmed', null, $this->user->id);
    }

    private function record(array $overrides = [], ?string $key = null): Payment
    {
        return app(PaymentService::class)->record(array_merge([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'method' => 'cash',
            'amount_minor' => 100000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $overrides), $this->user->id, $key);
    }

    public function test_it_records_a_receipt_and_allocates_it_to_a_booking(): void
    {
        $booking = $this->confirmedBooking();

        $payment = $this->record([
            'booking_id' => $booking->public_id,
            'amount_minor' => 150000,
        ]);

        $this->assertSame(0, $payment->unallocated_minor);
        $this->assertCount(1, $payment->allocations);
        $this->assertSame(150000, (int) $payment->allocations->sum('amount_minor'));

        $receipt = JournalEntry::query()
            ->where('source_type', 'payment')
            ->where('source_id', $payment->id)
            ->where('event', 'receipt')
            ->firstOrFail();

        $this->assertSame(
            150000,
            (int) $receipt->lines()->whereHas('account', fn ($query) => $query->where('code', '1110'))->sum('debit_minor'),
        );
        $this->assertSame(
            150000,
            (int) $receipt->lines()->whereHas('account', fn ($query) => $query->where('code', '1100'))->sum('credit_minor'),
        );
    }

    public function test_unallocated_receipts_are_booked_as_customer_advances(): void
    {
        $payment = $this->record(['amount_minor' => 50000]);

        $this->assertSame(50000, $payment->unallocated_minor);

        $receipt = JournalEntry::query()
            ->where('source_type', 'payment')
            ->where('source_id', $payment->id)
            ->firstOrFail();

        $this->assertSame(
            50000,
            (int) $receipt->lines()->whereHas('account', fn ($query) => $query->where('code', '2300'))->sum('credit_minor'),
        );
    }

    public function test_it_rejects_an_allocation_above_the_outstanding_balance(): void
    {
        $booking = $this->confirmedBooking(100000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exceeds the outstanding balance');

        $this->record([
            'booking_id' => $booking->public_id,
            'amount_minor' => 150000,
        ]);
    }

    public function test_recording_with_the_same_idempotency_key_returns_the_same_payment(): void
    {
        $first = $this->record(['amount_minor' => 30000], 'demo-key');
        $second = $this->record(['amount_minor' => 30000], 'demo-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'payment')->count());
    }

    public function test_it_requests_and_approves_a_refund_against_the_payment(): void
    {
        $booking = $this->confirmedBooking();
        $payment = $this->record([
            'booking_id' => $booking->public_id,
            'amount_minor' => 100000,
        ]);

        $service = app(PaymentService::class);

        $refund = $service->requestRefund([
            'payment_id' => $payment->public_id,
            'amount_minor' => 40000,
            'reason' => 'Partial cancellation',
        ], $this->user->id);

        $this->assertSame('requested', $refund->status);

        $approved = $service->approveRefund($refund, $this->user->id);

        $this->assertSame('approved', $approved->status);

        $entry = JournalEntry::query()
            ->where('source_type', 'refund')
            ->where('source_id', $refund->id)
            ->firstOrFail();

        $this->assertSame(40000, (int) $entry->lines()->sum('debit_minor'));
        $this->assertSame(
            40000,
            (int) $entry->lines()->whereHas('account', fn ($query) => $query->where('code', '1110'))->sum('credit_minor'),
        );
    }

    public function test_it_rejects_a_refund_larger_than_the_payment(): void
    {
        $payment = $this->record(['amount_minor' => 50000]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exceeds the refundable amount');

        app(PaymentService::class)->requestRefund([
            'payment_id' => $payment->public_id,
            'amount_minor' => 60000,
            'reason' => 'Too much',
        ], $this->user->id);
    }
}
