<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\CostCenterAllocation;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class CostCenterTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'cost_centers.view', 'cost_centers.manage',
        'journal.view', 'journal.create',
        'reports.view',
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

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function center(array $overrides = []): array
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/cost-centers', $overrides + [
            'code' => 'CC-100',
            'name_ar' => 'مركز تكلفة 100',
            'name_en' => 'Cost Center 100',
        ])->assertCreated()->json('data');
    }

    private function postLine(string $accountCode, int $debit, int $credit, ?string $date = null): array
    {
        $response = $this->withHeaders($this->headers())->postJson('/api/v1/journal-entries', [
            'entry_date' => $date ?? now()->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'post' => true,
            'lines' => [
                ['account_id' => $accountCode, 'debit_minor' => $debit, 'credit_minor' => $credit],
                ['account_id' => '3200', 'debit_minor' => $credit, 'credit_minor' => $debit],
            ],
        ])->assertCreated();

        $publicId = $response->json('data.id');
        $entry = JournalEntry::query()->where('public_id', $publicId)->firstOrFail();
        $line = JournalLine::query()->where('journal_entry_id', $entry->id)
            ->where('account_id', $this->account($accountCode)->id)
            ->firstOrFail();

        return ['entry_id' => $entry->id, 'line_id' => $line->id];
    }

    private function allocate(int $lineId, array $allocs): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson(
            "/api/v1/journal-lines/{$lineId}/cost-allocations",
            ['allocations' => $allocs]
        );
    }

    public function test_create_cost_center_with_parent_and_hierarchy(): void
    {
        $parent = $this->center(['code' => 'CC-P', 'name_en' => 'Parent Center']);
        $child = $this->withHeaders($this->headers())->postJson('/api/v1/cost-centers', [
            'code' => 'CC-C',
            'name_ar' => 'طفل',
            'name_en' => 'Child Center',
            'parent_id' => $parent['id'],
        ])->assertCreated()->json('data');

        $this->assertSame($parent['id'], $child['parent_id']);

        $shown = $this->withHeaders($this->headers())->getJson("/api/v1/cost-centers/{$child['id']}")
            ->assertOk()->json('data');

        $this->assertSame($parent['id'], $shown['parent_id']);

        $parentShown = $this->withHeaders($this->headers())->getJson("/api/v1/cost-centers/{$parent['id']}")
            ->assertOk()->json('data');

        $this->assertCount(1, $parentShown['children']);
        $this->assertSame($child['id'], $parentShown['children'][0]['id']);
    }

    public function test_cycle_protection_rejects_self_parent_and_circular_parent(): void
    {
        $a = $this->center(['code' => 'CC-A']);
        $b = $this->center(['code' => 'CC-B', 'parent_id' => $a['id']]);

        // B cannot become parent of A (would create cycle).
        $this->patchJson("/api/v1/cost-centers/{$a['id']}", ['parent_id' => $b['id']])
            ->assertStatus(422);

        // A cannot be its own parent.
        $this->patchJson("/api/v1/cost-centers/{$a['id']}", ['parent_id' => $a['id']])
            ->assertStatus(422);

        $this->assertSame(2, CostCenter::query()->count());
    }

    public function test_archive_cost_center_with_allocations_is_blocked(): void
    {
        $center = $this->center();
        $line = $this->postLine('6100', 100000, 0);

        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center['id'], 'allocation_type' => 'exact', 'amount_minor' => 100000],
        ])->assertCreated();

        $this->postJson("/api/v1/cost-centers/{$center['id']}/archive")->assertStatus(422);

        $this->assertSame('active', CostCenter::query()->where('public_id', $center['id'])->firstOrFail()->status);
    }

    public function test_exact_allocations_must_sum_to_line_amount(): void
    {
        $center1 = $this->center(['code' => 'CC-A']);
        $center2 = $this->center(['code' => 'CC-B']);
        $line = $this->postLine('6100', 100000, 0);

        // Two exact allocations summing to 100000: OK.
        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center1['id'], 'allocation_type' => 'exact', 'amount_minor' => 60000],
            ['cost_center_id' => $center2['id'], 'allocation_type' => 'exact', 'amount_minor' => 40000],
        ])->assertCreated();

        // Sum exceeds line amount: rejected.
        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center1['id'], 'allocation_type' => 'exact', 'amount_minor' => 70000],
            ['cost_center_id' => $center2['id'], 'allocation_type' => 'exact', 'amount_minor' => 50000],
        ])->assertStatus(422);

        $this->assertSame(2, CostCenterAllocation::query()->count());
    }

    public function test_percent_allocations_must_sum_to_100(): void
    {
        $center1 = $this->center(['code' => 'CC-P1']);
        $center2 = $this->center(['code' => 'CC-P2']);
        $line = $this->postLine('6100', 100000, 0);

        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center1['id'], 'allocation_type' => 'percent', 'percent' => 60],
            ['cost_center_id' => $center2['id'], 'allocation_type' => 'percent', 'percent' => 40],
        ])->assertCreated();

        // Percent sum != 100: rejected.
        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center1['id'], 'allocation_type' => 'percent', 'percent' => 70],
            ['cost_center_id' => $center2['id'], 'allocation_type' => 'percent', 'percent' => 20],
        ])->assertStatus(422);
    }

    public function test_mixed_exact_and_percent_allocations(): void
    {
        $center1 = $this->center(['code' => 'CC-M1']);
        $center2 = $this->center(['code' => 'CC-M2']);
        $line = $this->postLine('6100', 100000, 0);

        // Exact 30000 + 70% of remaining (70000) = 30000 + 49000 = 79000 != 100000: rejected.
        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center1['id'], 'allocation_type' => 'exact', 'amount_minor' => 30000],
            ['cost_center_id' => $center2['id'], 'allocation_type' => 'percent', 'percent' => 70],
        ])->assertStatus(422);

        // Exact 30000 + 100% of remaining (70000) = 100000: OK.
        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center1['id'], 'allocation_type' => 'exact', 'amount_minor' => 30000],
            ['cost_center_id' => $center2['id'], 'allocation_type' => 'percent', 'percent' => 100],
        ])->assertCreated();
    }

    /** P1-07: 100 minor units split 3 ways must sum exactly to 100 (33.33/33.33/33.34). */
    public function test_percent_allocation_splits_exactly_with_largest_remainder(): void
    {
        $c1 = $this->center(['code' => 'CC-P1']);
        $c2 = $this->center(['code' => 'CC-P2']);
        $c3 = $this->center(['code' => 'CC-P3']);
        $line = $this->postLine('6100', 100, 0);

        $this->allocate($line['line_id'], [
            ['cost_center_id' => $c1['id'], 'allocation_type' => 'percent', 'percent' => 33.33],
            ['cost_center_id' => $c2['id'], 'allocation_type' => 'percent', 'percent' => 33.33],
            ['cost_center_id' => $c3['id'], 'allocation_type' => 'percent', 'percent' => 33.34],
        ])->assertCreated();

        $allocs = CostCenterAllocation::query()
            ->where('journal_line_id', $line['line_id'])
            ->orderBy('cost_center_id')
            ->get();

        $this->assertCount(3, $allocs);
        $this->assertSame(33, $allocs[0]->amount_minor);
        $this->assertSame(33, $allocs[1]->amount_minor);
        $this->assertSame(34, $allocs[2]->amount_minor);
        $this->assertSame(100, $allocs->sum('amount_minor'));
    }

    public function test_profit_and_loss_report_by_cost_center(): void
    {
        $center = $this->center(['code' => 'CC-PL']);
        $line = $this->postLine('4100', 0, 100000); // revenue credit

        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center['id'], 'allocation_type' => 'exact', 'amount_minor' => 100000],
        ])->assertCreated();

        $report = $this->withHeaders($this->headers())->getJson(
            '/api/v1/reports/cost-center-pl?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString()
        )->assertOk()->json('data');

        $this->assertSame(1, count($report['centers']));
        $this->assertSame(100000, $report['centers'][0]['allocated_revenue_minor']);
        $this->assertSame(0, $report['centers'][0]['allocated_expense_minor']);
        $this->assertSame(100000, $report['grand_allocated_revenue_minor']);
    }

    public function test_unallocated_report_shows_lines_without_allocations(): void
    {
        $this->postLine('6100', 50000, 0); // no allocation
        $allocatedLine = $this->postLine('6100', 50000, 0);
        $center = $this->center();
        $this->allocate($allocatedLine['line_id'], [
            ['cost_center_id' => $center['id'], 'allocation_type' => 'exact', 'amount_minor' => 50000],
        ])->assertCreated();

        $report = $this->withHeaders($this->headers())->getJson(
            '/api/v1/reports/cost-center-unallocated?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString()
        )->assertOk()->json('data');

        // Entry 1: debit 6100 (unallocated) + credit 3200 (unallocated).
        // Entry 2: debit 6100 (allocated) + credit 3200 (unallocated).
        $this->assertSame(3, $report['unallocated_line_count']);
        $this->assertSame(150000, $report['unallocated_total_minor']);
    }

    public function test_allocations_only_against_posted_lines(): void
    {
        $center = $this->center();
        $draftPublicId = $this->withHeaders($this->headers())->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'post' => false,
            'lines' => [
                ['account_id' => '6100', 'debit_minor' => 10000, 'credit_minor' => 0],
                ['account_id' => '3200', 'debit_minor' => 0, 'credit_minor' => 10000],
            ],
        ])->assertCreated()->json('data.id');

        $draftEntry = JournalEntry::query()->where('public_id', $draftPublicId)->firstOrFail();
        $draftLine = JournalLine::query()->where('journal_entry_id', $draftEntry->id)->firstOrFail();

        $this->allocate($draftLine->id, [
            ['cost_center_id' => $center['id'], 'allocation_type' => 'exact', 'amount_minor' => 10000],
        ])->assertStatus(422);
    }

    /** P1-07: Trial balance with cost-center filter shows balanced=false when only one side allocated. */
    public function test_trial_balance_with_cost_center_filter_shows_balanced_flag(): void
    {
        $center = $this->center(['code' => 'CC-TB']);

        // Create a journal entry with debit to expense and credit to revenue.
        // Allocate only the debit side (expense) to the cost center.
        $line = $this->postLine('6100', 100000, 0); // expense debit
        $this->allocate($line['line_id'], [
            ['cost_center_id' => $center['id'], 'allocation_type' => 'exact', 'amount_minor' => 100000],
        ])->assertCreated();

        $report = $this->withHeaders($this->headers())->getJson(
            '/api/v1/reports/trial-balance?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
                'cost_center_id' => $center['id'],
            ])
        )->assertOk()->json('data');

        // With only the expense debit allocated, debits > credits → not balanced.
        $this->assertFalse($report['balanced']);
        $this->assertSame(100000, $report['total_debit_minor']);
        $this->assertSame(0, $report['total_credit_minor']);
    }

    public function test_permissions_are_enforced(): void
    {
        $this->center();

        // A user with only view permission cannot create cost centers.
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo(['cost_centers.view', 'journal.view', 'journal.create']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);
        $this->withHeaders($this->headers())->getJson('/api/v1/cost-centers')->assertOk();
        $this->withHeaders($this->headers())->postJson('/api/v1/cost-centers', [
            'code' => 'CC-VIEWER', 'name_ar' => 'x', 'name_en' => 'x',
        ])->assertStatus(403);

        // A user with no cost center permissions cannot view or create.
        $nobody = User::factory()->create();
        $nobody->branches()->attach($this->branch->id);
        $nobody->givePermissionTo(['journal.view', 'journal.create']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($nobody);
        $this->withHeaders($this->headers())->getJson('/api/v1/cost-centers')->assertStatus(403);
        $this->withHeaders($this->headers())->postJson('/api/v1/cost-centers', [])->assertStatus(403);
    }
}
