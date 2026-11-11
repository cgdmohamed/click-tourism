<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingDocumentService;
use App\Modules\Accounting\Services\CostCenterService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Prompt 28: financial reports completion and reconciliation.
 *
 * Covers trial-balance opening/movement/closing arithmetic, coa roll-ups,
 * cost-center and adjustment scopes, source/party drilldowns, party
 * statements, ledger-based AR/AP aging reconciliation, comparative
 * income/balance statements, branch isolation and CSV exports.
 */
class FinancialReportsReconciliationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'reports.view', 'journal.view', 'journal.create',
        'invoices.view', 'invoices.create', 'invoices.approve',
        'payments.view', 'payments.create', 'cost_centers.view', 'cost_centers.manage',
        'accounts.post',
    ];

    private string $previousDate;

    private string $currentDate;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        $this->currentDate = now()->startOfMonth()->addDay()->toDateString();
        $previous = now()->startOfMonth()->subMonth();
        $this->previousDate = $previous->copy()->addDays(9)->toDateString();

        FiscalPeriod::query()->create([
            'fiscal_year_id' => FiscalPeriod::query()->firstOrFail()->fiscal_year_id,
            'name' => $previous->format('Y-m'),
            'starts_on' => $previous->toDateString(),
            'ends_on' => $previous->copy()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);

        $this->approver = User::factory()->create();
        $this->approver->branches()->attach($this->branch->id);
        $this->approver->givePermissionTo(['accounts.post', 'journal.reverse']);

        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    /** @param  array{0: string, 1: int}|null  $debitParty */
    private function entry(
        string $date,
        string $debitCode,
        string $creditCode,
        int $amount,
        ?Branch $branch = null,
        ?array $debitParty = null,
        ?array $creditParty = null,
        string $currency = 'EGP',
        string $fx = '1',
    ): void {
        $branch ??= $this->branch;

        $party = fn (?array $reference) => $reference === null
            ? []
            : ['party_type' => $reference[0], 'party_id' => $reference[1]];

        $this->withHeaders($this->headers($branch))
            ->postJson('/api/v1/journal-entries', [
                'entry_date' => $date,
                'branch_id' => $branch->public_id,
                'currency' => $currency,
                'fx_rate' => $fx,
                'fx_rate_date' => $date,
                'fx_rate_source' => 'manual',
                'memo' => "Entry {$debitCode}/{$creditCode}",
                'post' => true,
                'lines' => [
                    ['account_id' => $this->account($debitCode)->public_id, 'debit_minor' => $amount] + $party($debitParty),
                    ['account_id' => $this->account($creditCode)->public_id, 'credit_minor' => $amount] + $party($creditParty),
                ],
            ])
            ->assertCreated();
    }

    private function trialBalance(array $query = [], ?Branch $branch = null): TestResponse
    {
        $query += [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ];

        return $this->withHeaders($this->headers($branch))
            ->getJson('/api/v1/reports/trial-balance?'.http_build_query($query));
    }

    private function issueCustomerInvoice(Customer $customer, int $amountMinor = 100000, ?string $date = null): string
    {
        $id = $this->withHeaders($this->headers())
            ->postJson('/api/v1/invoices', [
                'type' => 'customer_invoice',
                'party_type' => 'customer',
                'party_id' => $customer->public_id,
                'issue_date' => $date ?? now()->toDateString(),
                'currency' => 'EGP',
                'lines' => [[
                    'account_id' => $this->account('4100')->public_id,
                    'description' => 'Tour package sale',
                    'credit_minor' => $amountMinor,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/submit")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        return $id;
    }

    public function test_trial_balance_reports_opening_movements_and_closing(): void
    {
        $this->entry($this->previousDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 500000);
        $this->entry($this->currentDate, '5100', '1110', 200000);

        $response = $this->trialBalance()->assertOk();

        $cash = collect($response->json('data.rows'))->firstWhere('code', '1110');
        $this->assertSame(100000, $cash['opening_balance_minor']);
        $this->assertSame(500000, $cash['debit_minor']);
        $this->assertSame(200000, $cash['credit_minor']);
        $this->assertSame(400000, $cash['closing_balance_minor']);
        $this->assertSame(400000, $cash['closing_debit_minor']);

        // opening + signed movements = closing for every row.
        foreach ($response->json('data.rows') as $row) {
            $this->assertSame(
                $row['opening_balance_minor'] + $row['debit_minor'] - $row['credit_minor'],
                $row['closing_balance_minor'],
            );
        }

        $this->assertSame(
            $response->json('data.total_opening_debit_minor') - $response->json('data.total_opening_credit_minor')
                + $response->json('data.total_debit_minor') - $response->json('data.total_credit_minor'),
            $response->json('data.total_closing_debit_minor') - $response->json('data.total_closing_credit_minor'),
        );
    }

    public function test_trial_balance_max_level_rolls_children_without_double_counting(): void
    {
        $parent = Account::query()->create([
            'code' => '1190', 'name_ar' => 'أصول نقدية', 'name_en' => 'Cash group',
            'type' => 'asset', 'is_postable' => false, 'is_active' => true,
        ]);
        foreach (['1191' => 'Cash A', '1192' => 'Cash B'] as $code => $name) {
            Account::query()->create([
                'code' => $code, 'name_ar' => $name, 'name_en' => $name,
                'type' => 'asset', 'parent_id' => $parent->id, 'is_postable' => true, 'is_active' => true,
            ]);
        }

        $this->entry($this->currentDate, '1191', '4100', 100000);
        $this->entry($this->currentDate, '1192', '4100', 50000);

        $response = $this->trialBalance(['max_level' => 1])->assertOk();
        $rows = collect($response->json('data.rows'));

        $this->assertNull($rows->firstWhere('code', '1191'));
        $this->assertNull($rows->firstWhere('code', '1192'));

        $group = $rows->firstWhere('code', '1190');
        $this->assertSame(150000, $group['debit_minor']);
        $this->assertSame(150000, $group['closing_balance_minor']);
        $this->assertSame($response->json('data.total_debit_minor'), $response->json('data.total_credit_minor'));
        $this->assertSame(150000, $response->json('data.total_debit_minor'));
    }

    public function test_trial_balance_cost_center_contains_only_the_allocated_portion(): void
    {
        $centers = app(CostCenterService::class);
        $first = $centers->create(['code' => 'CC-A', 'name_ar' => 'أ', 'name_en' => 'Center A'], $this->user->id);
        $second = $centers->create(['code' => 'CC-B', 'name_ar' => 'ب', 'name_en' => 'Center B'], $this->user->id);

        $this->entry($this->currentDate, '6100', '3200', 100000);

        $line = JournalLine::query()
            ->where('account_id', $this->account('6100')->id)
            ->where('debit_minor', 100000)
            ->firstOrFail();

        $centers->allocate($line->id, [
            ['cost_center_id' => $first->public_id, 'allocation_type' => 'percent', 'percent' => 60],
            ['cost_center_id' => $second->public_id, 'allocation_type' => 'percent', 'percent' => 40],
        ], $this->user->id);

        $response = $this->trialBalance(['cost_center_id' => $first->public_id])->assertOk();
        $rows = collect($response->json('data.rows'));

        $rent = $rows->firstWhere('code', '6100');
        $this->assertSame(60000, $rent['debit_minor']);
        $this->assertNull($rows->firstWhere('code', '3200'));
        $this->assertSame($first->id, $response->json('data.cost_center_id'));
    }

    public function test_trial_balance_adjustments_scope_includes_excludes_and_only(): void
    {
        $this->entry($this->currentDate, '6400', '3200', 10000);

        $documents = app(AccountingDocumentService::class);
        $voucher = $documents->create([
            'type' => 'adjustment_voucher',
            'document_date' => $this->currentDate,
            'currency' => 'EGP',
            'lines' => [
                ['account_id' => '6400', 'debit_minor' => 5000],
                ['account_id' => '3200', 'credit_minor' => 5000],
            ],
            'submit' => true,
        ], $this->branch, $this->user->id);
        $documents->approve($voucher, $this->approver->id);
        $documents->post($voucher, $this->approver->id);

        $amount = fn (string $mode): int => collect(
            $this->trialBalance(['adjustments' => $mode])->assertOk()->json('data.rows')
        )->firstWhere('code', '6400')['debit_minor'];

        $this->assertSame(15000, $amount('include'));
        $this->assertSame(10000, $amount('exclude'));
        $this->assertSame(5000, $amount('only'));
    }

    public function test_general_ledger_exposes_source_document_and_party_for_drilldown(): void
    {
        $customer = $this->customer();
        $invoiceId = $this->issueCustomerInvoice($customer);
        $invoice = Invoice::query()->where('public_id', $invoiceId)->firstOrFail();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/general-ledger?'.http_build_query([
                'account_id' => $this->account('1100')->public_id,
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_debit_minor', 100000)
            ->assertJsonCount(1, 'data.lines');

        $line = $response->json('data.lines.0');
        $this->assertSame('invoice', $line['source_type']);
        $this->assertSame($invoice->number, $line['source_reference']);
        $this->assertSame($invoice->public_id, $line['source_public_id']);
        $this->assertSame('customer', $line['party_type']);
        $this->assertSame('Test Customer', $line['party']['name_en']);
        $this->assertSame($customer->public_id, $line['party']['public_id']);
    }

    public function test_party_statement_reconciles_ledger_with_open_invoices_and_surfaces_differences(): void
    {
        $customer = $this->customer();
        $this->issueCustomerInvoice($customer, 100000);

        // A receipt applied to the control account but not to the invoice itself.
        $this->entry($this->currentDate, '1110', '1100', 40000, creditParty: ['customer', $customer->public_id]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/party-statement?'.http_build_query([
                'party_type' => 'customer',
                'party_id' => $customer->public_id,
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.party_type', 'customer')
            ->assertJsonPath('data.opening_minor', 0)
            ->assertJsonPath('data.total_debit_minor', 100000)
            ->assertJsonPath('data.total_credit_minor', 40000)
            ->assertJsonPath('data.closing_minor', 60000)
            ->assertJsonPath('data.reconciliation.reconciled', false)
            ->assertJsonPath('data.reconciliation.operational_open_invoices_minor', 100000)
            ->assertJsonPath('data.reconciliation.difference_minor', -40000)
            ->assertJsonPath('data.reconciliation.differences.0.code', 'credits_not_applied_to_invoices');

        $this->assertSame(100000, $response->json('data.subaccount_closing_minor'));
        $this->assertSame(-40000, $response->json('data.tagged_control_closing_minor'));
        $this->assertSame(
            $response->json('data.closing_minor'),
            $response->json('data.subaccount_closing_minor') + $response->json('data.tagged_control_closing_minor'),
        );

        $invoiceLine = collect($response->json('data.lines'))->firstWhere('source_type', 'invoice');
        $this->assertNotNull($invoiceLine['source_reference']);
        $this->assertSame('Test Customer', $invoiceLine['party']['name_en']);
    }

    public function test_party_statement_supports_employees(): void
    {
        $employee = Employee::query()->create([
            'internal_code' => 'EMP-T1', 'name_ar' => 'موظف', 'name_en' => 'Employee One',
            'category' => 'staff', 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);

        $this->entry(
            $this->currentDate, '1151', '1110', 50000,
            debitParty: ['employee', $employee->public_id],
        );
        // The statement control family covers employee advances (1151) and custody (1152).
        $this->entry(
            $this->currentDate, '1152', '1110', 20000,
            debitParty: ['employee', $employee->public_id],
        );

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/party-statement?'.http_build_query([
                'party_type' => 'employee',
                'party_id' => $employee->public_id,
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.party_type', 'employee')
            ->assertJsonPath('data.party.name_en', 'Employee One')
            ->assertJsonPath('data.closing_minor', 70000)
            ->assertJsonPath('data.reconciliation.reconciled', false)
            ->assertJsonPath('data.reconciliation.difference_minor', 70000);
    }

    public function test_aging_reconciliation_compares_control_ledger_to_open_invoices(): void
    {
        $customer = $this->customer();
        $this->issueCustomerInvoice($customer, 100000);

        // A receipt applied to AR in the ledger but not reflected on the invoice.
        $this->entry($this->currentDate, '1110', '1100', 40000, creditParty: ['customer', $customer->public_id]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/aging-reconciliation?'.http_build_query([
                'type' => 'ar',
                'as_of' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.type', 'ar')
            ->assertJsonPath('data.ledger.balance_minor', 60000)
            ->assertJsonPath('data.operational.open_invoices_minor', 100000)
            ->assertJsonPath('data.difference_minor', -40000)
            ->assertJsonPath('data.reconciled', false)
            ->assertJsonPath('data.differences.0.code', 'payments_not_applied_to_invoices');

        $this->assertSame(100000, array_sum(array_column($response->json('data.operational.buckets'), 'amount_minor')));
        $party = $response->json('data.parties.0');
        $this->assertSame($customer->public_id, $party['public_id']);
        $this->assertSame(60000, $party['ledger_minor']);
    }

    public function test_income_statement_and_balance_sheet_compare_periods_and_balance(): void
    {
        $this->entry($this->previousDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '5100', '1110', 30000);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/income-statement?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
                'compare_from' => Carbon::now()->subMonth()->startOfMonth()->toDateString(),
                'compare_to' => Carbon::now()->subMonth()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_revenue_minor', 0)
            ->assertJsonPath('data.total_expense_minor', 30000)
            ->assertJsonPath('data.net_profit_minor', -30000)
            ->assertJsonPath('data.comparison.total_revenue_minor', 100000)
            ->assertJsonPath('data.comparison.net_profit_minor', 100000)
            ->assertJsonPath('data.variance.net_profit_minor', -130000);

        $sheet = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/balance-sheet?'.http_build_query([
                'as_of' => now()->endOfMonth()->toDateString(),
                'compare_as_of' => Carbon::now()->subMonth()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_assets_minor', 70000)
            ->assertJsonPath('data.current_earnings_minor', 70000)
            ->assertJsonPath('data.balanced', true)
            ->assertJsonPath('data.comparison.total_assets_minor', 100000)
            ->assertJsonPath('data.variance.total_assets_minor', -30000);

        $this->assertSame(
            $sheet->json('data.total_assets_minor'),
            $sheet->json('data.total_liabilities_and_equity_minor'),
        );
    }

    public function test_trial_balance_flags_abnormal_balances_for_leaf_and_rolled_up_rows(): void
    {
        // Cash overdrawn (credit balance on a debit-normal asset) and a
        // revenue account with a net debit (credit-normal) are abnormal.
        $this->entry($this->currentDate, '5100', '1110', 50000);
        $this->entry($this->currentDate, '4100', '1110', 10000);
        $this->entry($this->currentDate, '1110', '4100', 4000);

        $rows = collect($this->trialBalance()->assertOk()->json('data.rows'));

        $cash = $rows->firstWhere('code', '1110');
        $this->assertSame(-56000, $cash['closing_balance_minor']);
        $this->assertSame(56000, $cash['closing_credit_minor']);
        $this->assertTrue($cash['abnormal']);

        $revenue = $rows->firstWhere('code', '4100');
        $this->assertSame(6000, $revenue['closing_debit_minor']);
        $this->assertTrue($revenue['abnormal']);

        $expense = $rows->firstWhere('code', '5100');
        $this->assertSame(50000, $expense['closing_debit_minor']);
        $this->assertFalse($expense['abnormal']);

        // Rolled-up rows carry the flag as well.
        foreach ($this->trialBalance(['max_level' => 1])->assertOk()->json('data.rows') as $row) {
            $this->assertArrayHasKey('abnormal', $row);
            $normalDebit = in_array($row['type'], ['asset', 'expense'], true);
            $expected = $normalDebit ? $row['closing_balance_minor'] < 0 : $row['closing_balance_minor'] > 0;
            $this->assertSame($expected, $row['abnormal']);
        }
    }

    public function test_income_statement_splits_cost_of_services_and_reports_gross_profit_with_comparison(): void
    {
        $this->entry($this->previousDate, '1110', '4100', 100000);
        $this->entry($this->previousDate, '5100', '1110', 40000);
        $this->entry($this->currentDate, '1110', '4100', 300000);
        $this->entry($this->currentDate, '5100', '1110', 120000);

        $opex = Account::query()->where('type', 'expense')->where('code', '!=', '5100')
            ->where('is_postable', true)->orderBy('code')->first();
        if ($opex) {
            $this->entry($this->currentDate, $opex->code, '1110', 20000);
        }
        $opexAmount = $opex ? 20000 : 0;

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/income-statement?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
                'compare_from' => Carbon::now()->subMonth()->startOfMonth()->toDateString(),
                'compare_to' => Carbon::now()->subMonth()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_revenue_minor', 300000)
            ->assertJsonPath('data.total_cost_of_services_minor', 120000)
            ->assertJsonPath('data.gross_profit_minor', 180000)
            ->assertJsonPath('data.total_operating_expenses_minor', $opexAmount)
            ->assertJsonPath('data.operating_profit_minor', 180000 - $opexAmount)
            ->assertJsonPath('data.net_profit_minor', 180000 - $opexAmount)
            ->assertJsonPath('data.total_expense_minor', 120000 + $opexAmount)
            ->assertJsonPath('data.comparison.gross_profit_minor', 60000)
            ->assertJsonPath('data.variance.gross_profit_minor', 120000)
            ->assertJsonPath('data.variance.total_cost_of_services_minor', 80000);

        $this->assertSame(['5100'], array_column($response->json('data.cost_of_services'), 'code'));
        $this->assertNotContains('5100', array_column($response->json('data.operating_expenses'), 'code'));
        // Existing consumers keep the combined list.
        $this->assertContains('5100', array_column($response->json('data.expenses'), 'code'));
    }

    public function test_balance_sheet_classifies_current_and_non_current_and_still_balances(): void
    {
        $nonCurrent = Account::query()->where('type', 'asset')->where('is_postable', true)
            ->where('code', 'not like', '11%')->orderBy('code')->first();
        $liability = Account::query()->where('type', 'liability')->where('is_postable', true)->orderBy('code')->first();

        $this->entry($this->currentDate, '1110', '4100', 100000);
        if ($nonCurrent) {
            $this->entry($this->currentDate, $nonCurrent->code, '1110', 30000);
        }
        if ($liability) {
            $this->entry($this->currentDate, '1110', $liability->code, 25000);
        }

        $sheet = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/balance-sheet?'.http_build_query([
                'as_of' => now()->endOfMonth()->toDateString(),
                'compare_as_of' => Carbon::now()->subMonth()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.classification', 'by_account')
            ->assertJsonPath('data.balanced', true);

        $data = $sheet->json('data');
        $this->assertSame(
            $data['total_assets_minor'],
            $data['current_assets_minor'] + $data['non_current_assets_minor'],
        );
        $this->assertSame(
            $data['total_liabilities_minor'],
            $data['current_liabilities_minor'] + $data['non_current_liabilities_minor'],
        );
        foreach ($data['current_assets'] as $line) {
            $this->assertStringStartsWith('11', $line['code']);
        }
        foreach ($data['non_current_assets'] as $line) {
            $this->assertStringStartsNotWith('11', $line['code']);
        }
        foreach ($data['current_liabilities'] as $line) {
            $this->assertMatchesRegularExpression('/^2[1-4]/', $line['code']);
        }
        foreach ($data['non_current_liabilities'] as $line) {
            $this->assertDoesNotMatchRegularExpression('/^2[1-4]/', $line['code']);
        }
        $this->assertGreaterThan(0, $data['current_assets_minor']);
        $this->assertSame(
            $data['total_assets_minor'],
            $data['total_liabilities_and_equity_minor'],
        );
        $this->assertSame(
            $data['current_assets_minor'] - $data['comparison']['current_assets_minor'],
            $data['variance']['current_assets_minor'],
        );
    }

    public function test_report_exports_include_the_new_columns_and_sections(): void
    {
        $this->user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'reports.export', 'guard_name' => 'web']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->entry($this->currentDate, '1110', '4100', 90000);
        $this->entry($this->currentDate, '5100', '1110', 30000);
        $range = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];

        $tb = $this->withHeaders($this->headers())->get('/api/v1/reports/trial-balance/export?'.http_build_query($range))->assertOk()->getContent();
        $this->assertStringContainsString('"Closing debit","Closing credit",Abnormal', $tb);

        $is = $this->withHeaders($this->headers())->get('/api/v1/reports/income-statement/export?'.http_build_query($range))->assertOk()->getContent();
        $this->assertStringContainsString('"Cost of services",5100', $is);
        $this->assertStringContainsString('"Gross profit",,,,600.00,EGP', $is);
        $this->assertStringContainsString('Operating profit', $is);

        $bs = $this->withHeaders($this->headers())->get('/api/v1/reports/balance-sheet/export?'.http_build_query(['as_of' => $range['to']]))->assertOk()->getContent();
        $this->assertStringContainsString('"Current asset",1110', $bs);
        $this->assertStringContainsString('Total current assets', $bs);
        $this->assertStringContainsString('Classification,by_account', $bs);
    }

    public function test_reports_cannot_leak_other_branch_data(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'فرع', 'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($other->id);

        $this->entry($this->currentDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 700000, $other);

        $this->trialBalance()->assertOk()->assertJsonPath('data.total_debit_minor', 100000);
        $this->trialBalance(branch: $other)->assertOk()->assertJsonPath('data.total_debit_minor', 700000);

        $foreign = Branch::query()->create([
            'code' => 'B3', 'name_ar' => 'غريب', 'name_en' => 'Foreign',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->trialBalance(branch: $foreign)->assertStatus(403);
        $this->withHeaders($this->headers($foreign))
            ->getJson('/api/v1/reports/aging-reconciliation?type=ar&as_of='.now()->toDateString())
            ->assertStatus(403);
    }

    public function test_new_report_exports_are_csv_and_audited(): void
    {
        Permission::query()->firstOrCreate(['name' => 'reports.export', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo('reports.export');

        $customer = $this->customer();
        $this->issueCustomerInvoice($customer, 100000);
        $this->entry($this->currentDate, '1110', '1100', 40000, creditParty: ['customer', $customer->public_id]);

        $range = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ];

        $exports = [
            '/api/v1/reports/income-statement/export?'.http_build_query($range),
            '/api/v1/reports/balance-sheet/export?'.http_build_query(['as_of' => now()->toDateString()]),
            '/api/v1/reports/aging-reconciliation/export?'.http_build_query(['type' => 'ar', 'as_of' => now()->toDateString()]),
            '/api/v1/reports/party-statement/export?'.http_build_query($range + [
                'party_type' => 'customer',
                'party_id' => $customer->public_id,
            ]),
            '/api/v1/reports/trial-balance/export?'.http_build_query($range),
        ];

        foreach ($exports as $url) {
            $response = $this->withHeaders($this->headers())->getJson($url)->assertOk();
            $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        }

        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'actor_id' => $this->user->id]);
    }

    public function test_new_report_filters_are_validated(): void
    {
        $this->trialBalance(['max_level' => 0])->assertStatus(422);
        $this->trialBalance(['max_level' => 21])->assertStatus(422);
        $this->trialBalance(['adjustments' => 'sometimes'])->assertStatus(422);
        $this->trialBalance(['cost_center_id' => 'missing'])->assertStatus(422);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/party-statement?party_type=employee&party_id=missing&from='
                .now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
            ->assertStatus(422);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/aging-reconciliation?type=xx&as_of='.now()->toDateString())
            ->assertStatus(422);
    }
}
