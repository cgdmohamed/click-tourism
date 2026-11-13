<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingCostSummary;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Bookings\Services\CostAccrualService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Services\SupplierSettlementService;
use App\Modules\Reports\Services\ReportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** DEC-002: estimated vs recognized supplier cost, linked invoices, payments and period-end accruals. */
class SupplierCostRecognitionTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private FiscalPeriod $period;

    private Supplier $theSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 10, 12));
        $this->buildAccounting();
        $this->period = FiscalPeriod::query()->firstOrFail();
        $this->theSupplier = $this->supplier();
        Account::query()->firstOrCreate(['code' => '2170'], [
            'name_ar' => 'تكاليف موردين مستحقة', 'name_en' => 'Accrued supplier costs', 'type' => 'liability',
            'is_postable' => true, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(): Booking
    {
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'owner_user_id' => $this->user->public_id,
            'start_date' => '2026-03-12',
            'end_date' => '2026-03-15',
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [[
                'service_type' => 'hotel',
                'description' => 'Hotel nights',
                'quantity' => 2,
                'unit_cost_minor' => 50000,
                'unit_sell_minor' => 100000,
                'supplier_id' => $this->theSupplier->public_id,
            ]],
        ], $this->user->id);
        app(BookingService::class)->transition($booking, 'confirmed', null, $this->user->id);

        return $booking->refresh()->load('items');
    }

    private function supplierInvoice(Booking $booking, int $amount = 100000, ?string $date = null, bool $linkItem = true): Invoice
    {
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $this->theSupplier->public_id,
            'booking_id' => $booking->public_id,
            'issue_date' => $date ?? now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [[
                'account_id' => $this->account('5100')->public_id,
                'description' => 'Hotel cost',
                'debit_minor' => $amount,
                'booking_item_id' => $linkItem ? (string) $booking->items->first()->id : null,
            ]],
        ], $this->branch, $this->user->id);
        $invoices->submit($invoice, $this->user->id);

        return $invoices->approve($invoice, $this->user->id);
    }

    private function summary(Booking $booking): array
    {
        return app(BookingCostSummary::class)->for($booking->refresh()->load('items'));
    }

    /** Net cost-of-services carried by the whole ledger (base minor). */
    private function costTotal(): int
    {
        return (int) DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('a.code', '5100')->selectRaw('COALESCE(SUM(jl.base_debit_minor - jl.base_credit_minor),0) as t')->value('t');
    }

    public function test_estimated_cost_is_shown_apart_from_recognized_and_a_draft_changes_nothing(): void
    {
        $booking = $this->booking();
        $this->supplierInvoice($booking); // approved, not issued

        $summary = $this->summary($booking);

        $this->assertSame(100000, $summary['estimated_cost_minor']);
        $this->assertSame(0, $summary['recognized_cost_minor']);
        $this->assertSame(100000, $summary['expected_remaining_minor']);
        $this->assertSame($summary['recognized_revenue_minor'], $summary['recognized_profit_minor']);
        $this->assertSame(0, $this->costTotal());
    }

    public function test_issuing_the_invoice_recognizes_the_cost_once_and_updates_profit(): void
    {
        $booking = $this->booking();
        $invoice = $this->supplierInvoice($booking);

        app(InvoiceService::class)->issue($invoice, $this->user->id);
        $summary = $this->summary($booking);

        $this->assertSame(100000, $summary['recognized_cost_minor']);
        $this->assertSame(0, $summary['expected_remaining_minor']);
        $this->assertSame(100000, $summary['items'][0]['invoiced_cost_minor']);
        $this->assertSame(100000, $this->costTotal());

        try {
            app(InvoiceService::class)->issue($invoice->refresh(), $this->user->id);
            $this->fail('A second issue must be refused.');
        } catch (DomainException) {
            // expected
        }
        $this->assertSame(100000, $this->costTotal());
    }

    public function test_paying_the_supplier_does_not_record_the_expense_again(): void
    {
        $booking = $this->booking();
        $invoice = $this->supplierInvoice($booking);
        app(InvoiceService::class)->issue($invoice, $this->user->id);
        $before = $this->costTotal();

        app(SupplierSettlementService::class)->recordPayment([
            'branch_id' => $this->branch->public_id,
            'supplier_id' => $this->theSupplier->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 100000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => now()->toIso8601String(),
        ], $this->user->id);

        $this->assertSame($before, $this->costTotal());
        $this->assertSame(100000, $this->summary($booking)['recognized_cost_minor']);
    }

    public function test_a_line_cannot_name_a_service_of_another_booking(): void
    {
        $first = $this->booking();
        $second = $this->booking();

        $this->expectException(DomainException::class);
        app(InvoiceService::class)->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $this->theSupplier->public_id,
            'booking_id' => $first->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [[
                'account_id' => $this->account('5100')->public_id,
                'debit_minor' => 1000,
                'booking_item_id' => (string) $second->items->first()->id,
            ]],
        ], $this->branch, $this->user->id);
    }

    public function test_period_end_accrual_is_reversed_and_the_later_invoice_is_not_counted_twice(): void
    {
        $booking = $this->booking();
        $accruals = app(CostAccrualService::class);

        $preview = $accruals->preview('2026-03-31', $this->branch, $this->period);
        $this->assertSame(100000, $preview[0]['accrual_minor']);

        $this->assertSame('posted', $accruals->accrue($this->period, $this->branch, $this->user->id)['status']);
        $this->assertSame('nothing_to_accrue', $accruals->accrue($this->period, $this->branch, $this->user->id)['status']);
        $this->assertSame(100000, $this->costTotal());
        $this->assertSame(100000, $this->summary($booking)['accrued_cost_minor']);

        // April: the next period opens, the accrual reverses, then the real invoice arrives.
        FiscalPeriod::query()->create([
            'fiscal_year_id' => $this->period->fiscal_year_id,
            'name' => '2026-04',
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-04-30',
            'status' => 'open',
        ]);
        Carbon::setTestNow(Carbon::create(2026, 4, 3, 12));
        $this->assertSame(1, $accruals->postDueReversals());
        $this->assertSame(0, $accruals->postDueReversals());
        $this->assertSame(0, $this->costTotal());

        $invoice = $this->supplierInvoice($booking, 100000, '2026-04-04');
        app(InvoiceService::class)->issue($invoice, $this->user->id);

        $this->assertSame(100000, $this->costTotal());
        $this->assertSame(0, $this->summary($booking)['accrued_cost_minor']);
        $this->assertSame(1, JournalEntry::query()->where('event', 'like', 'cost_accrual:%')->count());
    }

    private function supplierCostLines(string $from = '2026-01-01', string $to = '2026-12-31'): int
    {
        $ledger = app(ReportService::class)->generalLedger(
            $this->account('5100'), $from, $to, $this->branch, 1, 100, null,
            ['type' => 'supplier', 'id' => $this->theSupplier->id],
        );

        return (int) $ledger['summary']['total_debit_minor'];
    }

    public function test_the_ledger_filtered_by_supplier_finds_invoice_accrual_and_booking_cost(): void
    {
        $booking = $this->booking();
        app(CostAccrualService::class)->accrue($this->period, $this->branch, $this->user->id);
        $this->assertSame(100000, $this->supplierCostLines());

        app(InvoiceService::class)->issue($this->supplierInvoice($booking, 40000), $this->user->id);
        $this->assertSame(140000, $this->supplierCostLines());
    }

    public function test_next_periods_accrual_is_not_suppressed_while_the_previous_reversal_has_not_run(): void
    {
        $this->booking();
        $accruals = app(CostAccrualService::class);
        $accruals->accrue($this->period, $this->branch, $this->user->id);

        $april = FiscalPeriod::query()->create([
            'fiscal_year_id' => $this->period->fiscal_year_id,
            'name' => '2026-04', 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30', 'status' => 'open',
        ]);
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12));

        // March's reversal has not run; the service is still uninvoiced, so April accrues it again.
        $preview = $accruals->preview('2026-04-30', $this->branch, $april);
        $this->assertSame(100000, $preview[0]['accrual_minor']);
        $this->assertSame('posted', $accruals->accrue($april, $this->branch, $this->user->id)['status']);

        // The job then reverses March only; April's accrual stays as the liability.
        $this->assertSame(1, $accruals->postDueReversals());
        $liability = (int) DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('a.code', '2170')->selectRaw('COALESCE(SUM(jl.base_credit_minor - jl.base_debit_minor),0) as t')->value('t');
        $this->assertSame(100000, $liability);
    }

    public function test_accrual_goes_to_the_supplier_that_is_still_uninvoiced(): void
    {
        $other = $this->supplier();
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'owner_user_id' => $this->user->public_id,
            'start_date' => '2026-03-12',
            'end_date' => '2026-03-15',
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [
                ['service_type' => 'hotel', 'description' => 'A', 'quantity' => 1, 'unit_cost_minor' => 100000, 'unit_sell_minor' => 150000, 'supplier_id' => $this->theSupplier->public_id],
                ['service_type' => 'transport', 'description' => 'B', 'quantity' => 1, 'unit_cost_minor' => 100000, 'unit_sell_minor' => 150000, 'supplier_id' => $other->public_id],
            ],
        ], $this->user->id);
        app(BookingService::class)->transition($booking, 'confirmed', null, $this->user->id);
        $booking = $booking->refresh()->load('items');

        // A is invoiced in full; only B is still owed.
        app(InvoiceService::class)->issue($this->supplierInvoice($booking, 100000), $this->user->id);
        app(CostAccrualService::class)->accrue($this->period, $this->branch, $this->user->id);

        $debits = fn ($supplier) => (int) app(ReportService::class)->generalLedger(
            $this->account('5100'), '2026-01-01', '2026-12-31', $this->branch, 1, 100, null, ['type' => 'supplier', 'id' => $supplier->id],
        )['summary']['total_debit_minor'];

        $this->assertSame(100000, $debits($this->theSupplier));
        $this->assertSame(100000, $debits($other));
    }

    public function test_a_booking_whose_cost_is_already_invoiced_is_not_accrued(): void
    {
        $booking = $this->booking();
        app(InvoiceService::class)->issue($this->supplierInvoice($booking), $this->user->id);

        $this->assertSame([], app(CostAccrualService::class)->preview('2026-03-31', $this->branch, $this->period));
    }
}
