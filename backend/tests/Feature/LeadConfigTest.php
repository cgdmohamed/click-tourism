<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\AssignmentRule;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use App\Modules\CRM\Services\LeadService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CRM-02: lead sources and pipeline stages are configurable records
 * instead of hard-coded arrays in the UI. Codes are immutable, the fallback
 * `other` source / `new` stage cannot be deleted, and in-use records are
 * never hard-deleted.
 */
class LeadConfigTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);

        $permissions = ['leads.view', 'leads.create', 'leads.update', 'leads.convert', 'leads.manage_rules'];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permissions);

        Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    private function createLead(string $source = 'facebook', string $stage = 'new'): Lead
    {
        return app(LeadService::class)->create([
            'name' => 'عميل محتمل',
            'phone' => '01000000010',
            'source' => $source,
            'stage' => $stage,
            'branch_id' => $this->branch->public_id,
        ], $this->user->id);
    }

    // ---------------------------------------------------------------- sources

    public function test_it_lists_active_sources_ordered_by_code(): void
    {
        LeadSource::query()->where('code', 'referral')->update(['is_active' => false]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/lead-sources')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertNotContains('referral', $codes);
        $this->assertSame(['facebook', 'instagram', 'other', 'phone', 'walk_in', 'website', 'whatsapp'], $codes);

        $withInactive = $this->withHeaders($this->headers())
            ->getJson('/api/v1/lead-sources?include_inactive=1')
            ->assertOk();

        $this->assertContains('referral', collect($withInactive->json('data'))->pluck('code')->all());
    }

    public function test_it_creates_a_source_with_a_normalised_code_and_audits_it(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-sources', [
                'code' => '  TikTok ',
                'name_ar' => 'تيك توك',
                'name_en' => 'TikTok',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'tiktok')
            ->assertJsonPath('data.name_en', 'TikTok')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('lead_sources', ['code' => 'tiktok', 'name_en' => 'TikTok']);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead_source.created']);
    }

    public function test_a_source_code_must_be_unique_and_valid(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-sources', [
                'code' => 'facebook',
                'name_ar' => 'مكرر',
                'name_en' => 'Duplicate',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-sources', [
                'code' => 'has space',
                'name_ar' => 'مسافة',
                'name_en' => 'Space',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-sources', ['name_ar' => 'بدون كود'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_en'])
            ->assertJsonMissingValidationErrors(['code']);
    }

    public function test_it_updates_a_source_without_changing_its_code(): void
    {
        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/lead-sources/facebook', [
                'code' => 'renamed',
                'name_ar' => 'فيسبوك (محدث)',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'facebook')
            ->assertJsonPath('data.name_ar', 'فيسبوك (محدث)')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('lead_sources', ['code' => 'facebook', 'is_active' => false]);
        $this->assertDatabaseMissing('lead_sources', ['code' => 'renamed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead_source.updated']);
    }

    public function test_it_deletes_an_unused_source(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-sources', [
                'code' => 'tiktok', 'name_ar' => 'تيك توك', 'name_en' => 'TikTok',
            ])
            ->assertCreated();

        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-sources/tiktok')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('lead_sources', ['code' => 'tiktok']);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead_source.deleted']);
    }

    public function test_it_refuses_to_delete_a_source_used_by_a_lead(): void
    {
        $this->createLead('facebook');

        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-sources/facebook')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        $this->assertDatabaseHas('lead_sources', ['code' => 'facebook']);
    }

    public function test_it_never_deletes_the_fallback_other_source(): void
    {
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-sources/other')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        $this->assertDatabaseHas('lead_sources', ['code' => 'other']);
    }

    public function test_assignment_rules_reference_sources_by_code(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/assignment-rules', [
                'priority' => 5,
                'source_id' => 'facebook',
                'strategy' => 'round_robin',
            ])
            ->assertCreated()
            ->assertJsonPath('data.source_id', 'facebook');

        $rule = AssignmentRule::query()->firstOrFail();
        $this->assertSame(LeadSource::query()->where('code', 'facebook')->value('id'), $rule->source_id);

        // A source used by a rule is in use and cannot be deleted.
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-sources/facebook')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/assignment-rules', ['source_id' => 'missing', 'strategy' => 'manual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_id');
    }

    // ----------------------------------------------------------------- stages

    public function test_it_lists_stages_in_pipeline_order(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/lead-stages')
            ->assertOk();

        $stages = collect($response->json('data'));
        $this->assertSame(
            ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'],
            $stages->pluck('code')->all(),
        );
        $this->assertTrue($stages->firstWhere('code', 'won')['is_won']);
        $this->assertTrue($stages->firstWhere('code', 'lost')['is_lost']);
    }

    public function test_it_creates_and_updates_a_stage(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-stages', [
                'code' => ' Negotiation ',
                'name_ar' => 'تفاوض',
                'name_en' => 'Negotiation',
                'sort_order' => 45,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'negotiation')
            ->assertJsonPath('data.sort_order', 45);

        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/lead-stages/negotiation', [
                'code' => 'hijacked',
                'name_en' => 'Negotiating',
                'sort_order' => 46,
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'negotiation')
            ->assertJsonPath('data.name_en', 'Negotiating')
            ->assertJsonPath('data.sort_order', 46);

        $this->assertDatabaseHas('audit_events', ['action' => 'lead_stage.created']);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead_stage.updated']);
    }

    public function test_a_stage_cannot_be_both_won_and_lost(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-stages', [
                'code' => 'confusing',
                'name_ar' => 'مربك',
                'name_en' => 'Confusing',
                'is_won' => true,
                'is_lost' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_won');

        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/lead-stages/won', ['is_lost' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_won');
    }

    public function test_a_custom_won_stage_sets_the_lead_status(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-stages', [
                'code' => 'booked',
                'name_ar' => 'تم الحجز',
                'name_en' => 'Booked',
                'sort_order' => 55,
                'is_won' => true,
            ])
            ->assertCreated();

        $lead = $this->createLead();

        $this->withHeaders($this->headers())
            ->patchJson("/api/v1/leads/{$lead->public_id}", ['stage' => 'booked'])
            ->assertOk();

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertSame('booked', $lead->stage?->code);
    }

    public function test_it_deletes_an_unused_stage(): void
    {
        LeadStage::query()->create([
            'code' => 'negotiation', 'name_ar' => 'تفاوض', 'name_en' => 'Negotiation', 'sort_order' => 45,
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-stages/negotiation')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('lead_stages', ['code' => 'negotiation']);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead_stage.deleted']);
    }

    public function test_it_refuses_to_delete_a_stage_used_by_a_lead(): void
    {
        $this->createLead('facebook', 'contacted');

        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-stages/contacted')
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');

        $this->assertDatabaseHas('lead_stages', ['code' => 'contacted']);
    }

    public function test_it_never_deletes_the_fallback_new_stage(): void
    {
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-stages/new')
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');

        $this->assertDatabaseHas('lead_stages', ['code' => 'new']);
    }

    // ------------------------------------------------------------ permissions

    public function test_reading_needs_leads_view_and_writing_needs_manage_rules(): void
    {
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo(['leads.view']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);

        $this->withHeaders($this->headers())->getJson('/api/v1/lead-sources')->assertOk();
        $this->withHeaders($this->headers())->getJson('/api/v1/lead-stages')->assertOk();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-sources', ['code' => 'tiktok', 'name_ar' => 'تيك توك', 'name_en' => 'TikTok'])
            ->assertStatus(403);
        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/lead-sources/facebook', ['name_en' => 'FB'])
            ->assertStatus(403);
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-sources/facebook')
            ->assertStatus(403);
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/lead-stages', ['code' => 'x', 'name_ar' => 'س', 'name_en' => 'X'])
            ->assertStatus(403);
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-stages/contacted')
            ->assertStatus(403);

        $this->assertSame(0, AuditEvent::query()->whereIn('action', [
            'lead_source.created', 'lead_source.updated', 'lead_source.deleted',
            'lead_stage.created', 'lead_stage.updated', 'lead_stage.deleted',
        ])->count());

        // Without leads.view, reads are denied too.
        $viewer->revokePermissionTo('leads.view');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);
        $this->withHeaders($this->headers())->getJson('/api/v1/lead-sources')->assertStatus(403);
        $this->withHeaders($this->headers())->getJson('/api/v1/lead-stages')->assertStatus(403);
    }

    public function test_unknown_codes_return_not_found(): void
    {
        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/lead-sources/missing', ['name_en' => 'Missing'])
            ->assertNotFound();
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/lead-stages/missing')
            ->assertNotFound();
    }
}
