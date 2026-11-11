<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class FiscalCloseTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'periods.view', 'periods.manage', 'periods.close', 'periods.reopen',
        'journal.view', 'journal.create', 'reports.view', 'accounts.post',
    ];

    private int $year;

    private int $yearId;

    private User $poster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        $this->poster = User::factory()->create();
        $this->poster->branches()->attach($this->branch->id);
        $this->poster->givePermissionTo(['accounts.post', 'journal.view', 'journal.create', 'periods.close']);

        Sanctum::actingAs($this->user);

        $this->year = (int) now()->year - 1;
        $this->yearId = $this->withHeaders($this->h())->postJson('/api/v1/fiscal-periods', [
            'name' => (string) $this->year,
            'starts_on' => "{$this->year}-01-01",
            'ends_on' => "{$this->year}-12-31",
        ])->assertCreated()->json('data.id');
    }

    private function h(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function entry(string $date, string $debit, string $credit, int $amount, ?Branch $branch = null, bool $post = true): TestResponse
    {
        return $this->withHeaders($this->h($branch))->postJson('/api/v1/journal-entries', [
            'entry_date' => $date,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'post' => $post,
            'lines' => [
                ['account_id' => $debit, 'debit_minor' => $amount],
                ['account_id' => $credit, 'credit_minor' => $amount],
            ],
        ]);
    }

    private function profitYear(): void
    {
        $this->entry("{$this->year}-03-10", '1110', '4100', 1000000)->assertCreated();
        $this->entry("{$this->year}-07-15", '6100', '1110', 400000)->assertCreated();
    }

    private function close(?int $yearId = null): TestResponse
    {
        return $this->withHeaders($this->h())->postJson('/api/v1/fiscal-years/'.($yearId ?? $this->yearId).'/close');
    }

    private function balance(string $code, ?Branch $branch = null): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->accountFamily($code))
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    private function closingEntries()
    {
        return JournalEntry::query()->where('source_type', 'fiscal_year')->where('source_id', $this->yearId)->orderBy('id')->get();
    }

    public function test_a_profitable_year_closes_into_retained_earnings_with_a_balanced_entry(): void
    {
        $this->profitYear();

        $this->close()->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.close_count', 1);

        $entries = $this->closingEntries();
        $this->assertCount(1, $entries);
        $this->assertSame("{$this->year}-12-31", $entries[0]->entry_date->toDateString());
        $this->assertSame($entries[0]->lines->sum('base_debit_minor'), $entries[0]->lines->sum('base_credit_minor'));

        $this->assertSame(0, $this->balance('4100'));
        $this->assertSame(0, $this->balance('6100'));
        $this->assertSame(-600000, $this->balance('3100'));
        $this->assertSame(600000, $this->balance('1110'));

        $this->assertSame('closed', FiscalYear::query()->findOrFail($this->yearId)->status);
        $this->assertSame(0, FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)->where('status', '!=', 'closed')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'fiscal_year.closed']);
    }

    public function test_a_loss_year_debits_retained_earnings(): void
    {
        $this->entry("{$this->year}-02-01", '6100', '1110', 250000)->assertCreated();

        $this->close()->assertOk();

        $this->assertSame(250000, $this->balance('3100'));
        $this->assertSame(0, $this->balance('6100'));
    }

    public function test_reports_still_show_a_closed_years_profit_and_the_balance_sheet_balances(): void
    {
        $this->profitYear();
        $range = ['from' => "{$this->year}-01-01", 'to' => "{$this->year}-12-31"];

        $before = $this->withHeaders($this->h())->getJson('/api/v1/reports/balance-sheet?as_of='.$range['to'])->assertOk();
        $this->assertSame(600000, $before->json('data.current_earnings_minor'));
        $this->assertSame($before->json('data.total_assets_minor'), $before->json('data.total_liabilities_and_equity_minor'));

        $this->close()->assertOk();

        $income = $this->withHeaders($this->h())->getJson('/api/v1/reports/income-statement?'.http_build_query($range))->assertOk();
        $this->assertSame(1000000, $income->json('data.total_revenue_minor'));
        $this->assertSame(400000, $income->json('data.total_expense_minor'));
        $this->assertSame(600000, $income->json('data.net_profit_minor'));

        $after = $this->withHeaders($this->h())->getJson('/api/v1/reports/balance-sheet?as_of='.$range['to'])->assertOk();
        $this->assertSame(0, $after->json('data.current_earnings_minor'));
        $this->assertSame(600000, $after->json('data.total_equity_minor'));
        $this->assertSame($after->json('data.total_assets_minor'), $after->json('data.total_liabilities_and_equity_minor'));

        $post = $this->withHeaders($this->h())->getJson('/api/v1/reports/trial-balance?'.http_build_query($range))->assertOk();
        $pre = $this->withHeaders($this->h())->getJson('/api/v1/reports/trial-balance?'.http_build_query($range + ['pre_closing' => 1]))->assertOk();
        $this->assertSame($post->json('data.total_debit_minor'), $post->json('data.total_credit_minor'));
        $this->assertSame($pre->json('data.total_debit_minor'), $pre->json('data.total_credit_minor'));
        $this->assertSame(1400000, $pre->json('data.total_debit_minor'));
        $this->assertSame(2400000, $post->json('data.total_debit_minor'));
    }

    public function test_each_branch_gets_its_own_closing_entry(): void
    {
        $second = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($second->id);

        $this->entry("{$this->year}-03-10", '1110', '4100', 900000)->assertCreated();
        $this->entry("{$this->year}-03-11", '1110', '4100', 300000, $second)->assertCreated();
        $this->entry("{$this->year}-04-11", '6100', '1110', 500000, $second)->assertCreated();

        $this->close()->assertOk();

        $this->assertCount(2, $this->closingEntries());
        $this->assertSame(-900000, $this->balance('3100', $this->branch));
        $this->assertSame(200000, $this->balance('3100', $second));
        $this->assertSame(0, $this->balance('4100', $second));
    }

    public function test_closing_needs_access_to_every_branch_with_activity(): void
    {
        $second = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($second->id);
        $this->entry("{$this->year}-03-11", '1110', '4100', 300000, $second)->assertCreated();
        $this->user->branches()->detach($second->id);

        $this->close()->assertStatus(403);

        $this->assertSame('open', FiscalYear::query()->findOrFail($this->yearId)->status);
        $this->assertCount(0, $this->closingEntries());
    }

    public function test_pre_close_checks_block_the_close(): void
    {
        $this->profitYear();
        $draft = $this->entry("{$this->year}-05-05", '1110', '4100', 5000, post: false)->assertCreated()->json('data.id');

        $report = $this->withHeaders($this->h())->getJson("/api/v1/fiscal-years/{$this->yearId}/close-checks")->assertOk();
        $this->assertFalse($report->json('data.can_close'));
        $this->assertSame(1, collect($report->json('data.checks'))->firstWhere('code', 'draft_entries')['count']);

        $this->close()->assertStatus(422);
        $this->assertCount(0, $this->closingEntries());

        Sanctum::actingAs($this->poster);
        $this->withHeaders($this->h())->postJson("/api/v1/journal-entries/{$draft}/post")->assertOk();
        $this->close()->assertOk();
    }

    public function test_submitted_expenses_and_a_closed_final_period_block_the_close(): void
    {
        $this->profitYear();
        $this->seed(TreasurySeeder::class);

        $expense = Expense::query()->create([
            'number' => 'EX-1', 'branch_id' => $this->branch->id,
            'expense_category_id' => ExpenseCategory::query()->firstOrFail()->id,
            'expense_date' => "{$this->year}-06-01", 'payee_type' => 'none', 'currency_code' => 'EGP',
            'fx_rate_date' => "{$this->year}-06-01", 'amount_minor' => 1000, 'description' => 'Pending', 'status' => 'submitted',
        ]);
        $this->close()->assertStatus(422);
        $expense->update(['status' => 'rejected']);

        FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)->orderByDesc('ends_on')->first()->update(['status' => 'closed']);
        $this->close()->assertStatus(422);
        $this->assertCount(0, $this->closingEntries());
    }

    public function test_a_closed_year_rejects_postings_and_period_status_changes(): void
    {
        $this->profitYear();
        $this->close()->assertOk();

        $this->entry("{$this->year}-08-01", '1110', '4100', 1000)->assertStatus(422);

        $period = FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)->firstOrFail();
        $this->withHeaders($this->h())->patchJson("/api/v1/fiscal-periods/{$period->id}/status", ['status' => 'open'])->assertStatus(422);
        $this->assertSame('closed', $period->fresh()->status);

        $this->close()->assertStatus(422);
        $this->assertCount(1, $this->closingEntries());
    }

    public function test_reopening_reverses_the_closing_entries_and_the_year_can_be_closed_again(): void
    {
        $this->profitYear();
        $this->close()->assertOk();

        $this->withHeaders($this->h())->postJson("/api/v1/fiscal-years/{$this->yearId}/reopen", [])->assertStatus(422);
        $this->withHeaders($this->h())->postJson("/api/v1/fiscal-years/{$this->yearId}/reopen", ['reason' => 'Late invoice'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->assertSame(1000000 * -1, $this->balance('4100'));
        $this->assertSame(400000, $this->balance('6100'));
        $this->assertSame(0, $this->balance('3100'));
        $this->assertSame(0, FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)->where('status', '!=', 'open')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'fiscal_year.reopened']);

        $this->entry("{$this->year}-09-09", '6100', '1110', 100000)->assertCreated();
        $this->close()->assertOk()->assertJsonPath('data.close_count', 2);

        $this->assertSame(0, $this->balance('6100'));
        $this->assertSame(-500000, $this->balance('3100'));
        $this->assertCount(3, $this->closingEntries());

        $this->withHeaders($this->h())->postJson("/api/v1/fiscal-years/{$this->yearId}/reopen", ['reason' => 'Open year'])->assertOk();
        $this->withHeaders($this->h())->postJson("/api/v1/fiscal-years/{$this->yearId}/reopen", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_a_year_without_activity_closes_without_entries(): void
    {
        $this->close()->assertOk();

        $this->assertCount(0, $this->closingEntries());
        $this->assertSame('closed', FiscalYear::query()->findOrFail($this->yearId)->status);
    }

    public function test_closing_needs_a_period_covering_the_year_end_when_the_year_has_activity(): void
    {
        $this->profitYear();

        FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)
            ->whereDate('starts_on', '<=', "{$this->year}-12-31")
            ->whereDate('ends_on', '>=', "{$this->year}-12-31")
            ->delete();

        $report = $this->withHeaders($this->h())->getJson("/api/v1/fiscal-years/{$this->yearId}/close-checks")->assertOk();
        $this->assertFalse($report->json('data.can_close'));
        $this->assertFalse(collect($report->json('data.checks'))->firstWhere('code', 'year_end_period')['passed']);

        $this->close()->assertStatus(422);
        $this->assertCount(0, $this->closingEntries());
        $this->assertSame('open', FiscalYear::query()->findOrFail($this->yearId)->status);

        FiscalPeriod::query()->create([
            'fiscal_year_id' => $this->yearId,
            'name' => "{$this->year}-12",
            'starts_on' => "{$this->year}-12-01",
            'ends_on' => "{$this->year}-12-31",
            'status' => 'open',
        ]);

        $this->close()->assertOk();
        $this->assertSame(-600000, $this->balance('3100'));
    }

    public function test_a_year_without_activity_still_closes_without_a_period_covering_the_year_end(): void
    {
        FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)
            ->whereDate('starts_on', '<=', "{$this->year}-12-31")
            ->whereDate('ends_on', '>=', "{$this->year}-12-31")
            ->delete();

        $this->close()->assertOk();
        $this->assertCount(0, $this->closingEntries());
        $this->assertSame('closed', FiscalYear::query()->findOrFail($this->yearId)->status);
    }

    public function test_closing_a_single_period_is_blocked_while_it_has_drafts(): void
    {
        $this->entry("{$this->year}-05-05", '1110', '4100', 5000, post: false)->assertCreated();
        $period = FiscalPeriod::query()->where('fiscal_year_id', $this->yearId)->whereDate('starts_on', "{$this->year}-05-01")->firstOrFail();

        $this->withHeaders($this->h())->patchJson("/api/v1/fiscal-periods/{$period->id}/status", ['status' => 'closed'])->assertStatus(422);
        $this->withHeaders($this->h())->patchJson("/api/v1/fiscal-periods/{$period->id}/status", ['status' => 'soft_closed'])->assertOk();
    }

    public function test_close_and_reopen_require_their_own_permissions(): void
    {
        $this->profitYear();

        $clerk = User::factory()->create();
        $clerk->branches()->attach($this->branch->id);
        $clerk->givePermissionTo(['periods.view', 'periods.reopen']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($clerk);

        $this->close()->assertStatus(403);
        $this->withHeaders($this->h())->getJson("/api/v1/fiscal-years/{$this->yearId}/close-checks")->assertOk();

        $this->user->revokePermissionTo('periods.reopen');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user);
        $this->close()->assertOk();
        $this->withHeaders($this->h())->postJson("/api/v1/fiscal-years/{$this->yearId}/reopen", ['reason' => 'x'])->assertStatus(403);
        $this->assertSame('closed', FiscalYear::query()->findOrFail($this->yearId)->status);
    }
}
