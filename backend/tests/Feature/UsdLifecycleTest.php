<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FxRevaluationRun;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\RevaluationService;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Money\Services\FxService;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * End-to-end USD lifecycle on one customer and one booking:
 *
 *   draft booking (rate 50) -> customer invoice (rate 50) -> receipt (rate 52)
 *   -> refund (rate 53) -> invoice settlement (rate 52) -> month-end revaluation (rate 55)
 *
 * The point is traceability, not isolated units: every step must post a
 * balanced entry, realized differences must accumulate in 7900/6900, and the
 * closing rate must move only the base carrying value of the receivable
 * through the unrealized 7901 account while its native balance stays put.
 */
class UsdLifecycleTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 5, 10));
        $this->buildAccounting();
        $this->period = FiscalPeriod::query()->firstOrFail();
        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'true');

        foreach ([
            ['50', '2026-03-05'],
            ['52', '2026-03-20'],
            ['53', '2026-03-25'],
            ['55', '2026-03-31'],
        ] as [$rate, $date]) {
            app(FxService::class)->upsertRate('USD', $rate, $date);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    private function nativeBalance(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.debit_minor),0) as d, COALESCE(SUM(jl.credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    public function test_usd_booking_reaches_invoice_refund_and_revaluation_with_balanced_traceable_entries(): void
    {
        $customer = $this->customer();
        $customer->update(['currency_code' => 'USD']);

        // 1. Draft booking: 1,000.00 USD sell / 600.00 USD cost at rate 50.
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'start_date' => '2026-04-01',
            'currency' => 'USD',
            'fx_rate' => '50',
            'items' => [[
                'service_type' => 'program',
                'description' => 'Cairo package',
                'quantity' => 1,
                'unit_cost_minor' => 60000,
                'unit_sell_minor' => 100000,
            ]],
        ], $this->user->id);

        $this->assertSame(100000, (int) $booking->total_sell_minor);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'booking')->count(), 'a draft booking posts nothing');

        // 2. Customer invoice for the full booking value at the booking rate.
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->create([
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'booking_id' => $booking->public_id,
            'issue_date' => '2026-03-05',
            'currency' => 'USD',
            'fx_rate' => '50',
            'lines' => [[
                'account_id' => $this->account('4100')->public_id,
                'description' => 'Tour package',
                'credit_minor' => 100000,
            ]],
        ], $this->branch, $this->user->id);
        $invoices->submit($invoice, $this->user->id);
        $invoices->approve($invoice, $this->user->id);
        $invoices->issue($invoice, $this->user->id);

        $this->assertSame(5000000, $this->balance('1100'));
        $this->assertSame(-5000000, $this->balance('4100'));
        $this->assertSame(100000, (int) $invoice->fresh()->outstanding_minor);

        // 3. Customer pays 600.00 USD of the booking at 52.
        Carbon::setTestNow(Carbon::create(2026, 3, 20, 11));
        $payments = app(PaymentService::class);
        $receipt = $payments->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'booking_id' => $booking->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 60000,
            'currency' => 'USD',
            'fx_rate' => '52',
            'paid_at' => '2026-03-20T11:00:00+00:00',
            'method' => 'cash',
        ], $this->user->id);

        // Cash 60,000*52 = 3,120,000; the booking receivable clears at 50, so
        // 120,000 is a realized gain.
        $this->assertSame(3120000, $this->balance('1110'));
        $this->assertSame(-120000, $this->balance('7900'));
        $this->assertSame(2000000, $this->balance('1100'));
        $this->assertSame(0, (int) $receipt->fresh()->unallocated_minor);

        // 4. Refund 100.00 USD of that receipt at 53.
        Carbon::setTestNow(Carbon::create(2026, 3, 25, 9));
        $refund = $payments->requestRefund([
            'payment_id' => $receipt->public_id,
            'amount_minor' => 10000,
            'reason' => 'Cancelled excursion',
        ], $this->user->id);
        $payments->approveRefund($refund, $this->user->id, '53');

        // The receivable returns at the booking rate (+500,000) while cash
        // leaves at 53 (530,000): a 30,000 realized loss.
        $this->assertSame(2500000, $this->balance('1100'));
        $this->assertSame(30000, $this->balance('6900'));
        $this->assertSame(2590000, $this->balance('1110'));
        $this->assertSame(50000, (int) PaymentAllocation::query()->where('payment_id', $receipt->id)->sum('amount_minor'));

        // 5. A second receipt settles 400.00 USD of the invoice at 52.
        Carbon::setTestNow(Carbon::create(2026, 3, 26, 10));
        $settlement = $payments->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 40000,
            'currency' => 'USD',
            'fx_rate' => '52',
            'paid_at' => '2026-03-26T10:00:00+00:00',
            'method' => 'cash',
        ], $this->user->id);
        app(InvoiceSettlementService::class)->applyReceipt($settlement, $invoice->fresh(), 40000, $this->user->id);

        $this->assertSame(60000, (int) $invoice->fresh()->outstanding_minor);
        $this->assertSame(0, $this->balance('2300'), 'the advance is fully applied to the invoice');
        $this->assertSame(500000, $this->balance('1100'));
        $this->assertSame(10000, $this->nativeBalance('1100'));
        $this->assertSame(-200000, $this->balance('7900'));

        // 6. Month-end revaluation at 55.
        Carbon::setTestNow(Carbon::create(2026, 3, 31, 23));
        $result = app(RevaluationService::class)->revalue($this->period, $this->branch, $this->user->id);

        $this->assertSame('posted', $result['status']);
        $this->assertSame(1, $result['rows'], 'only the USD receivable is revalued');
        $this->assertSame(550000, $this->balance('1100'));
        $this->assertSame(10000, $this->nativeBalance('1100'), 'native history is never converted twice');
        $this->assertSame(-50000, $this->balance('7901'));
        $this->assertSame(0, $this->balance('6901'));
        $this->assertSame('fx_revaluation', $result['entry']->source_type);
        $this->assertSame($this->period->id, (int) $result['entry']->source_id);
        $this->assertSame('reversal_pending', FxRevaluationRun::query()->firstOrFail()->status);

        // 7. Every posted entry is balanced and traceable to its source document.
        $entries = JournalEntry::query()->where('status', 'posted')->with('lines')->get();
        $this->assertGreaterThanOrEqual(6, $entries->count());
        foreach ($entries as $entry) {
            $this->assertSame(
                (int) $entry->lines->sum('base_debit_minor'),
                (int) $entry->lines->sum('base_credit_minor'),
                "entry {$entry->number} ({$entry->event}) does not balance in base currency",
            );
            $this->assertContains($entry->source_type, [
                'invoice', 'payment', 'payment_allocation', 'refund', 'invoice_application', 'fx_revaluation',
            ], "unexpected source for entry {$entry->number}");
            $this->assertNotNull($entry->source_id, "entry {$entry->number} has no source document");
        }

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->where('event', 'issued')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'refund')->where('source_id', $refund->id)->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());
    }
}
