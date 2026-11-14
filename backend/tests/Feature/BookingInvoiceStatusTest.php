<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** W2 (owner decision 2026-11-14): a "ready to invoice" worklist and a read-only invoice status on the booking. */
class BookingInvoiceStatusTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        // A sales-like user: can see bookings, cannot see invoices.
        Permission::query()->firstOrCreate(['name' => 'bookings.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        /** @var User $user */
        $user = $this->user;
        $user->givePermissionTo('bookings.view');
        Sanctum::actingAs($user);
    }

    private function booking(string $status): Booking
    {
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [['service_type' => 'hotel', 'description' => 'Hotel', 'quantity' => 1, 'unit_cost_minor' => 0, 'unit_sell_minor' => 100000]],
        ], $this->user->id);

        return $status === 'draft' ? $booking : app(BookingService::class)->transition($booking, $status, null, $this->user->id);
    }

    private function rows(string $url): array
    {
        return $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->getJson($url)->assertOk()->json('data');
    }

    public function test_the_worklist_lists_live_bookings_without_an_invoice(): void
    {
        $confirmed = $this->booking('confirmed');
        $this->booking('draft');
        $invoiced = $this->booking('confirmed');
        $invoice = app(InvoiceService::class)->createFromBooking($invoiced, $this->user->id);

        $ready = collect($this->rows('/api/v1/bookings?needs_invoice=1'))->pluck('number')->all();
        $this->assertSame([$confirmed->number], $ready);

        // A voided invoice puts the booking back on the worklist.
        $invoice->update(['status' => Invoice::STATUS_VOIDED]);
        $this->assertEqualsCanonicalizing([$confirmed->number, $invoiced->number], collect($this->rows('/api/v1/bookings?needs_invoice=1'))->pluck('number')->all());
        $this->assertCount(3, $this->rows('/api/v1/bookings'));
    }

    public function test_a_booking_carries_its_invoice_status_for_users_who_cannot_list_invoices(): void
    {
        $booking = $this->booking('confirmed');
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->getJson('/api/v1/invoices')->assertForbidden();

        $this->assertNull($this->rows('/api/v1/bookings')[0]['invoice']);

        $invoice = app(InvoiceService::class)->createFromBooking($booking, $this->user->id);
        $row = $this->rows('/api/v1/bookings')[0];
        $this->assertSame($invoice->number, $row['invoice']['number']);
        $this->assertSame('draft', $row['invoice']['status']);
        $this->assertSame(['number', 'status', 'currency', 'total_minor', 'outstanding_minor'], array_keys($row['invoice']));

        $detail = $this->rows('/api/v1/bookings/'.$booking->public_id);
        $this->assertSame($invoice->number, $detail['invoice']['number']);
    }

    public function test_a_credit_note_on_the_booking_does_not_hide_its_invoice_or_bring_it_back_to_the_worklist(): void
    {
        $booking = $this->booking('confirmed');
        $invoice = app(InvoiceService::class)->createFromBooking($booking, $this->user->id);
        // A newer row of another type for the same booking, as creditNote() writes it.
        Invoice::query()->create([
            'type' => Invoice::TYPE_CREDIT_NOTE, 'series' => 'CN', 'number' => 'CN-T-1', 'party_type' => 'customer', 'party_id' => $invoice->party_id,
            'booking_id' => $booking->id, 'branch_id' => $this->branch->id, 'issue_date' => now()->toDateString(), 'currency_code' => 'EGP',
            'fx_rate' => '1', 'fx_rate_date' => now()->toDateString(), 'status' => Invoice::STATUS_ISSUED,
            'subtotal_minor' => 1000, 'tax_minor' => 0, 'total_minor' => 1000, 'outstanding_minor' => 0,
        ]);

        $row = $this->rows('/api/v1/bookings')[0];
        $this->assertSame($invoice->number, $row['invoice']['number']);
        $this->assertSame([], $this->rows('/api/v1/bookings?needs_invoice=1'));
        $this->assertSame($invoice->number, $this->rows('/api/v1/bookings/'.$booking->public_id)['invoice']['number']);
    }
}
