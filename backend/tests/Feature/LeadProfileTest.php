<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CRM-03: a lead detail endpoint exposing the activity timeline, the
 * current owner, and the owner changes recorded in the audit trail.
 */
class LeadProfileTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);

        $permissions = ['leads.view', 'leads.create', 'leads.update', 'leads.convert'];
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

    private function lead(array $overrides = []): Lead
    {
        return app(LeadService::class)->create(array_merge([
            'name' => 'مصطفى كامل',
            'phone' => '01000000010',
            'email' => 'lead@example.com',
            'source' => 'facebook',
            'branch_id' => $this->branch->public_id,
            'consent' => true,
        ], $overrides), $this->user->id);
    }

    public function test_it_returns_the_lead_with_its_activity_timeline(): void
    {
        $lead = $this->lead();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/'.$lead->public_id)
            ->assertOk()
            ->assertJsonPath('data.id', $lead->public_id)
            ->assertJsonPath('data.name', 'مصطفى كامل')
            ->assertJsonPath('data.email', 'lead@example.com')
            ->assertJsonPath('data.source', 'facebook')
            ->assertJsonPath('data.stage', 'new')
            ->assertJsonPath('data.consent', true)
            ->assertJsonPath('data.owner.id', $this->user->public_id)
            ->assertJsonPath('data.owner.name', $this->user->name)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.activities.0.type', 'created')
            ->assertJsonPath('data.activities.0.actor.id', $this->user->public_id)
            ->assertJsonPath('data.activities.0.actor.name', $this->user->name);

        $this->assertNotEmpty($response->json('data.activities.0.created_at'));
    }

    public function test_activities_are_paginated_newest_first(): void
    {
        $lead = $this->lead();

        foreach (['first note', 'second note', 'third note'] as $note) {
            $this->withHeaders($this->headers())
                ->patchJson('/api/v1/leads/'.$lead->public_id, ['note' => $note])
                ->assertOk();
        }

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/'.$lead->public_id.'?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data.activities');

        $this->assertSame('third note', $response->json('data.activities.0.body'));
        $this->assertSame('second note', $response->json('data.activities.1.body'));

        $second = $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/'.$lead->public_id.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(2, 'data.activities');

        $this->assertSame(['first note', 'Lead captured'], collect($second->json('data.activities'))->pluck('body')->all());
    }

    public function test_owner_history_records_manual_reassignment(): void
    {
        $lead = $this->lead();

        $newOwner = User::factory()->create();
        $newOwner->branches()->attach($this->branch->id);

        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/leads/'.$lead->public_id, ['owner_user_id' => $newOwner->public_id])
            ->assertOk()
            ->assertJsonPath('data.owner_user_id', $newOwner->public_id);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/'.$lead->public_id)
            ->assertOk()
            ->assertJsonPath('data.owner.id', $newOwner->public_id)
            ->assertJsonPath('data.owner.name', $newOwner->name)
            ->assertJsonPath('data.owner_history.0.action', 'assigned')
            ->assertJsonPath('data.owner_history.0.from', null)
            ->assertJsonPath('data.owner_history.0.to.id', $newOwner->public_id)
            ->assertJsonPath('data.owner_history.0.to.name', $newOwner->name);

        $this->assertNotEmpty($response->json('data.owner_history.0.at'));
    }

    public function test_the_detail_endpoint_requires_leads_view(): void
    {
        $lead = $this->lead();

        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/'.$lead->public_id)
            ->assertStatus(403);
    }

    public function test_a_lead_in_another_branch_cannot_be_viewed(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2',
            'name_ar' => 'فرع',
            'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $foreign = app(LeadService::class)->create([
            'name' => 'Foreign lead',
            'phone' => '01000000011',
            'source' => 'facebook',
            'branch_id' => $other->public_id,
        ], null);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/'.$foreign->public_id)
            ->assertStatus(403);
    }

    public function test_an_unknown_lead_returns_not_found(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/leads/01JZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();
    }
}
