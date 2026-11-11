<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Accounting\Services\OpeningBalanceService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\SupplierSettlementService;
use App\Modules\Reports\Services\ReportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Opening balances post to party subaccounts and settle through invoice applications. */
class OpeningBalanceSettlementTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Supplier $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->vendor = $this->supplier();
    }

    private function net(Account $account): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->where('jl.account_id', $account->id)
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')->first();

        return (int) $row->d - (int) $row->c;
    }

    private function subaccount(string $type, int $id, string $role): Account
    {
        return PartyAccount::query()->where('party_type', $type)->where('party_id', $id)->where('role', $role)->firstOrFail()->account;
    }

    private function postedBatch(): OpeningBalanceBatch
    {
        $service = app(OpeningBalanceService::class);
        $batch = $service->create([
            'as_of_date' => now()->startOfMonth()->toDateString(),
            'lines' => [
                ['account_id' => '1100', 'debit_minor' => 5000000, 'party_type' => 'customer', 'party_id' => $this->customer()->public_id],
                ['account_id' => '2100', 'credit_minor' => 3000000, 'party_type' => 'supplier', 'party_id' => $this->vendor->public_id],
                ['account_id' => '3200', 'credit_minor' => 2000000],
            ],
        ], $this->branch, $this->user->id);

        return $service->post($batch, $this->user->id);
    }

    private function openingInvoice(string $type): Invoice
    {
        return Invoice::query()->where('is_opening', true)->where('type', $type)->firstOrFail();
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

    private function supplierPayment(int $amount): SupplierPayment
    {
        return app(SupplierSettlementService::class)->recordPayment([
            'branch_id' => $this->branch->public_id,
            'supplier_id' => $this->vendor->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => $amount, 'currency' => 'EGP', 'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->user->id);
    }

    public function test_posting_uses_party_subaccounts_and_creates_issued_opening_documents(): void
    {
        $this->postedBatch();

        $receivable = $this->subaccount('customer', $this->customer()->id, 'receivable');
        $payable = $this->subaccount('supplier', $this->vendor->id, 'payable');
        $this->assertSame(5000000, $this->net($receivable));
        $this->assertSame(-3000000, $this->net($payable));
        $this->assertSame(0, $this->net($this->account('1100')), 'nothing posts directly to the control account');
        $this->assertSame(0, $this->net($this->account('2100')));

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->count(), 'no second journal entry');

        $customerDoc = $this->openingInvoice('customer_invoice');
        $this->assertSame('OB', $customerDoc->series);
        $this->assertSame('issued', $customerDoc->status);
        $this->assertSame(5000000, (int) $customerDoc->total_minor);
        $this->assertSame(5000000, (int) $customerDoc->outstanding_minor);
        $this->assertSame(now()->startOfMonth()->toDateString(), $customerDoc->issue_date->toDateString());
        $this->assertNotNull($customerDoc->journal_entry_id);
        $this->assertSame(JournalEntry::query()->where('source_type', 'opening_balance')->value('id'), $customerDoc->journal_entry_id);

        $supplierDoc = $this->openingInvoice('supplier_invoice');
        $this->assertSame(3000000, (int) $supplierDoc->outstanding_minor);
        $this->assertSame(0, Invoice::query()->where('is_opening', false)->count());
    }

    public function test_opening_documents_are_settled_by_a_receipt_and_a_supplier_payment(): void
    {
        $this->postedBatch();
        $settlement = app(InvoiceSettlementService::class);

        $customerDoc = $this->openingInvoice('customer_invoice');
        $settlement->applyReceipt($this->receipt(2000000), $customerDoc, 1500000, $this->user->id);
        $this->assertSame(3500000, (int) $customerDoc->refresh()->outstanding_minor);
        $this->assertSame(3500000, $this->net($this->subaccount('customer', $this->customer()->id, 'receivable')));
        $this->assertSame(-500000, $this->net($this->account('2300')), 'the unapplied rest of the receipt remains an advance');

        $supplierDoc = $this->openingInvoice('supplier_invoice');
        $settlement->applySupplierPayment($this->supplierPayment(1000000), $supplierDoc, 1000000, $this->user->id);
        $this->assertSame(2000000, (int) $supplierDoc->refresh()->outstanding_minor);
        $this->assertSame(-2000000, $this->net($this->subaccount('supplier', $this->vendor->id, 'payable')));
    }

    public function test_reconciliation_still_ties_control_accounts_to_party_detail(): void
    {
        $batch = $this->postedBatch();

        $report = app(OpeningBalanceService::class)->reconciliation($batch);

        $this->assertTrue($report['reconciled']);
        $this->assertSame(5000000, $report['control_account_detail'][0]['party_detail_minor']);
        $this->assertSame(-3000000, $report['control_account_detail'][1]['party_detail_minor']);
        $this->assertTrue(collect($report['accounts'])->every(fn ($row) => $row['matches_ledger'] === true));
    }

    public function test_opening_documents_cannot_be_voided_or_credited(): void
    {
        $this->postedBatch();
        $document = $this->openingInvoice('customer_invoice');

        try {
            app(InvoiceService::class)->creditNote($document, [], $this->user->id);
            $this->fail('credit note should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Opening balance', $e->getMessage());
        }
        try {
            app(InvoiceService::class)->void($document, $this->user->id);
            $this->fail('void should be refused');
        } catch (DomainException) {
            // issued documents can never be voided directly
        }
        $this->assertSame('issued', $document->refresh()->status);
    }

    public function test_reversal_voids_unsettled_opening_documents(): void
    {
        $batch = $this->postedBatch();

        app(OpeningBalanceService::class)->reverse($batch, 'Wrong figures', $this->user->id);

        $this->assertSame('reversed', $batch->refresh()->status);
        $this->assertSame(0, $this->net($this->subaccount('customer', $this->customer()->id, 'receivable')));
        $this->assertSame(0, $this->net($this->subaccount('supplier', $this->vendor->id, 'payable')));
        foreach (Invoice::query()->where('is_opening', true)->get() as $document) {
            $this->assertSame('voided', $document->status);
            $this->assertSame(0, (int) $document->outstanding_minor);
        }
    }

    public function test_reversal_is_refused_while_an_opening_document_has_an_application(): void
    {
        $batch = $this->postedBatch();
        $settlement = app(InvoiceSettlementService::class);
        $application = $settlement->applyReceipt($this->receipt(100000), $this->openingInvoice('customer_invoice'), 100000, $this->user->id);

        try {
            app(OpeningBalanceService::class)->reverse($batch, 'Too late', $this->user->id);
            $this->fail('reversal should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been settled', $e->getMessage());
        }
        $this->assertSame('posted', $batch->refresh()->status);
        $this->assertSame('issued', $this->openingInvoice('customer_invoice')->status);

        $settlement->unapply($application, 'Undo', $this->user->id);
        app(OpeningBalanceService::class)->reverse($batch, 'Now fine', $this->user->id);
        $this->assertSame('reversed', $batch->refresh()->status);
    }

    private function creditBatch(): OpeningBalanceBatch
    {
        $service = app(OpeningBalanceService::class);
        $batch = $service->create([
            'as_of_date' => now()->startOfMonth()->toDateString(),
            'lines' => [
                ['account_id' => '1100', 'debit_minor' => 1000000, 'party_type' => 'customer', 'party_id' => $this->customer()->public_id],
                ['account_id' => '1100', 'credit_minor' => 400000, 'party_type' => 'customer', 'party_id' => $this->customer()->public_id],
                ['account_id' => '2100', 'credit_minor' => 900000, 'party_type' => 'supplier', 'party_id' => $this->vendor->public_id],
                ['account_id' => '2100', 'debit_minor' => 250000, 'party_type' => 'supplier', 'party_id' => $this->vendor->public_id],
                ['account_id' => '3200', 'debit_minor' => 50000],
            ],
        ], $this->branch, $this->user->id);

        return $service->post($batch, $this->user->id);
    }

    public function test_credit_side_lines_post_to_the_advance_accounts_and_create_applicable_records(): void
    {
        $batch = $this->creditBatch();
        $customer = $this->customer();

        // Ledger: advance accounts tagged with the party, not the subaccount, not the control account.
        $this->assertSame(-400000, $this->net($this->account('2300')));
        $this->assertSame(250000, $this->net($this->account('1153')));
        $this->assertSame(1000000, $this->net($this->subaccount('customer', $customer->id, 'receivable')));
        $this->assertSame(-900000, $this->net($this->subaccount('supplier', $this->vendor->id, 'payable')));
        $this->assertSame(0, $this->net($this->account('1100')));
        $this->assertSame(0, $this->net($this->account('2100')));
        $advanceLine = DB::table('journal_lines')->where('account_id', $this->account('2300')->id)->first();
        $this->assertSame('customer', $advanceLine->party_type);
        $this->assertSame($customer->id, (int) $advanceLine->party_id);

        // Records: unapplied, no cash account, flagged, linked to their batch line.
        $receipt = Payment::query()->where('is_opening', true)->firstOrFail();
        $this->assertSame('posted', $receipt->status);
        $this->assertSame(400000, (int) $receipt->amount_minor);
        $this->assertSame(400000, (int) $receipt->unallocated_minor);
        $this->assertNull($receipt->cash_account_id);
        $this->assertNotNull($receipt->opening_balance_line_id);
        $this->assertTrue($receipt->toApiArray()['is_opening']);
        $advance = SupplierPayment::query()->where('is_opening', true)->firstOrFail();
        $this->assertSame(250000, $advance->unallocatedMinor());
        $this->assertNull($advance->cash_account_id);
        $this->assertNull($advance->toApiArray()['cash_account_id']);
        $this->assertSame(2, Invoice::query()->where('is_opening', true)->count(), 'the credit side has no document');

        $report = app(OpeningBalanceService::class)->reconciliation($batch->refresh());
        $this->assertTrue($report['reconciled']);
        $this->assertTrue(collect($report['accounts'])->every(fn ($row) => $row['matches_ledger'] === true));
        $this->assertTrue(collect($report['control_account_detail'])->every(fn ($row) => $row['ledger_matches'] === true));
    }

    public function test_opening_credit_is_applied_to_an_invoice_with_the_normal_settlement_service(): void
    {
        $this->creditBatch();
        $settlement = app(InvoiceSettlementService::class);

        $customerDoc = $this->openingInvoice('customer_invoice');
        $settlement->applyReceipt(Payment::query()->where('is_opening', true)->firstOrFail(), $customerDoc, 400000, $this->user->id);
        $this->assertSame(600000, (int) $customerDoc->refresh()->outstanding_minor);
        $this->assertSame(0, $this->net($this->account('2300')));
        $this->assertSame(600000, $this->net($this->subaccount('customer', $this->customer()->id, 'receivable')));
        $this->assertSame(0, (int) Payment::query()->where('is_opening', true)->value('unallocated_minor'));

        $supplierDoc = $this->openingInvoice('supplier_invoice');
        $settlement->applySupplierPayment(SupplierPayment::query()->where('is_opening', true)->firstOrFail(), $supplierDoc, 250000, $this->user->id);
        $this->assertSame(650000, (int) $supplierDoc->refresh()->outstanding_minor);
        $this->assertSame(0, $this->net($this->account('1153')));
        $this->assertSame(-650000, $this->net($this->subaccount('supplier', $this->vendor->id, 'payable')));
    }

    public function test_opening_credit_cannot_be_refunded_or_cancelled_and_never_counts_as_cash(): void
    {
        $this->creditBatch();
        $receipt = Payment::query()->where('is_opening', true)->firstOrFail();
        $payments = app(PaymentService::class);

        try {
            $payments->requestRefund(['payment_id' => $receipt->public_id, 'amount_minor' => 1000], $this->user->id);
            $this->fail('refund should be refused');
        } catch (DomainException $e) {
            $this->assertSame('Opening credit cannot be refunded in cash; apply it to an invoice.', $e->getMessage());
        }
        try {
            $payments->cancel($receipt, 'x', $this->user->id);
            $this->fail('cancel should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reverse the batch', $e->getMessage());
        }
        try {
            app(SupplierSettlementService::class)->cancel(SupplierPayment::query()->where('is_opening', true)->firstOrFail(), 'x', $this->user->id);
            $this->fail('cancel should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reverse the batch', $e->getMessage());
        }

        // A real receipt next to it: only that one is cash.
        $this->receipt(70000);
        $dashboard = app(ReportService::class)->dashboard(null);
        $month = collect($dashboard['monthly_cash_flow'])->last();
        $this->assertSame(70000, $month['inflow_minor'], 'the opening receipt is not cash inflow');
        $this->assertSame(0, $month['outflow_minor'], 'the opening supplier advance is not cash outflow');
        $this->assertCount(1, $dashboard['recent_payments'], 'the opening receipt is not a recent receipt');
        // It still counts as a customer advance (unapplied credit) next to the real receipt's.
        $this->assertSame(470000, $dashboard['customer_advances_minor']);
    }

    public function test_reversal_cancels_unused_credit_records_and_is_refused_once_applied(): void
    {
        $batch = $this->creditBatch();
        $settlement = app(InvoiceSettlementService::class);
        $application = $settlement->applyReceipt(
            Payment::query()->where('is_opening', true)->firstOrFail(),
            $this->openingInvoice('customer_invoice'), 100000, $this->user->id,
        );

        try {
            app(OpeningBalanceService::class)->reverse($batch, 'Too late', $this->user->id);
            $this->fail('reversal should be refused');
        } catch (DomainException) {
            $this->assertSame('posted', $batch->refresh()->status);
        }

        $settlement->unapply($application, 'Undo', $this->user->id);
        app(OpeningBalanceService::class)->reverse($batch, 'Wrong', $this->user->id);

        $this->assertSame('reversed', $batch->refresh()->status);
        $this->assertSame('cancelled', Payment::query()->where('is_opening', true)->value('status'));
        $this->assertSame('cancelled', SupplierPayment::query()->where('is_opening', true)->value('status'));
        $this->assertSame(0, $this->net($this->account('2300')));
        $this->assertSame(0, $this->net($this->account('1153')));
    }

    public function test_supplier_advance_application_blocks_reversal(): void
    {
        $batch = $this->creditBatch();
        app(InvoiceSettlementService::class)->applySupplierPayment(
            SupplierPayment::query()->where('is_opening', true)->firstOrFail(),
            $this->openingInvoice('supplier_invoice'), 50000, $this->user->id,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been');
        app(OpeningBalanceService::class)->reverse($batch, 'Too late', $this->user->id);
    }
}
