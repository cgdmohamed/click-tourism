<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class GeneralLedgerReportTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private string $previousDate;

    private string $currentDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (['journal.view', 'journal.create', 'reports.view'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['journal.view', 'journal.create', 'reports.view']);

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

        Sanctum::actingAs($this->user);
    }

    private function entry(
        string $date,
        string $debitCode,
        string $creditCode,
        int $amount,
        ?Branch $branch = null,
        bool $post = true,
        string $currency = 'EGP',
        string $fx = '1',
        ?string $memo = null,
        ?Customer $party = null,
    ): void {
        $branch ??= $this->branch;

        $this->withHeaders(['X-Branch-Id' => $branch->public_id])
            ->postJson('/api/v1/journal-entries', [
                'entry_date' => $date,
                'branch_id' => $branch->public_id,
                'currency' => $currency,
                'fx_rate' => $fx,
                'fx_rate_date' => $date,
                'fx_rate_source' => 'manual',
                'memo' => $memo ?? "Entry {$debitCode}/{$creditCode}",
                'post' => $post,
                'lines' => [
                    [
                        'account_id' => $this->account($debitCode)->public_id,
                        'debit_minor' => $amount,
                        'credit_minor' => 0,
                        'party_type' => $party ? 'customer' : null,
                        'party_id' => $party?->id,
                    ],
                    ['account_id' => $this->account($creditCode)->public_id, 'debit_minor' => 0, 'credit_minor' => $amount],
                ],
            ])
            ->assertCreated();
    }

    private function ledger(array $query = [], ?Branch $branch = null): TestResponse
    {
        $query += [
            'account_id' => $this->account('1110')->public_id,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ];

        return $this->withHeaders(['X-Branch-Id' => ($branch ?? $this->branch)->public_id])
            ->getJson('/api/v1/reports/general-ledger?'.http_build_query($query));
    }

    public function test_it_reports_opening_movements_running_balance_and_closing(): void
    {
        $this->entry($this->previousDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 500000);
        $this->entry($this->currentDate, '5100', '1110', 200000);

        $response = $this->ledger()
            ->assertOk()
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.opening_minor', 100000)
            ->assertJsonPath('data.total_debit_minor', 500000)
            ->assertJsonPath('data.total_credit_minor', 200000)
            ->assertJsonPath('data.closing_minor', 400000)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data.lines');

        $this->assertSame(600000, $response->json('data.lines.0.balance_minor'));
        $this->assertSame(400000, $response->json('data.lines.1.balance_minor'));
        $this->assertSame(
            $response->json('data.closing_minor'),
            $response->json('data.lines.1.balance_minor'),
        );
    }

    public function test_draft_entries_are_excluded(): void
    {
        $this->entry($this->currentDate, '1110', '4100', 100000, post: false);

        $this->ledger()
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data.closing_minor', 0)
            ->assertJsonCount(0, 'data.lines');
    }

    public function test_an_empty_period_shows_only_the_opening_balance_as_closing(): void
    {
        $this->entry($this->previousDate, '1110', '4100', 100000);

        $this->ledger()
            ->assertOk()
            ->assertJsonPath('data.opening_minor', 100000)
            ->assertJsonPath('data.closing_minor', 100000)
            ->assertJsonCount(0, 'data.lines');
    }

    public function test_the_running_balance_stays_correct_across_pages(): void
    {
        $this->entry($this->previousDate, '1110', '4100', 100000);
        foreach ([10000, 20000, 30000] as $amount) {
            $this->entry($this->currentDate, '1110', '4100', $amount);
        }

        $second = $this->ledger(['per_page' => 2, 'page' => 2])
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data.lines');

        $this->assertSame(160000, $second->json('data.lines.0.balance_minor'));
        $this->assertSame(160000, $second->json('data.closing_minor'));
    }

    public function test_a_parent_account_includes_its_descendants(): void
    {
        $group = Account::query()->create([
            'code' => '1190',
            'name_ar' => 'مجموعة',
            'name_en' => 'Cash group',
            'type' => 'asset',
            'is_postable' => false,
            'is_active' => true,
        ]);
        $this->account('1110')->update(['parent_id' => $group->id]);

        $this->entry($this->currentDate, '1110', '4100', 100000);

        $this->ledger(['account_id' => $group->public_id])
            ->assertOk()
            ->assertJsonPath('data.total_debit_minor', 100000)
            ->assertJsonPath('data.lines.0.account_code', '1110');
    }

    public function test_lines_are_limited_to_the_active_branch(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2',
            'name_ar' => 'فرع',
            'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);
        $this->user->branches()->attach($other->id);

        $this->entry($this->currentDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 700000, $other);

        $this->ledger()->assertOk()->assertJsonPath('data.total_debit_minor', 100000);
        $this->ledger(branch: $other)->assertOk()->assertJsonPath('data.total_debit_minor', 700000);
    }

    public function test_a_branch_the_user_is_not_assigned_to_is_forbidden(): void
    {
        $other = Branch::query()->create([
            'code' => 'B3',
            'name_ar' => 'فرع',
            'name_en' => 'Branch 3',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->ledger(branch: $other)->assertStatus(403);
    }

    public function test_validation_rejects_a_bad_range_and_unknown_account(): void
    {
        $this->ledger(['from' => '2026-05-10', 'to' => '2026-05-01'])->assertStatus(422);
        $this->ledger(['account_id' => 'does-not-exist'])->assertStatus(422);
        $this->ledger(['per_page' => 1000])->assertStatus(422);
    }

    public function test_it_requires_authentication_and_the_reports_permission(): void
    {
        $this->user->revokePermissionTo('reports.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ledger()->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withHeaders([])->getJson('/api/v1/reports/general-ledger')->assertStatus(401);
    }

    public function test_the_trial_balance_balances_for_posted_entries(): void
    {
        $this->entry($this->currentDate, '1110', '4100', 123456);

        $this->getJson('/api/v1/reports/trial-balance?'.http_build_query([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('data.total_debit_minor', 123456)
            ->assertJsonPath('data.total_credit_minor', 123456);
    }

    public function test_the_currency_filter_reports_original_amounts_of_that_currency_only(): void
    {
        $this->entry($this->currentDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 2000, currency: 'USD', fx: '50');

        $this->ledger()
            ->assertOk()
            ->assertJsonPath('data.basis', 'base')
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.total_debit_minor', 200000);

        $this->ledger(['currency' => 'usd'])
            ->assertOk()
            ->assertJsonPath('data.basis', 'original')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.total_debit_minor', 2000)
            ->assertJsonPath('data.closing_minor', 2000)
            ->assertJsonPath('meta.total', 1);

        $this->ledger(['currency' => 'EGP'])
            ->assertOk()
            ->assertJsonPath('data.total_debit_minor', 100000);
    }

    public function test_the_trial_balance_can_be_limited_to_one_currency(): void
    {
        $this->entry($this->currentDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 2000, currency: 'USD', fx: '50');

        $query = http_build_query([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'currency' => 'USD',
        ]);

        $this->getJson('/api/v1/reports/trial-balance?'.$query)
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.basis', 'original')
            ->assertJsonPath('data.total_debit_minor', 2000)
            ->assertJsonPath('data.total_credit_minor', 2000);
    }

    public function test_the_party_filter_limits_lines_to_one_customer(): void
    {
        $first = $this->customer();
        $second = $this->customer($this->branch);

        $this->entry($this->currentDate, '1110', '4100', 100000, party: $first);
        $this->entry($this->currentDate, '1110', '4100', 300000, party: $second);

        $this->ledger(['party_type' => 'customer', 'party_id' => $second->public_id])
            ->assertOk()
            ->assertJsonPath('data.total_debit_minor', 300000)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_party_and_currency_filters_are_validated(): void
    {
        $this->ledger(['party_type' => 'customer'])->assertStatus(422);
        $this->ledger(['party_id' => 'abc'])->assertStatus(422);
        $this->ledger(['party_type' => 'customer', 'party_id' => 'missing'])->assertStatus(422);
        $this->ledger(['party_type' => 'employee', 'party_id' => 'x'])->assertStatus(422);
        $this->ledger(['currency' => 'ZZZ'])->assertStatus(422);
    }

    public function test_export_requires_the_export_permission(): void
    {
        $this->ledgerExport()->assertStatus(403);
    }

    public function test_the_general_ledger_exports_as_csv_with_decimal_amounts_and_an_audit_record(): void
    {
        $this->grantExport();
        $this->entry($this->previousDate, '1110', '4100', 100000);
        $this->entry($this->currentDate, '1110', '4100', 123456);

        $response = $this->ledgerExport()->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="general-ledger_1110_', $response->headers->get('Content-Disposition'));

        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Opening balance",,,,,1000.00,EGP', $csv);
        $this->assertStringContainsString('1234.56', $csv);
        $this->assertStringContainsString('Totals,,,,1234.56,0.00,2234.56,EGP', $csv);

        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'actor_id' => $this->user->id]);
    }

    public function test_csv_cells_that_look_like_formulas_are_neutralised(): void
    {
        $this->grantExport();
        $this->entry($this->currentDate, '1110', '4100', 1000, memo: '=HYPERLINK("http://evil","x")');

        $csv = $this->ledgerExport()->assertOk()->getContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
    }

    public function test_the_trial_balance_exports_as_csv(): void
    {
        $this->grantExport();
        $this->entry($this->currentDate, '1110', '4100', 123456);

        $csv = $this->getJson('/api/v1/reports/trial-balance/export?'.http_build_query([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('1110,"Cash on Hand",', $csv);
        $this->assertStringContainsString('1234.56,0.00,1234.56,EGP', $csv);
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported']);
    }

    public function test_exports_are_branch_scoped(): void
    {
        $this->grantExport();
        $other = Branch::query()->create([
            'code' => 'B4', 'name_ar' => 'ف', 'name_en' => 'Branch 4', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);

        $this->ledgerExport(branch: $other)->assertStatus(403);
    }

    private function grantExport(): void
    {
        Permission::query()->firstOrCreate(['name' => 'reports.export', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo('reports.export');
    }

    private function ledgerExport(?Branch $branch = null): TestResponse
    {
        return $this->withHeaders(['X-Branch-Id' => ($branch ?? $this->branch)->public_id])
            ->getJson('/api/v1/reports/general-ledger/export?'.http_build_query([
                'account_id' => $this->account('1110')->public_id,
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
            ]));
    }

    public function test_dates_are_inclusive_at_the_period_boundaries(): void
    {
        $this->entry(now()->startOfMonth()->toDateString(), '1110', '4100', 1000);
        $this->entry(now()->endOfMonth()->toDateString(), '1110', '4100', 2000);

        $this->ledger()
            ->assertOk()
            ->assertJsonPath('data.opening_minor', 0)
            ->assertJsonPath('data.total_debit_minor', 3000);

        $this->assertSame(
            Carbon::now()->endOfMonth()->toDateString(),
            $this->ledger()->json('data.lines.1.entry_date'),
        );
    }
}
