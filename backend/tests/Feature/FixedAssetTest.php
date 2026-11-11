<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\FixedAssetDepreciationRun;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Prompt 25 — fixed assets and straight-line depreciation.
 *
 * The acceptance points covered here: a 12-month asset depreciates to exactly
 * its cost with no negative carrying value; duplicate and concurrent runs
 * cannot duplicate postings; a closed period refuses the run and a retry
 * succeeds after reopening; a halfway disposal removes cost and accumulated
 * depreciation and recognizes the difference; a branch transfer posts balanced
 * inter-branch journals, keeps global totals and records the move in the audit trail.
 */
class FixedAssetTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'assets.view', 'assets.manage', 'assets.approve',
        'branches.view_all',
        'periods.view', 'periods.manage',
        'journal.view', 'journal.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    private function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    private function nextPeriod(): string
    {
        return now()->startOfMonth()->addMonthNoOverflow()->format('Y-m');
    }

    private function nextMonthEnd(): string
    {
        return now()->startOfMonth()->addMonthNoOverflow()->endOfMonth()->toDateString();
    }

    private function createPeriod(string $period, string $status = 'open'): FiscalPeriod
    {
        $year = FiscalYear::query()->firstOrFail();
        $start = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();

        return FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'name' => $period,
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->endOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createCategory(array $overrides = []): FixedAssetCategory
    {
        $response = $this->withHeaders($this->headers())->postJson('/api/v1/fixed-asset-categories', $overrides + [
            'code' => 'VEH',
            'name_ar' => 'مركبات',
            'name_en' => 'Vehicles',
            'default_useful_life_months' => 12,
        ])->assertCreated();

        return FixedAssetCategory::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function createAsset(array $overrides = [], ?FixedAssetCategory $category = null): FixedAsset
    {
        $category ??= $this->createCategory();

        $response = $this->withHeaders($this->headers())->postJson('/api/v1/fixed-assets', $overrides + [
            'category_id' => $category->public_id,
            'description' => 'Delivery van',
            'capitalization_date' => now()->startOfMonth()->toDateString(),
            'in_service_date' => now()->startOfMonth()->toDateString(),
            'acquisition_value_minor' => 1_200_000,
            'residual_value_minor' => 0,
            'useful_life_months' => 12,
            'currency' => 'EGP',
        ])->assertCreated();

        return FixedAsset::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }

    private function capitalize(FixedAsset $asset): FixedAsset
    {
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/fixed-assets/{$asset->public_id}/capitalize")
            ->assertOk();

        return $asset->refresh();
    }

    private function createRun(string $period): FixedAssetDepreciationRun
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/depreciation-runs', ['period' => $period])
            ->assertCreated();

        return FixedAssetDepreciationRun::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();
    }

    private function postRun(FixedAssetDepreciationRun $run): TestResponse
    {
        return $this->withHeaders($this->headers())
            ->postJson("/api/v1/depreciation-runs/{$run->public_id}/post");
    }

    private function postedJournalCount(): int
    {
        return JournalEntry::query()->where('status', 'posted')->count();
    }

    private function accountNet(string $code): int
    {
        $accountId = $this->account($code)->id;

        $debit = (int) JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))
            ->sum('base_debit_minor');

        $credit = (int) JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))
            ->sum('base_credit_minor');

        return $debit - $credit;
    }

    private function assertLedgerBalanced(): void
    {
        foreach (JournalEntry::query()->where('status', 'posted')->with('lines')->get() as $entry) {
            $debit = $entry->lines->sum('base_debit_minor');
            $credit = $entry->lines->sum('base_credit_minor');
            $this->assertSame($debit, $credit, "Journal {$entry->number} is not balanced.");
        }
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_twelve_month_schedule_reaches_exactly_cost_and_never_goes_negative(): void
    {
        $asset = $this->capitalize($this->createAsset());

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/v1/fixed-assets/{$asset->public_id}/schedule")
            ->assertOk();

        $periods = $response->json('data.periods');

        $this->assertCount(12, $periods);
        $this->assertSame(1_200_000, array_sum(array_column($periods, 'amount_minor')));
        $this->assertSame(0, end($periods)['carrying_value_minor']);
        $this->assertSame(0, $periods[11]['accumulated_minor'] - 1_200_000);

        foreach ($periods as $period) {
            $this->assertGreaterThanOrEqual(0, $period['amount_minor']);
            $this->assertGreaterThanOrEqual(0, $period['carrying_value_minor']);
        }

        // The final postable month absorbs the rounding residual.
        $this->assertSame(100_000, $periods[0]['amount_minor']);
        $this->assertSame(1_200_000, $periods[11]['accumulated_minor']);
    }

    public function test_duplicate_and_concurrent_runs_cannot_duplicate_postings(): void
    {
        $asset = $this->capitalize($this->createAsset());
        $run = $this->createRun($this->currentPeriod());

        $this->postRun($run)->assertOk();
        $this->assertSame(100_000, $asset->refresh()->accumulated_depreciation_minor);

        // Re-posting the same run is idempotent: one journal, same reference.
        $first = $run->refresh();
        $this->postRun($first)->assertOk();
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'fixed_asset_depreciation_run')
            ->where('source_id', $first->id)
            ->where('event', 'posted')
            ->count());

        // A second run for the same period finds nothing left to depreciate.
        $second = $this->createRun($this->currentPeriod());
        $this->assertSame(0, $second->assets_count);
        $this->assertSame(0, $second->total_amount_minor);
        $this->postRun($second)->assertStatus(422);

        $this->assertSame(100_000, $asset->refresh()->accumulated_depreciation_minor);
        $this->assertSame(1, FixedAssetDepreciationRun::query()->where('status', 'posted')->count());
        $this->assertLedgerBalanced();
    }

    public function test_closed_period_refuses_posting_and_succeeds_after_reopening(): void
    {
        $asset = $this->capitalize($this->createAsset());
        $journalsBefore = $this->postedJournalCount();
        $period = $this->createPeriod($this->nextPeriod(), 'closed');
        $run = $this->createRun($this->nextPeriod());

        $this->postRun($run)->assertStatus(422)
            ->assertJsonPath('code', 'domain_error');

        $run->refresh();
        $this->assertSame(FixedAssetDepreciationRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->failure_reason);
        $this->assertSame(0, $asset->refresh()->accumulated_depreciation_minor);
        $this->assertSame($journalsBefore, $this->postedJournalCount());

        $period->update(['status' => 'open']);

        $this->postRun($run)->assertOk();
        $run->refresh();

        $this->assertSame(FixedAssetDepreciationRun::STATUS_POSTED, $run->status);
        $this->assertNotNull($run->journal_entry_id);
        $this->assertSame(100_000, $asset->refresh()->accumulated_depreciation_minor);
        $this->assertLedgerBalanced();
    }

    public function test_halfway_disposal_removes_cost_and_accumulated_depreciation_and_recognizes_loss(): void
    {
        $this->createPeriod($this->nextPeriod(), 'open');

        $asset = $this->capitalize($this->createAsset());

        $this->postRun($this->createRun($this->currentPeriod()))->assertOk();
        $this->postRun($this->createRun($this->nextPeriod()))->assertOk();

        $asset->refresh();
        $this->assertSame(200_000, $asset->accumulated_depreciation_minor);
        $this->assertSame(1_000_000, $asset->carryingValueMinor());

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/v1/fixed-assets/{$asset->public_id}/dispose", [
                'disposal_date' => $this->nextMonthEnd(),
                'disposal_type' => FixedAsset::DISPOSAL_SALE,
                'proceeds_minor' => 900_000,
            ])
            ->assertOk();

        $this->assertSame(FixedAsset::STATUS_DISPOSED, $response->json('data.status'));
        $this->assertSame(-100_000, $response->json('data.disposal_gain_loss_minor'));
        $this->assertSame(0, $response->json('data.carrying_value_minor'));

        // The disposal entry removes the asset cost and accumulated balance.
        $this->assertSame(0, $this->accountNet('1300'));
        $this->assertSame(0, $this->accountNet('1210'));
        $this->assertSame(100_000, $this->accountNet('6915'));

        $asset->refresh();
        $this->assertNotNull($asset->disposal_journal_entry_id);
        $this->assertSame(FixedAsset::STATUS_DISPOSED, $asset->status);

        $reconciliation = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation')
            ->assertOk();

        $this->assertTrue($reconciliation->json('data.is_reconciled'));
        $this->assertLedgerBalanced();
    }

    public function test_branch_transfer_posts_journals_keeps_global_totals_and_records_audit_trail(): void
    {
        $other = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);
        $this->user->branches()->attach($other->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $asset = $this->capitalize($this->createAsset());
        $journalsBefore = $this->postedJournalCount();

        $before = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation?global=1')
            ->assertOk();

        $this->assertTrue($before->json('data.is_reconciled'));
        $this->assertSame(0, $before->json('data.cost_difference_minor'));

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/fixed-assets/{$asset->public_id}/transfer", [
                'to_branch_id' => $other->public_id,
                'transfer_date' => now()->toDateString(),
                'reason' => 'Operational move',
            ])
            ->assertCreated();

        // An inter-branch transfer posts one journal per branch.
        $this->assertSame($journalsBefore + 2, $this->postedJournalCount());
        $this->assertSame($other->id, $asset->refresh()->branch_id);

        $global = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation?global=1')
            ->assertOk();

        $this->assertTrue($global->json('data.is_reconciled'));
        $this->assertSame(0, $global->json('data.cost_difference_minor'));
        $this->assertSame(0, $global->json('data.accumulated_difference_minor'));

        // The journals move cost between the branches, so each branch reconciles.
        $from = $this->withHeaders($this->headers())->getJson('/api/v1/reports/fixed-assets/reconciliation')->assertOk();
        $to = $this->withHeaders($this->headers($other))->getJson('/api/v1/reports/fixed-assets/reconciliation')->assertOk();

        $this->assertSame(0, $from->json('data.cost_difference_minor'));
        $this->assertSame(0, $to->json('data.cost_difference_minor'));
        $this->assertTrue($from->json('data.is_reconciled'));
        $this->assertTrue($to->json('data.is_reconciled'));

        $trail = $this->withHeaders($this->headers())
            ->getJson("/api/v1/fixed-assets/{$asset->public_id}/audit-trail")
            ->assertOk();

        $actions = array_column($trail->json('data.events'), 'action');
        $this->assertContains('fixed_asset.transferred', $actions);
        $this->assertCount(1, $trail->json('data.transfers'));
        $this->assertSame('ALX', $trail->json('data.transfers.0.to_branch.code'));
        $this->assertSame(0, $trail->json('data.asset.accumulated_depreciation_minor'));

        // Roll-forward still reconciles globally after the move.
        $rollForward = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/roll-forward?global=1&from='.$this->currentPeriod().'&to='.$this->currentPeriod())
            ->assertOk();

        $this->assertSame(1_200_000, $rollForward->json('data.cost.closing_minor'));
        $this->assertSame(1_200_000, $rollForward->json('data.carrying_value.closing_minor'));
    }

    public function test_assets_view_permission_cannot_create_or_post(): void
    {
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo('assets.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($viewer);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/fixed-assets')
            ->assertOk();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/fixed-assets', [])
            ->assertForbidden();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/depreciation-runs', ['period' => $this->currentPeriod()])
            ->assertForbidden();
    }
}
