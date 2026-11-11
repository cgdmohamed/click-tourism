<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Parties\Services\PartyLedgerService;
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
            (int) $receipt->lines()->whereHas('account', fn ($query) => $query->where('code', 'like', '1100%'))->sum('credit_minor'),
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

    public function test_later_allocation_posts_transfer_without_duplicate_on_retry(): void
    {
        $booking = $this->confirmedBooking();
        $payment = $this->record(['amount_minor' => 100000]);
        $service = app(PaymentService::class);
        $request = [['booking_id' => $booking->public_id, 'amount_minor' => 60000]];

        $payment = $service->allocate($payment, $request, $this->user->id);
        $this->assertSame(40000, $payment->unallocated_minor);
        $this->assertSame(60000, $booking->fresh()->paidMinor());

        $entry = JournalEntry::query()->where('source_type', 'payment_allocation')
            ->where('event', 'allocated:60000')->firstOrFail();
        $this->assertSame(60000, (int) $entry->lines()->whereHas('account',
            fn ($q) => $q->where('code', '2300'))->sum('debit_minor'));
        $this->assertSame(60000, (int) $entry->lines()->whereHas('account',
            fn ($q) => $q->where('code', 'like', '1100%'))->sum('credit_minor'));

        $service->allocate($payment, $request, $this->user->id);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'payment_allocation')->count());

        $payment = $service->allocate($payment, [
            ['booking_id' => $booking->public_id, 'amount_minor' => 80000],
        ], $this->user->id);
        $this->assertSame(20000, $payment->unallocated_minor);
        $this->assertSame(80000, $booking->fresh()->paidMinor());
        $this->assertSame(20000, (int) JournalEntry::query()
            ->where('source_type', 'payment_allocation')->where('event', 'allocated:80000')
            ->firstOrFail()->lines()->whereHas('account', fn ($q) => $q->where('code', '2300'))
            ->sum('debit_minor'));
    }

    public function test_unallocation_posts_opposite_transfer_and_keeps_receipt(): void
    {
        $booking = $this->confirmedBooking();
        $payment = $this->record(['amount_minor' => 100000]);
        $service = app(PaymentService::class);
        $service->allocate($payment, [
            ['booking_id' => $booking->public_id, 'amount_minor' => 70000],
        ], $this->user->id);

        $payment = $service->unallocate($payment, $booking->public_id, $this->user->id);
        $this->assertSame(100000, $payment->unallocated_minor);
        $this->assertSame(0, $booking->fresh()->paidMinor());
        $entry = JournalEntry::query()->where('source_type', 'payment_allocation')
            ->where('event', 'released')->firstOrFail();
        $this->assertSame(70000, (int) $entry->lines()->whereHas('account',
            fn ($q) => $q->where('code', 'like', '1100%'))->sum('debit_minor'));
        $this->assertSame(70000, (int) $entry->lines()->whereHas('account',
            fn ($q) => $q->where('code', '2300'))->sum('credit_minor'));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'payment')
            ->where('source_id', $payment->id)->count());

        $this->expectException(DomainException::class);
        $service->unallocate($payment, $booking->public_id, $this->user->id);
    }

    public function test_allocation_cannot_exceed_booking_balance_or_reduce_target(): void
    {
        $booking = $this->confirmedBooking(80000);
        $payment = $this->record(['amount_minor' => 100000]);
        $service = app(PaymentService::class);
        $service->allocate($payment, [
            ['booking_id' => $booking->public_id, 'amount_minor' => 60000],
        ], $this->user->id);

        try {
            $service->allocate($payment, [
                ['booking_id' => $booking->public_id, 'amount_minor' => 90000],
            ], $this->user->id);
            $this->fail('Over-allocation should be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('outstanding balance', $e->getMessage());
        }

        try {
            $service->allocate($payment, [
                ['booking_id' => $booking->public_id, 'amount_minor' => 20000],
            ], $this->user->id);
            $this->fail('Reducing an allocation should be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('unallocate it first', $e->getMessage());
        }
        $this->assertSame(60000, $booking->fresh()->paidMinor());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'payment_allocation')->count());
    }

    private function usdBooking(int $sellMinor = 100000, string $rate = '48')
    {
        $this->customer()->update(['currency_code' => 'USD']);
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'USD',
            'fx_rate' => $rate,
            'items' => [[
                'service_type' => 'hotel', 'description' => 'Hotel', 'quantity' => 1,
                'unit_cost_minor' => 0, 'unit_sell_minor' => $sellMinor,
            ]],
        ], $this->user->id);

        return app(BookingService::class)->transition($booking, 'confirmed', null, $this->user->id);
    }

    private function usdReceipt(int $amount, string $rate, ?string $bookingId = null): Payment
    {
        return $this->record([
            'amount_minor' => $amount, 'currency' => 'USD', 'fx_rate' => $rate,
            'booking_id' => $bookingId,
        ]);
    }

    /** @return array{0:int,1:int} [transaction-currency, base] net debit of the customer receivable subaccount */
    private function arNet(): array
    {
        $account = app(PartyLedgerService::class)->receivable($this->customer()->id);
        $lines = JournalLine::query()->where('account_id', $account->id)->where('currency_code', 'USD')->get();

        return [
            (int) $lines->sum('debit_minor') - (int) $lines->sum('credit_minor'),
            (int) JournalLine::query()->where('account_id', $account->id)->sum('base_debit_minor')
                - (int) JournalLine::query()->where('account_id', $account->id)->sum('base_credit_minor'),
        ];
    }

    private function assertLedgerBalanced(): void
    {
        $this->assertSame(
            (int) JournalLine::query()->sum('base_debit_minor'),
            (int) JournalLine::query()->sum('base_credit_minor'),
        );
    }

    private function fxNet(): int
    {
        $gain = (int) JournalLine::query()->whereHas('account', fn ($q) => $q->where('code', '7900'))->sum('base_credit_minor')
            - (int) JournalLine::query()->whereHas('account', fn ($q) => $q->where('code', '7900'))->sum('base_debit_minor');
        $loss = (int) JournalLine::query()->whereHas('account', fn ($q) => $q->where('code', '6900'))->sum('base_debit_minor')
            - (int) JournalLine::query()->whereHas('account', fn ($q) => $q->where('code', '6900'))->sum('base_credit_minor');

        return $gain - $loss;
    }

    public function test_direct_usd_receipt_above_booking_rate_books_realized_gain(): void
    {
        $booking = $this->usdBooking(100000, '48');
        $this->usdReceipt(100000, '50', $booking->public_id);

        $this->assertLedgerBalanced();
        $this->assertSame([0, 0], $this->arNet());
        $this->assertSame(200000, $this->fxNet());
    }

    public function test_direct_usd_receipt_below_booking_rate_books_realized_loss(): void
    {
        $booking = $this->usdBooking(100000, '48');
        $this->usdReceipt(100000, '46', $booking->public_id);

        $this->assertLedgerBalanced();
        $this->assertSame([0, 0], $this->arNet());
        $this->assertSame(-200000, $this->fxNet());
    }

    public function test_usd_advance_allocation_partial_and_unallocate_restore_exactly(): void
    {
        $booking = $this->usdBooking(100000, '48');
        $payment = $this->usdReceipt(100000, '50');
        $this->assertSame(0, $this->fxNet());
        $service = app(PaymentService::class);

        $service->allocate($payment, [['booking_id' => $booking->public_id, 'amount_minor' => 40000]], $this->user->id);
        $this->assertSame(80000, $this->fxNet());
        $this->assertSame([60000, 60000 * 48], $this->arNet());
        $this->assertLedgerBalanced();

        $service->allocate($payment, [['booking_id' => $booking->public_id, 'amount_minor' => 100000]], $this->user->id);
        $this->assertSame(200000, $this->fxNet());
        $this->assertSame([0, 0], $this->arNet());

        $service->unallocate($payment, $booking->public_id, $this->user->id);
        $this->assertSame(0, $this->fxNet());
        $this->assertSame([100000, 100000 * 48], $this->arNet());
        $this->assertLedgerBalanced();
    }

    public function test_usd_refunds_release_receivable_at_booking_rate_and_advance_at_receipt_rate(): void
    {
        $booking = $this->usdBooking(100000, '48');
        $payment = $this->usdReceipt(100000, '50');
        $service = app(PaymentService::class);
        $service->allocate($payment, [['booking_id' => $booking->public_id, 'amount_minor' => 60000]], $this->user->id);
        $this->assertSame(120000, $this->fxNet());

        // Allocated portion: receivable (48) released, cash leaves at 51 (51 vs 48).
        $refund = $service->requestRefund([
            'payment_id' => $payment->public_id, 'booking_id' => $booking->public_id,
            'amount_minor' => 60000, 'reason' => 'Cancel',
        ], $this->user->id);
        $service->approveRefund($refund, $this->user->id, '51');
        // Dr AR 60000*48, Cr cash 60000*51 -> loss 180000 on top of 120000 gain.
        $this->assertSame(120000 - 180000, $this->fxNet());
        $this->assertSame([100000, 100000 * 48], $this->arNet());
        $this->assertLedgerBalanced();

        // Advance portion: advance (50) released, cash leaves at payment rate by default -> no FX.
        $before = $this->fxNet();
        $refund2 = $service->requestRefund([
            'payment_id' => $payment->public_id, 'amount_minor' => 40000, 'reason' => 'Advance back',
        ], $this->user->id);
        $service->approveRefund($refund2, $this->user->id);
        $this->assertSame($before, $this->fxNet());
        $this->assertSame(0, (int) $payment->fresh()->unallocated_minor);
        $this->assertLedgerBalanced();
    }

    public function test_base_currency_flows_post_no_fx_lines(): void
    {
        $booking = $this->confirmedBooking();
        $this->record(['booking_id' => $booking->public_id, 'amount_minor' => 100000]);

        $this->assertSame(0, JournalLine::query()->whereHas('account', fn ($q) => $q->whereIn('code', ['7900', '6900']))->count());
    }
}
