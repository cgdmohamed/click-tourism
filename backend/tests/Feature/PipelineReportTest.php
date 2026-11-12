<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use App\Modules\CRM\Services\PipelineReportService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CRM-05: pipeline metrics by stage/source/owner for leads captured in a
 * period, scoped to the active branch, and reconciling with lead data.
 */
class PipelineReportTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Africa/Cairo'));
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);

        foreach (['leads.view', 'reports.view'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['leads.view', 'reports.view']);

        $this->owner = User::factory()->create(['name' => 'Sales One']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sourceId(string $code): int
    {
        return (int) LeadSource::query()->where('code', $code)->value('id');
    }

    private function stageId(string $code): int
    {
        return (int) LeadStage::query()->where('code', $code)->value('id');
    }

    private function lead(array $overrides = []): Lead
    {
        return Lead::query()->forceCreate(array_merge([
            'source_id' => $this->sourceId('facebook'),
            'stage_id' => $this->stageId('new'),
            'name' => 'Lead '.++$this->seq,
            'phone' => '0100000'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id,
            'owner_user_id' => $this->owner->id,
            'status' => 'open',
            'consent' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function report(array $filters = []): array
    {
        return app(PipelineReportService::class)->report($this->branch, $filters + ['period' => 'thisMonth']);
    }

    public function test_it_breaks_captured_leads_down_by_stage_source_and_owner(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->lead();
        }
        $this->lead(['source_id' => $this->sourceId('facebook'), 'stage_id' => $this->stageId('won'), 'status' => 'won']);
        $this->lead(['source_id' => $this->sourceId('website'), 'stage_id' => $this->stageId('lost'), 'status' => 'lost']);
        $this->lead(['source_id' => $this->sourceId('website'), 'owner_user_id' => null]);

        $report = $this->report();

        $this->assertSame(7, $report['totals']['captured']);
        $this->assertSame(5, $report['totals']['open']);
        $this->assertSame(1, $report['totals']['won']);
        $this->assertSame(1, $report['totals']['lost']);
        $this->assertSame(1, $report['totals']['unassigned']);
        $this->assertSame(14.3, $report['totals']['conversion_rate']);

        // Stage and source breakdowns reconcile with the captured total.
        $this->assertSame(7, array_sum(array_column($report['by_stage'], 'count')));
        $this->assertSame(7, array_sum(array_column($report['by_source'], 'count')));

        $stages = collect($report['by_stage'])->keyBy('stage');
        $this->assertSame(5, $stages['new']['count']);
        $this->assertSame(1, $stages['won']['count']);
        $this->assertTrue($stages['won']['is_won']);
        $this->assertSame(71.4, $stages['new']['share']);

        $sources = collect($report['by_source'])->keyBy('source');
        $this->assertSame(5, $sources['facebook']['count']);
        $this->assertSame(1, $sources['facebook']['won']);
        $this->assertSame(20.0, $sources['facebook']['conversion_rate']);
        $this->assertSame(2, $sources['website']['count']);
        $this->assertSame(0.0, $sources['website']['conversion_rate']);

        $owners = collect($report['by_owner'])->keyBy('name');
        $this->assertSame(6, $owners['Sales One']['count']);
        $this->assertSame(4, $owners['Sales One']['open']);
        $this->assertSame(1, $owners['Sales One']['won']);
        $this->assertSame(1, $owners['Sales One']['lost']);
        $this->assertSame(16.7, $owners['Sales One']['conversion_rate']);
        $this->assertNull($owners['']['owner']);
        $this->assertSame(1, $owners['']['count']);
    }

    public function test_it_counts_converted_leads(): void
    {
        $converted = $this->lead([
            'stage_id' => $this->stageId('won'),
            'status' => 'won',
            'converted_customer_id' => $this->customer()->id,
        ]);
        $this->lead();

        $report = $this->report();

        $this->assertSame(2, $report['totals']['captured']);
        $this->assertSame(1, $report['totals']['converted']);
        $this->assertSame($converted->id, Lead::query()->whereNotNull('converted_customer_id')->value('id'));
    }

    public function test_the_period_excludes_leads_captured_outside_it(): void
    {
        $this->lead();
        $this->lead(['created_at' => Carbon::parse('2026-10-01 09:00:00', 'Africa/Cairo')]);

        $this->assertSame(1, $this->report()['totals']['captured']);
        $this->assertSame(1, $this->report(['period' => 'lastMonth'])['totals']['captured']);
    }

    public function test_custom_range_is_honoured(): void
    {
        $this->lead(['created_at' => Carbon::parse('2026-11-01 09:00:00', 'Africa/Cairo')]);
        $this->lead(['created_at' => Carbon::parse('2026-11-05 09:00:00', 'Africa/Cairo')]);
        $this->lead(['created_at' => Carbon::parse('2026-11-10 09:00:00', 'Africa/Cairo')]);

        $report = $this->report(['period' => 'customRange', 'from' => '2026-11-01', 'to' => '2026-11-05']);

        $this->assertSame('2026-11-01', $report['from']);
        $this->assertSame('2026-11-05', $report['to']);
        $this->assertSame(2, $report['totals']['captured']);
    }

    public function test_another_branch_is_excluded(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2',
            'name_ar' => 'فرع',
            'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->lead();
        $this->lead(['branch_id' => $other->id]);

        $report = $this->report();

        $this->assertSame(1, $report['totals']['captured']);
        $this->assertSame($this->branch->public_id, $report['branch_id']);
    }

    public function test_the_api_returns_the_report_for_users_with_leads_view(): void
    {
        $this->lead();
        $this->lead(['stage_id' => $this->stageId('won'), 'status' => 'won']);
        Sanctum::actingAs($this->user);

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/reports/pipeline?period=thisMonth')
            ->assertOk()
            ->assertJsonPath('data.totals.captured', 2)
            ->assertJsonPath('data.totals.won', 1)
            ->assertJsonPath('data.branch_id', $this->branch->public_id)
            ->assertJsonStructure([
                'data' => [
                    'from',
                    'to',
                    'totals' => ['captured', 'open', 'won', 'lost', 'converted', 'unassigned', 'conversion_rate'],
                    'by_stage' => [['stage', 'name_ar', 'name_en', 'is_won', 'is_lost', 'count', 'share']],
                    'by_source' => [['source', 'name_ar', 'name_en', 'count', 'won', 'conversion_rate']],
                    'by_owner' => [['owner', 'name', 'count', 'open', 'won', 'lost', 'conversion_rate']],
                ],
            ]);
    }

    public function test_the_api_requires_leads_view(): void
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/reports/pipeline')
            ->assertForbidden();
    }

    public function test_custom_range_requires_both_dates(): void
    {
        Sanctum::actingAs($this->user);

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/reports/pipeline?period=customRange')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);
    }
}
