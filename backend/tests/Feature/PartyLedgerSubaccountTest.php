<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\SupplierSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Every receivable/payable movement lands on the party's own subaccount and settles invoices. */
class PartyLedgerSubaccountTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function subaccount(string $type, int $id, string $role): Account
    {
        return PartyAccount::query()->where('party_type', $type)->where('party_id', $id)->where('role', $role)->firstOrFail()->account;
    }

    private function net(Account $account): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->where('jl.account_id', $account->id)
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')->first();

        return (int) $row->d - (int) $row->c;
    }

    private function receipt(int $amount): Payment
    {
        return app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'method' => 'cash',
            'amount_minor' => $amount,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->user->id);
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

    public function test_booking_and_receipt_post_to_the_customer_subaccount(): void
    {
        $customer = $this->customer();
        $bookings = app(BookingService::class);
        $booking = $bookings->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP', 'fx_rate' => '1',
            'items' => [['service_type' => 'hotel', 'description' => 'Hotel', 'quantity' => 1, 'unit_cost_minor' => 0, 'unit_sell_minor' => 200000]],
        ], $this->user->id);
        $bookings->transition($booking, 'confirmed', null, $this->user->id);

        $subaccount = $this->subaccount('customer', $customer->id, 'receivable');
        $this->assertSame(200000, $this->net($subaccount));
        $this->assertSame(0, $this->net($this->account('1100')), 'nothing posts directly to the control account');

        $this->receipt(50000);
        $this->assertSame(200000, $this->net($subaccount) + 0, 'an unallocated receipt is an advance, not a receivable credit');
        $this->assertSame(-50000, $this->net($this->account('2300')));
    }

    public function test_a_receipt_settles_an_invoice_and_cannot_overspend(): void
    {
        $invoice = $this->issuedCustomerInvoice(100000);
        $payment = $this->receipt(30000);
        $settlement = app(InvoiceSettlementService::class);

        $settlement->applyReceipt($payment, $invoice, 30000, $this->user->id);

        $invoice->refresh();
        $this->assertSame(70000, (int) $invoice->outstanding_minor);
        $this->assertSame(0, (int) $payment->refresh()->unallocated_minor);
        $this->assertSame(70000, $this->net($this->subaccount('customer', $this->customer()->id, 'receivable')));
        $this->assertSame(0, $this->net($this->account('2300')), 'advance is fully consumed');

        $this->expectException(DomainException::class);
        $settlement->applyReceipt($payment, $invoice, 1, $this->user->id);
    }

    public function test_a_credit_note_reduces_the_original_invoice_outstanding(): void
    {
        $invoice = $this->issuedCustomerInvoice(100000);
        $service = app(InvoiceService::class);
        $note = $service->creditNote($invoice, ['total_minor' => 30000], $this->user->id);
        $service->issue($note, $this->user->id);

        $this->assertSame(70000, (int) $invoice->refresh()->outstanding_minor);
        $this->assertSame(0, (int) $note->refresh()->outstanding_minor);
        $this->assertSame(70000, $this->net($this->subaccount('customer', $this->customer()->id, 'receivable')));
    }

    public function test_refunding_an_advance_removes_it_from_the_receipt(): void
    {
        $payment = $this->receipt(100000);
        $payments = app(PaymentService::class);
        $refund = $payments->requestRefund(['payment_id' => $payment->public_id, 'amount_minor' => 100000, 'reason' => 'x'], $this->user->id);
        $payments->approveRefund($refund, $this->user->id);

        $this->assertSame(0, (int) $payment->refresh()->unallocated_minor);
        $this->assertSame(0, $this->net($this->account('2300')));
    }

    public function test_supplier_cost_and_advance_settle_a_supplier_invoice(): void
    {
        $supplier = $this->supplier();
        $customer = $this->customer();
        $bookings = app(BookingService::class);
        $booking = $bookings->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP', 'fx_rate' => '1',
            'items' => [['service_type' => 'hotel', 'supplier_id' => $supplier->public_id, 'description' => 'Hotel', 'quantity' => 1, 'unit_cost_minor' => 80000, 'unit_sell_minor' => 100000]],
        ], $this->user->id);
        $bookings->transition($booking, 'confirmed', null, $this->user->id);
        $bookings->transition($booking->refresh(), 'completed', null, $this->user->id);

        $payable = $this->subaccount('supplier', $supplier->id, 'payable');
        $this->assertSame(-80000, $this->net($payable), 'cost is credited to the supplier subaccount');

        $payment = app(SupplierSettlementService::class)->recordPayment([
            'branch_id' => $this->branch->public_id,
            'supplier_id' => $supplier->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 30000, 'currency' => 'EGP', 'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->user->id);

        $service = app(InvoiceService::class);
        $invoice = $service->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $supplier->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('6100')->public_id, 'debit_minor' => 50000]],
        ], $this->branch, $this->user->id);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);
        $service->issue($invoice, $this->user->id);

        app(InvoiceSettlementService::class)->applySupplierPayment($payment, $invoice, 30000, $this->user->id);

        $this->assertSame(20000, (int) $invoice->refresh()->outstanding_minor);
        $this->assertSame(0, $payment->refresh()->unallocatedMinor());
    }
}
