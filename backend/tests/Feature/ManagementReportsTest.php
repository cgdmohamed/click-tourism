<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Commissions\Models\Commission;
use App\Modules\Commissions\Services\CommissionRuleService;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-RPT-01: management report pack. Each report is derived from posted
 * journal lines, so its totals must equal the trial balance / income statement.
 */
class ManagementReportsTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private User $seller;

    private Employee $employee;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(TreasurySeeder::class);
        $this->seed(RolePermissionSeeder::class);
        app(SettingService::class)->set('commissions', 'segregation', 'false');
        app(SettingService::class)->set('tax', 'default_rate', '10');

        $this->user->assignRole('accountant');
        $this->user->givePermissionTo(['reports.view', 'reports.export']);

        $this->other = Branch::query()->create([
            'code' => 'AL2', 'name_ar' => 'فرع الإسكندرية', 'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($this->other->id);

        $this->seller = User::factory()->create(['name' => 'Sam Seller']);
        $this->seller->branches()->attach([$this->branch->id, $this->other->id]);
        $this->employee = Employee::query()->create([
            'internal_code' => 'S1', 'name_ar' => 'بائع', 'name_en' => 'Seller One', 'category' => 'staff',
            'branch_id' => $this->branch->id, 'user_id' => $this->seller->id, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    /** @param list<array{0: string, 1: int, 2: int}> $items [service_type, sell, cost] */
    private function booking(array $items, ?Branch $branch = null, bool $complete = true): Booking
    {
        $branch ??= $this->branch;
        $service = app(BookingService::class);
        $booking = $service->create([
            'branch_id' => $branch->public_id,
            'customer_id' => $this->customer($branch)->public_id,
            'owner_user_id' => $this->seller->public_id,
            'start_date' => Carbon::now()->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => array_map(fn ($i) => [
                'service_type' => $i[0], 'description' => $i[0], 'quantity' => 1,
                'unit_sell_minor' => $i[1], 'unit_cost_minor' => $i[2],
            ], $items),
        ], $this->user->id);
        $booking = $service->transition($booking, 'confirmed', null, $this->user->id);

        return $complete ? $service->transition($booking, 'completed', null, $this->user->id) : $booking;
    }

    private function report(string $path, ?Branch $branch = null, array $query = []): TestResponse
    {
        $query += ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];

        return $this->withHeaders(['X-Branch-Id' => ($branch ?? $this->branch)->public_id])
            ->getJson('/api/v1/reports/'.$path.'?'.http_build_query($query));
    }

    private function trialBalanceRow(string $code, ?Branch $branch = null): array
    {
        $rows = $this->report('trial-balance', $branch)->assertOk()->json('data.rows');

        return collect($rows)->firstWhere('code', $code) ?? [];
    }

    public function test_profitability_reconciles_to_income_statement_and_trial_balance(): void
    {
        $a = $this->booking([['hotel', 60000, 20000], ['flight', 40000, 30000]]);
        $b = $this->booking([['hotel', 50000, 10000]]);
        $this->booking([['visa', 99999, 99999]], $this->other);

        $report = $this->report('profitability')->assertOk()->json('data');

        // Tax (10% default) is excluded from revenue. Branch HQ only.
        $income = $this->report('income-statement')->assertOk()->json('data');
        $this->assertSame($income['total_revenue_minor'], $report['total_revenue_minor']);
        $this->assertSame($income['total_cost_of_services_minor'], $report['total_direct_cost_minor']);
        $this->assertSame($income['gross_profit_minor'], $report['total_margin_minor']);
        $this->assertTrue($report['reconciliation']['reconciled']);
        $this->assertSame($this->trialBalanceRow('4100')['credit_minor'] - $this->trialBalanceRow('4100')['debit_minor'], $report['total_revenue_minor']);
        $this->assertSame($this->trialBalanceRow('5100')['debit_minor'] - $this->trialBalanceRow('5100')['credit_minor'], $report['total_direct_cost_minor']);

        $byNumber = collect($report['by_booking'])->keyBy('booking.number');
        $this->assertCount(2, $byNumber);
        $this->assertSame(100000, $byNumber[$a->number]['revenue_minor']);
        $this->assertSame(50000, $byNumber[$a->number]['direct_cost_minor']);
        $this->assertSame(50000, $byNumber[$a->number]['margin_minor']);
        $this->assertSame($b->public_id, $byNumber[$b->number]['booking']['id']);

        $byService = collect($report['by_service_type'])->keyBy('service_type');
        $this->assertSame(110000, $byService['hotel']['revenue_minor']);
        $this->assertSame(30000, $byService['hotel']['direct_cost_minor']);
        $this->assertSame(40000, $byService['flight']['revenue_minor']);
        $this->assertSame(30000, $byService['flight']['direct_cost_minor']);
        $this->assertSame($report['total_revenue_minor'], array_sum(array_column($report['by_service_type'], 'revenue_minor')));
    }

    public function test_cancelled_booking_nets_to_zero_and_unlinked_ledger_activity_stays_visible(): void
    {
        $booking = $this->booking([['hotel', 70000, 20000]], complete: false);
        app(BookingService::class)->cancel($booking, 'changed mind', 0, $this->user->id);

        // Manual revenue entry has no booking: it must appear in the unlinked bucket.
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(), 'branch_id' => $this->branch->public_id, 'currency' => 'EGP',
            'fx_rate' => '1', 'fx_rate_date' => now()->toDateString(), 'fx_rate_source' => 'manual', 'post' => true,
            'lines' => [
                ['account_id' => $this->account('1110')->public_id, 'debit_minor' => 5000],
                ['account_id' => $this->account('4100')->public_id, 'credit_minor' => 5000],
            ],
        ])->assertCreated();

        $report = $this->report('profitability')->assertOk()->json('data');
        $this->assertTrue($report['reconciliation']['reconciled']);
        $this->assertSame(5000, $report['total_revenue_minor']);
        $this->assertCount(1, $report['by_booking']);
        $this->assertNull($report['by_booking'][0]['booking']);
    }

    public function test_sales_by_user_and_branch_are_branch_scoped_and_reconcile(): void
    {
        $this->booking([['hotel', 100000, 0]]);
        $this->booking([['hotel', 30000, 0]], $this->other);

        $hq = $this->report('sales')->assertOk()->json('data');
        $this->assertCount(1, $hq['by_branch']);
        $this->assertSame($this->branch->public_id, $hq['by_branch'][0]['branch']['id']);
        $this->assertSame(100000, $hq['total_revenue_minor']);
        $this->assertSame('Sam Seller', $hq['by_user'][0]['user']['name']);
        $this->assertSame(1, $hq['by_user'][0]['bookings_count']);
        $this->assertTrue($hq['reconciliation']['reconciled']);

        $other = $this->report('sales', $this->other)->assertOk()->json('data');
        $this->assertSame(30000, $other['total_revenue_minor']);
        $this->assertSame($this->other->public_id, $other['by_branch'][0]['branch']['id']);
    }

    public function test_commissions_summary_matches_the_commission_expense_account(): void
    {
        $rules = app(CommissionRuleService::class);
        $rule = $rules->create([
            'code' => 'R1', 'name_ar' => 'عمولة', 'name_en' => 'Commission', 'beneficiary_type' => 'employee',
            'basis' => 'percent_revenue', 'rate_bp' => 1000, 'effective_from' => Carbon::now()->subMonth()->toDateString(),
        ], $this->user->id);
        $rules->submit($rule, $this->user->id);
        $checker = User::factory()->create();
        $checker->branches()->attach($this->branch->id);
        $checker->assignRole('finance_manager');
        $rules->approve($rule, $checker->id);

        $commissions = app(CommissionService::class);
        $paid = $this->booking([['hotel', 100000, 0]]);
        $pending = $this->booking([['hotel', 50000, 0]]);
        foreach ([$paid, $pending] as $booking) {
            app(PaymentService::class)->record([
                'branch_id' => $booking->branch->public_id, 'customer_id' => $booking->customer->public_id,
                'booking_id' => $booking->public_id, 'cash_account_id' => $this->account('1110')->public_id,
                'method' => 'cash', 'amount_minor' => 100000 * 0 + $booking->total_sell_minor, 'currency' => 'EGP',
                'fx_rate' => '1', 'paid_at' => Carbon::now()->toIso8601String(),
            ], $this->user->id);
        }
        $all = Commission::query()->orderBy('id')->get();
        $this->assertCount(2, $all);

        $first = $commissions->post($commissions->approve($all[0], $checker->id), $checker->id);
        $cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $commissions->pay($first, ['cash_account_id' => $cash->public_id, 'amount_minor' => 4000], $checker->id);

        $report = $this->report('commissions')->assertOk()->json('data');
        $row = $report['rows'][0];
        $this->assertSame('employee', $row['beneficiary_type']);
        $this->assertSame('Seller One', $row['beneficiary']['name_en']);
        $this->assertSame($all[0]->amount_minor, $row['expense_minor']);
        $this->assertSame($all[1]->amount_minor, $row['pending_minor']);
        $this->assertSame(4000, $row['paid_minor']);
        $this->assertTrue($report['reconciliation']['reconciled']);
        $tb = $this->trialBalanceRow('6700');
        $this->assertSame($tb['debit_minor'] - $tb['credit_minor'], $report['total_expense_minor']);

        $this->assertSame(0, $this->report('commissions', $this->other)->assertOk()->json('data.total_expense_minor'));
    }

    public function test_tax_summary_reports_output_and_input_for_the_single_tax_and_reconciles_to_trial_balance(): void
    {
        $this->booking([['hotel', 100000, 0]]); // 10,000 output at the default rate, default accounts
        // Input tax paid on an expense.
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(), 'branch_id' => $this->branch->public_id, 'currency' => 'EGP',
            'fx_rate' => '1', 'fx_rate_date' => now()->toDateString(), 'fx_rate_source' => 'manual', 'post' => true,
            'lines' => [
                ['account_id' => $this->account('1160')->public_id, 'debit_minor' => 1500],
                ['account_id' => $this->account('1110')->public_id, 'credit_minor' => 1500],
            ],
        ])->assertCreated();

        $report = $this->report('tax')->assertOk()->json('data');

        $this->assertSame('10', $report['rate']);
        $this->assertFalse($report['inclusive']);
        $this->assertSame(['output' => '2200', 'input' => '1160'], collect($report['rows'])->mapWithKeys(fn ($r) => [$r['side'] => $r['account']['code']])->all());
        $this->assertSame(10000, $report['total_output_minor']);
        $this->assertSame(1500, $report['total_input_minor']);
        $this->assertSame(8500, $report['total_net_payable_minor']);

        $out = $this->trialBalanceRow('2200');
        $in = $this->trialBalanceRow('1160');
        $this->assertSame($out['credit_minor'] - $out['debit_minor'], $report['total_output_minor']);
        $this->assertSame($in['debit_minor'] - $in['credit_minor'], $report['total_input_minor']);

        $this->assertSame(0, $this->report('tax', $this->other)->assertOk()->json('data.total_output_minor'));
    }

    public function test_reports_need_reports_view_and_exports_need_reports_export(): void
    {
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo('reports.view');
        $nobody = User::factory()->create();
        $nobody->branches()->attach($this->branch->id);

        foreach (['profitability', 'sales', 'commissions', 'tax'] as $name) {
            Sanctum::actingAs($nobody);
            $this->report($name)->assertForbidden();

            Sanctum::actingAs($viewer);
            $this->report($name)->assertOk();
            $this->report("{$name}/export")->assertForbidden();
        }
    }

    public function test_exports_render_in_all_formats_and_are_audited(): void
    {
        $this->booking([['hotel', 100000, 20000]]);

        foreach (['profitability', 'sales', 'commissions', 'tax'] as $name) {
            $csv = $this->report("{$name}/export")->assertOk();
            $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());

            $xlsx = $this->report("{$name}/export", query: ['format' => 'xlsx'])->assertOk();
            $this->assertStringStartsWith('PK', $xlsx->getContent());

            $pdf = $this->report("{$name}/export", query: ['format' => 'pdf'])->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $csv = $this->report('profitability/export')->getContent();
        $this->assertStringContainsString('1000.00', $csv);
        $this->assertStringContainsString('800.00', $csv);
        $this->assertSame(13, AuditEvent::query()->where('action', 'report.exported')->count());
    }

    public function test_period_validation(): void
    {
        $this->report('sales', query: ['from' => '2026-02-01', 'to' => '2026-01-01'])->assertStatus(422);
    }
}
