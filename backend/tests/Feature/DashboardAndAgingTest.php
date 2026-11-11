<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Models\SupplierObligation;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Dashboard net profit / collections and the invoice-based AR/AP aging.
 */
class DashboardAndAgingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Permission::query()->firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');
        Sanctum::actingAs($this->user);
    }

    private function postEntry(string $debit, string $credit, int $amount, string $sourceType = 'test'): void
    {
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account($debit), $amount),
                PostingLine::credit($this->account($credit), $amount),
            ],
            memo: 'Test entry',
            sourceType: $sourceType,
            sourceId: ++$this->seq,
            event: 'sale',
            fxRate: '1',
            fxRateDate: now()->toDateString(),
            fxRateSource: 'manual',
            actorId: $this->user->id,
        ));
    }

    private function receipt(
        int $amount,
        string $currency = 'EGP',
        string $fx = '1',
        string $status = 'posted',
        ?\DateTimeInterface $paidAt = null,
    ): Payment {
        return Payment::query()->create([
            'number' => 'RC-'.++$this->seq,
            'customer_id' => $this->customer()->id,
            'branch_id' => $this->branch->id,
            'method' => 'cash',
            'cash_account_id' => $this->account('1110')->id,
            'amount_minor' => $amount,
            'currency_code' => $currency,
            'fx_rate' => $fx,
            'fx_rate_date' => now()->toDateString(),
            'unallocated_minor' => $amount,
            'status' => $status,
            'paid_at' => $paidAt ?? now(),
        ]);
    }

    private function refund(Payment $payment, int $amount, string $status = 'approved'): Refund
    {
        return Refund::query()->create([
            'number' => 'RF-'.++$this->seq,
            'payment_id' => $payment->id,
            'branch_id' => $this->branch->id,
            'amount_minor' => $amount,
            'currency_code' => $payment->currency_code,
            'reason' => 'test',
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
        ]);
    }

    private function invoice(
        string $type,
        int $outstanding,
        ?int $dueDaysAgo,
        string $currency = 'EGP',
        string $fx = '1',
        ?Branch $branch = null,
        string $status = Invoice::STATUS_ISSUED,
        ?Customer $customer = null,
    ): Invoice {
        $partyType = $type === Invoice::TYPE_SUPPLIER_INVOICE ? 'supplier' : 'customer';

        return Invoice::query()->create([
            'type' => $type,
            'series' => 'T',
            'number' => 'INV-'.++$this->seq,
            'party_type' => $partyType,
            'party_id' => $partyType === 'supplier' ? $this->supplier()->id : ($customer ?? $this->customer())->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'issue_date' => now()->subDays(120)->toDateString(),
            'due_date' => $dueDaysAgo === null ? null : now()->subDays($dueDaysAgo)->toDateString(),
            'currency_code' => $currency,
            'fx_rate' => $fx,
            'fx_rate_date' => now()->toDateString(),
            'status' => $status,
            'total_minor' => $outstanding,
            'outstanding_minor' => $outstanding,
        ]);
    }

    private function dashboard(): array
    {
        return app(ReportService::class)->dashboard($this->branch);
    }

    private function aging(string $type, ?Branch $branch = null): array
    {
        return app(ReportService::class)->aging($type, now()->toDateString(), $branch ?? $this->branch);
    }

    private function bucketAmounts(array $report): array
    {
        return array_column($report['buckets'], 'amount_minor');
    }

    public function test_dashboard_net_profit_and_monthly_series_exclude_year_end_closing_entries(): void
    {
        $this->postEntry('1110', '4100', 100000);  // revenue 1000.00
        $this->postEntry('5100', '1110', 30000);   // expense 300.00
        // Year-end closing: zeroes revenue/expense into equity.
        $this->postEntry('4100', '1110', 100000, 'fiscal_year');
        $this->postEntry('1110', '5100', 30000, 'fiscal_year');

        $data = $this->dashboard();

        $this->assertSame(70000, $data['net_profit_minor']);
        $current = collect($data['monthly_profit_loss'])->last();
        $this->assertSame(100000, $current['revenue_minor']);
        $this->assertSame(30000, $current['expenses_minor']);
    }

    public function test_dashboard_collected_is_net_of_approved_refunds_and_excludes_cancelled_receipts(): void
    {
        $kept = $this->receipt(100000);
        $this->receipt(50000, status: 'cancelled');
        $this->refund($kept, 30000);
        $this->refund($kept, 20000, 'requested');   // not yet approved
        $this->refund($kept, 5000, 'rejected');

        $data = $this->dashboard();

        $this->assertSame(70000, $data['collected_minor']);
        $this->assertSame(70000, collect($data['monthly_cash_flow'])->last()['inflow_minor']);
        $this->assertSame(70000, collect($data['monthly_cash_flow'])->last()['net_minor']);
    }

    public function test_dashboard_collected_is_scoped_to_the_selected_period(): void
    {
        $this->receipt(100000, paidAt: now()->startOfMonth());
        $this->receipt(70000, paidAt: now()->subMonthNoOverflow()->startOfMonth());

        $thisMonth = $this->dashboard();
        $this->assertSame(100000, $thisMonth['collected_minor']);

        $lastMonth = app(ReportService::class)->dashboard($this->branch, ['period' => 'lastMonth']);
        $this->assertSame(70000, $lastMonth['collected_minor']);

        $custom = app(ReportService::class)->dashboard($this->branch, [
            'period' => 'customRange',
            'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]);
        $this->assertSame(70000, $custom['collected_minor']);
    }

    public function test_dashboard_refunds_use_the_receipt_fx_snapshot(): void
    {
        $usd = $this->receipt(10000, 'USD', '50');   // 100.00 USD @ 50 = 5000.00 EGP
        $this->refund($usd, 4000);                    // 40.00 USD @ 50 = 2000.00 EGP

        $data = $this->dashboard();

        $this->assertSame(300000, $data['collected_minor']);
        $this->assertSame(300000, collect($data['monthly_cash_flow'])->last()['inflow_minor']);
    }

    public function test_refund_of_a_cancelled_receipt_is_not_subtracted_twice(): void
    {
        $cancelled = $this->receipt(50000, status: 'cancelled');
        $this->refund($cancelled, 10000);
        $this->receipt(20000);

        $this->assertSame(20000, $this->dashboard()['collected_minor']);
    }

    public function test_aging_is_built_on_issued_invoices_by_due_date_with_fx_snapshot(): void
    {
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 10000, -5);            // not yet due -> 0-30
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 20000, 45);            // 31-60
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 30000, 75);            // 61-90
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 1000, 200, 'USD', '50'); // 90+ : 10.00 USD @ 50 = 500.00 EGP
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 0, 10);                // fully settled
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 99999, 10, status: Invoice::STATUS_DRAFT);
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 88888, 10, status: Invoice::STATUS_VOIDED);
        $this->invoice(Invoice::TYPE_SUPPLIER_INVOICE, 77777, 10);            // AP only

        $report = $this->aging('ar');

        $this->assertSame([10000, 20000, 30000, 50000], $this->bucketAmounts($report));
        $this->assertSame(110000, $report['total_minor']);
        $this->assertSame('EGP', $report['currency']);
        $this->assertSame('ar', $report['type']);
        $this->assertSame(now()->toDateString(), $report['as_of']);
        $this->assertSame(['label', 'amount_minor'], array_keys($report['buckets'][0]));
    }

    public function test_aging_falls_back_to_issue_date_when_there_is_no_due_date(): void
    {
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 12345, null);   // issued 120 days ago

        $this->assertSame([0, 0, 0, 12345], $this->bucketAmounts($this->aging('ar')));
    }

    public function test_aging_credit_notes_reduce_customer_total_in_their_own_bucket(): void
    {
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 50000, 10);
        $this->invoice(Invoice::TYPE_CREDIT_NOTE, 15000, 40);

        $report = $this->aging('ar');

        $this->assertSame([50000, -15000, 0, 0], $this->bucketAmounts($report));
        $this->assertSame(35000, $report['total_minor']);
    }

    public function test_aging_supplier_side_uses_supplier_invoices_and_ignores_unlinked_obligations(): void
    {
        $this->invoice(Invoice::TYPE_SUPPLIER_INVOICE, 40000, 35);
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 5000, 5);

        SupplierObligation::query()->create([
            'supplier_id' => $this->supplier()->id,
            'branch_id' => $this->branch->id,
            'amount_minor' => 999000,
            'currency_code' => 'EGP',
            'status' => 'open',
        ]);

        $report = $this->aging('ap');

        $this->assertSame([0, 40000, 0, 0], $this->bucketAmounts($report));
        $this->assertSame(40000, $report['total_minor']);
    }

    public function test_aging_respects_branch_filter_and_locale_labels(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'فرع', 'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 10000, 5);
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 70000, 5, branch: $other);

        $this->assertSame(10000, $this->aging('ar')['total_minor']);
        $this->assertSame(80000, app(ReportService::class)->aging('ar', now()->toDateString(), null)['total_minor']);

        app()->setLocale('ar');
        $this->assertSame('0-30 يوم', $this->aging('ar')['buckets'][0]['label']);
        app()->setLocale('en');
        $this->assertSame('0-30 days', $this->aging('ar')['buckets'][0]['label']);
    }

    public function test_aging_endpoint_keeps_its_response_shape_and_matches_reconciliation_buckets(): void
    {
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 20000, 45);
        $this->invoice(Invoice::TYPE_CUSTOMER_INVOICE, 0, 45);

        $headers = ['X-Branch-Id' => $this->branch->public_id];
        $query = http_build_query(['type' => 'ar', 'as_of' => now()->toDateString()]);

        $aging = $this->withHeaders($headers)->getJson('/api/v1/reports/aging?'.$query)
            ->assertOk()
            ->assertJsonStructure(['data' => ['currency', 'as_of', 'type', 'buckets' => [['label', 'amount_minor']], 'total_minor']])
            ->json('data');

        Permission::query()->firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $recon = $this->withHeaders($headers)->getJson('/api/v1/reports/aging-reconciliation?'.$query)
            ->assertOk()->json('data');

        $this->assertSame($aging['total_minor'], $recon['operational']['open_invoices_minor']);
        $this->assertSame(1, $recon['operational']['invoice_count']);
        $this->assertSame($this->bucketAmounts($aging), array_column($recon['operational']['buckets'], 'amount_minor'));
    }
}
