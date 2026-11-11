<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class CostDoubleRecognitionTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'true');
    }

    private function createBooking(): Booking
    {
        return app(BookingService::class)->create([
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
                'supplier_id' => $this->supplier()->public_id,
            ]],
        ], $this->user->id);
    }

    private function approvedSupplierInvoice(?Booking $booking): Invoice
    {
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $this->supplier()->public_id,
            'booking_id' => $booking?->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [[
                'account_id' => $this->account('5100')->public_id,
                'description' => 'Hotel cost',
                'debit_minor' => 100000,
            ]],
        ], $this->branch, $this->user->id);

        $invoice = $invoices->submit($invoice, $this->user->id);

        return $invoices->approve($invoice, $this->user->id);
    }

    private function costEntries(Booking $booking)
    {
        return JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'cost');
    }

    private function confirm(Booking $booking): void
    {
        app(BookingService::class)->transition($booking, 'confirmed', null, $this->user->id);
    }

    private function complete(Booking $booking): void
    {
        app(BookingService::class)->transition($booking->refresh(), 'completed', null, $this->user->id);
    }

    private function assertIssueRejected(Invoice $invoice): void
    {
        try {
            app(InvoiceService::class)->issue($invoice, $this->user->id);
            $this->fail('Expected a DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cost was already recognized for booking', $e->getMessage());
        }
    }

    public function test_issuing_a_linked_supplier_invoice_is_rejected_after_booking_cost_was_posted(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);
        $this->complete($booking);
        $this->assertSame(1, $this->costEntries($booking)->count());

        $invoice = $this->approvedSupplierInvoice($booking);
        $this->assertIssueRejected($invoice);

        $this->assertSame('approved', $invoice->refresh()->status);
        $this->assertNull($invoice->journal_entry_id);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_booking_cost_is_not_posted_when_a_linked_supplier_invoice_is_already_issued(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);

        $invoice = $this->approvedSupplierInvoice($booking);
        app(InvoiceService::class)->issue($invoice, $this->user->id);
        $this->assertSame('issued', $invoice->refresh()->status);

        $this->complete($booking);

        $this->assertSame('completed', $booking->refresh()->status);
        $this->assertSame(0, $this->costEntries($booking)->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count());
    }

    public function test_reversing_the_booking_cost_allows_the_invoice_to_be_issued(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);
        $this->complete($booking);

        $invoice = $this->approvedSupplierInvoice($booking);
        $this->assertIssueRejected($invoice);

        app(PostingService::class)->reverse($this->costEntries($booking)->firstOrFail(), 'Cost posted in error', $this->user->id);

        $issued = app(InvoiceService::class)->issue($invoice->refresh(), $this->user->id);

        $this->assertSame('issued', $issued->status);
        $this->assertNotNull($issued->journal_entry_id);
    }

    public function test_cancelling_the_booking_reverses_cost_and_allows_the_invoice_to_be_issued(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);
        app(BookingService::class)->postCost($booking->refresh()->load('items'), $this->user->id);
        $this->assertSame(1, $this->costEntries($booking)->count());

        $invoice = $this->approvedSupplierInvoice($booking);
        $this->assertIssueRejected($invoice);

        app(BookingService::class)->cancel($booking->refresh(), 'Customer changed plans', 0, $this->user->id);

        $issued = app(InvoiceService::class)->issue($invoice->refresh(), $this->user->id);
        $this->assertSame('issued', $issued->status);
    }

    public function test_a_reversed_supplier_invoice_does_not_block_booking_cost(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);

        $invoice = $this->approvedSupplierInvoice($booking);
        $issued = app(InvoiceService::class)->issue($invoice, $this->user->id);
        app(PostingService::class)->reverse($issued->journalEntry, 'Invoice issued in error', $this->user->id);

        $this->complete($booking);

        $this->assertSame(1, $this->costEntries($booking)->count());
    }

    public function test_unlinked_supplier_invoice_is_unaffected_by_booking_cost(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);
        $this->complete($booking);
        $this->assertSame(1, $this->costEntries($booking)->count());

        $issued = app(InvoiceService::class)->issue($this->approvedSupplierInvoice(null), $this->user->id);

        $this->assertSame('issued', $issued->status);
    }

    public function test_unlinked_supplier_invoice_does_not_suppress_booking_cost(): void
    {
        app(InvoiceService::class)->issue($this->approvedSupplierInvoice(null), $this->user->id);

        $booking = $this->createBooking();
        $this->confirm($booking);
        $this->complete($booking);

        $this->assertSame(1, $this->costEntries($booking)->count());
    }

    public function test_linked_supplier_invoice_issues_when_only_booking_revenue_is_recognized(): void
    {
        $booking = $this->createBooking();
        $this->confirm($booking);

        $issued = app(InvoiceService::class)->issue($this->approvedSupplierInvoice($booking), $this->user->id);

        $this->assertSame('issued', $issued->status);
    }
}
