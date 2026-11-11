<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\SupplierSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Document-level cancel actions and the journal-reverse guard for document entries. */
class DocumentCancellationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (['payments.reverse', 'supplier_payments.reverse'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['payments.reverse', 'supplier_payments.reverse']);
        Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    private function booking(int $sell = 200000): Booking
    {
        $bookings = app(BookingService::class);
        $booking = $bookings->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [['service_type' => 'hotel', 'description' => 'Hotel', 'quantity' => 1, 'unit_cost_minor' => 0, 'unit_sell_minor' => $sell]],
        ], $this->user->id);

        return $bookings->transition($booking, 'confirmed', null, $this->user->id);
    }

    private function receipt(int $amount, ?Booking $booking = null): Payment
    {
        return app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'booking_id' => $booking?->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'method' => 'cash',
            'amount_minor' => $amount,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->user->id);
    }

    private function supplierPay(array $overrides = []): SupplierPayment
    {
        return app(SupplierSettlementService::class)->recordPayment(array_merge([
            'branch_id' => $this->branch->public_id,
            'supplier_id' => $this->supplier()->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 100000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $overrides), $this->user->id);
    }

    /** Net (debit - credit) of an account family in base currency. */
    private function net(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')->first();

        return (int) $row->d - (int) $row->c;
    }

    private function assertLedgerClean(): void
    {
        foreach (['1110', '1100', '2300', '2100'] as $code) {
            $this->assertSame(0, $this->net($code), "account family {$code} must net to zero");
        }
    }

    private function issuedCustomerInvoice(int $amount): Invoice
    {
        $service = app(InvoiceService::class);
        $invoice = $service->create([
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('4100')->public_id, 'credit_minor' => $amount]],
        ], $this->branch, $this->user->id);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);

        return $service->issue($invoice, $this->user->id);
    }

    public function test_journal_reverse_endpoint_refuses_document_entries(): void
    {
        $payment = $this->receipt(50000);
        $entry = JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/journal-entries/{$entry->public_id}/reverse", ['reason' => 'oops'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'domain_error')
            ->assertJsonFragment(['message' => "This entry belongs to a business document (payment). Use the document's own cancel or reverse action instead of reversing the journal entry."]);

        $this->assertSame(0, JournalEntry::query()->whereNotNull('reversal_of_id')->count());
    }

    public function test_journal_reverse_endpoint_still_reverses_manual_entries(): void
    {
        $entry = app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account('1110'), 1000),
                PostingLine::credit($this->account('2300'), 1000),
            ],
            memo: 'Manual',
            sourceType: 'manual',
            actorId: $this->user->id,
        ));

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/journal-entries/{$entry->public_id}/reverse", ['reason' => 'typo'])
            ->assertOk();

        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $entry->id)->count());
    }

    public function test_cancelling_a_receipt_reverses_postings_and_restores_the_booking_balance(): void
    {
        $booking = $this->booking(200000);
        $payment = $this->receipt(150000, $booking);
        $this->assertSame(50000, $booking->refresh()->balanceMinor());

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/payments/{$payment->public_id}/cancel", ['reason' => 'Entered twice'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.unallocated_minor', 0);

        $this->assertSame(200000, $booking->refresh()->balanceMinor());
        $this->assertSame(0, $payment->allocations()->count());
        $this->assertSame('cancelled', $payment->refresh()->status);
        $this->assertSame(0, $this->net('1110'));
        $this->assertSame(0, $this->net('2300'));
        $this->assertSame(200000, $this->net('1100'), 'only the booking receivable remains');
        $this->assertDatabaseHas('audit_events', ['action' => 'payment.cancelled']);
    }

    public function test_cancelling_reverses_later_allocation_movements_too(): void
    {
        $booking = $this->booking(200000);
        $payment = $this->receipt(100000);
        $service = app(PaymentService::class);
        $service->allocate($payment, [['booking_id' => $booking->public_id, 'amount_minor' => 60000]], $this->user->id);
        $service->allocate($payment, [['booking_id' => $booking->public_id, 'amount_minor' => 80000]], $this->user->id);
        $service->unallocate($payment, $booking->public_id, $this->user->id);
        $service->allocate($payment, [['booking_id' => $booking->public_id, 'amount_minor' => 30000]], $this->user->id);

        $service->cancel($payment, 'Wrong customer', $this->user->id);

        $this->assertSame(200000, $booking->refresh()->balanceMinor());
        $this->assertSame(0, $this->net('1110'));
        $this->assertSame(0, $this->net('2300'));
        $this->assertSame(200000, $this->net('1100'));
    }

    public function test_cancelling_an_advance_receipt_clears_the_ledger(): void
    {
        $payment = $this->receipt(70000);

        app(PaymentService::class)->cancel($payment, 'Duplicate', $this->user->id);

        $this->assertLedgerClean();
    }

    public function test_receipt_cancel_is_idempotent_and_posts_reversals_once(): void
    {
        $payment = $this->receipt(70000);
        $service = app(PaymentService::class);

        $service->cancel($payment, 'Duplicate', $this->user->id);
        $count = JournalEntry::query()->whereNotNull('reversal_of_id')->count();
        $again = $service->cancel($payment, 'Duplicate', $this->user->id);

        $this->assertSame('cancelled', $again->status);
        $this->assertSame($count, JournalEntry::query()->whereNotNull('reversal_of_id')->count());
        $this->assertSame(1, $count);
    }

    public function test_receipt_cancel_requires_a_reason_and_the_permission(): void
    {
        $payment = $this->receipt(70000);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/payments/{$payment->public_id}/cancel", [])
            ->assertStatus(422);

        $this->user->revokePermissionTo('payments.reverse');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertStatus(403);

        $this->assertSame('posted', $payment->refresh()->status);
    }

    public function test_receipt_cancel_is_refused_with_an_approved_refund(): void
    {
        $payment = $this->receipt(100000);
        $service = app(PaymentService::class);
        $refund = $service->requestRefund(['payment_id' => $payment->public_id, 'amount_minor' => 30000], $this->user->id);
        $service->approveRefund($refund, $this->user->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This receipt has refunds. Resolve or reverse the refunds before cancelling it.']);

        $this->assertSame('posted', $payment->refresh()->status);
    }

    public function test_receipt_cancel_is_refused_with_a_pending_refund_and_refunds_on_cancelled_receipts_are_refused(): void
    {
        $payment = $this->receipt(100000);
        $service = app(PaymentService::class);
        $service->requestRefund(['payment_id' => $payment->public_id, 'amount_minor' => 30000], $this->user->id);

        try {
            $service->cancel($payment, 'x', $this->user->id);
            $this->fail('Expected a DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('has refunds', $e->getMessage());
        }

        $other = $this->receipt(50000);
        $service->cancel($other, 'x', $this->user->id);
        $this->expectException(\DomainException::class);
        $service->requestRefund(['payment_id' => $other->public_id, 'amount_minor' => 10000], $this->user->id);
    }

    public function test_receipt_cancel_is_refused_while_an_invoice_application_is_active_then_allowed_after_undo(): void
    {
        $invoice = $this->issuedCustomerInvoice(100000);
        $payment = $this->receipt(40000);
        $settlement = app(InvoiceSettlementService::class);
        $application = $settlement->applyReceipt($payment, $invoice, 40000, $this->user->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This receipt is applied to invoices. Undo the invoice applications first.']);

        $settlement->unapply($application, 'undo', $this->user->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertOk();

        $this->assertSame(0, $this->net('1110'));
        $this->assertSame(0, $this->net('2300'));
        $this->assertSame(100000, $this->net('1100'), 'only the issued invoice receivable remains');
    }

    public function test_cancelling_an_advance_supplier_payment_reverses_its_entry(): void
    {
        $payment = $this->supplierPay();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/supplier-payments/{$payment->public_id}/cancel", ['reason' => 'Wrong supplier'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertLedgerClean();

        $count = JournalEntry::query()->whereNotNull('reversal_of_id')->count();
        app(SupplierSettlementService::class)->cancel($payment, 'again', $this->user->id);
        $this->assertSame($count, JournalEntry::query()->whereNotNull('reversal_of_id')->count());
    }

    public function test_supplier_cancel_requires_a_reason_and_the_permission(): void
    {
        $payment = $this->supplierPay();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/supplier-payments/{$payment->public_id}/cancel", [])
            ->assertStatus(422);

        $this->user->revokePermissionTo('supplier_payments.reverse');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/supplier-payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertStatus(403);
    }

    public function test_supplier_cancel_is_refused_while_applied_and_clean_after_unapply(): void
    {
        $supplier = $this->supplier();
        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $supplier->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('5100')->public_id, 'debit_minor' => 50000]],
        ], $this->branch, $this->user->id);
        $invoiceService->submit($invoice, $this->user->id);
        $invoiceService->approve($invoice, $this->user->id);
        $invoiceService->issue($invoice, $this->user->id);

        $payment = $this->supplierPay(['supplier_id' => $supplier->public_id, 'amount_minor' => 30000]);
        $settlement = app(InvoiceSettlementService::class);
        $application = $settlement->applySupplierPayment($payment, $invoice, 30000, $this->user->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/supplier-payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This supplier payment is applied to obligations or invoices. Undo the applications first.']);

        $settlement->unapply($application, 'undo', $this->user->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/supplier-payments/{$payment->public_id}/cancel", ['reason' => 'x'])
            ->assertOk();

        $this->assertSame(0, $this->net('1110'));
        $this->assertSame(0, $this->net('2300'));
        $this->assertSame(-50000, $this->net('2100'), 'only the issued supplier invoice payable remains');
    }
}
