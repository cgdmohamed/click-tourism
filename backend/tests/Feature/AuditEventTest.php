<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CORE-02: operators can browse and export the append-only audit trail
 * with actor/action/entity/date/branch filters, scoped to their branches.
 */
class AuditEventTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (['audit.view', 'audit.export', 'branches.view_all'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user->givePermissionTo(['audit.view', 'audit.export']);
        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    private function event(array $overrides = []): AuditEvent
    {
        return AuditEvent::query()->create($overrides + [
            'actor_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'action' => 'lead.created',
            'entity_type' => 'Lead',
            'entity_id' => 1,
            'before' => null,
            'after' => ['name' => 'Test lead'],
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    private function secondBranch(): Branch
    {
        return Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'فرع الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);
    }

    public function test_audit_browsing_requires_the_audit_permission(): void
    {
        $user = User::factory()->create();
        $user->branches()->attach($this->branch->id);

        Sanctum::actingAs($user);

        $this->withHeaders($this->headers())->getJson('/api/v1/audit-events')->assertForbidden();
    }

    public function test_it_lists_events_newest_first_with_actor_entity_and_branch(): void
    {
        $older = $this->event(['created_at' => Carbon::parse('2026-01-10 09:00:00')]);
        $newer = $this->event([
            'action' => 'lead.updated',
            'entity_id' => 7,
            'created_at' => Carbon::parse('2026-01-11 09:00:00'),
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events')
            ->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame((string) $newer->id, $response->json('data.0.id'));
        $this->assertSame('lead.updated', $response->json('data.0.action'));
        $this->assertSame($this->user->public_id, $response->json('data.0.actor.id'));
        $this->assertSame($this->user->name, $response->json('data.0.actor.name'));
        $this->assertSame('Lead', $response->json('data.0.entity_type'));
        $this->assertSame(7, $response->json('data.0.entity_id'));
        $this->assertSame($this->branch->public_id, $response->json('data.0.branch.id'));
        $this->assertSame((string) $older->id, $response->json('data.1.id'));
    }

    public function test_it_filters_by_actor_action_entity_and_date(): void
    {
        $other = User::factory()->create(['name' => 'Nour Sales']);
        $other->branches()->attach($this->branch->id);

        $this->event([
            'actor_id' => $this->user->id,
            'action' => 'lead.created',
            'entity_type' => 'Lead',
            'entity_id' => 3,
            'created_at' => Carbon::parse('2026-01-10 09:00:00'),
        ]);
        $invoice = $this->event([
            'actor_id' => $other->id,
            'action' => 'invoice.approved',
            'entity_type' => 'Invoice',
            'entity_id' => 9,
            'created_at' => Carbon::parse('2026-02-10 09:00:00'),
        ]);

        $byActor = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?actor=Nour')
            ->assertOk();
        $this->assertSame(1, $byActor->json('meta.total'));
        $this->assertSame((string) $invoice->id, $byActor->json('data.0.id'));

        $byAction = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?action=invoice')
            ->assertOk();
        $this->assertSame(1, $byAction->json('meta.total'));
        $this->assertSame('invoice.approved', $byAction->json('data.0.action'));

        $byEntity = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?entity_type=Invoice&entity_id=9')
            ->assertOk();
        $this->assertSame(1, $byEntity->json('meta.total'));
        $this->assertSame((string) $invoice->id, $byEntity->json('data.0.id'));

        $byDate = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?from=2026-02-01&to=2026-02-28')
            ->assertOk();
        $this->assertSame(1, $byDate->json('meta.total'));
        $this->assertSame((string) $invoice->id, $byDate->json('data.0.id'));

        $outside = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?from=2026-03-01')
            ->assertOk();
        $this->assertSame(0, $outside->json('meta.total'));
    }

    public function test_a_branch_user_only_sees_their_branch_and_cannot_filter_another_branch(): void
    {
        $second = $this->secondBranch();

        $mine = $this->event();
        $this->event(['branch_id' => $second->id, 'action' => 'lead.created']);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame((string) $mine->id, $response->json('data.0.id'));

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/audit-events?branch={$second->public_id}")
            ->assertForbidden();
    }

    public function test_a_view_all_user_sees_every_branch_and_can_filter_one(): void
    {
        $this->user->givePermissionTo('branches.view_all');
        $second = $this->secondBranch();

        $this->event();
        $other = $this->event(['branch_id' => $second->id, 'action' => 'invoice.approved']);

        $all = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events')
            ->assertOk();
        $this->assertSame(2, $all->json('meta.total'));

        $filtered = $this->withHeaders($this->headers())
            ->getJson("/api/v1/audit-events?branch={$second->public_id}")
            ->assertOk();
        $this->assertSame(1, $filtered->json('meta.total'));
        $this->assertSame((string) $other->id, $filtered->json('data.0.id'));
    }

    public function test_it_paginates_the_trail(): void
    {
        foreach (range(1, 3) as $index) {
            $this->event([
                'entity_id' => $index,
                'created_at' => Carbon::parse("2026-01-0{$index} 09:00:00"),
            ]);
        }

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?per_page=2')
            ->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_it_exports_the_filtered_trail_as_csv_and_audits_the_export(): void
    {
        $this->event(['action' => 'lead.created']);
        $this->event(['action' => 'invoice.approved']);

        $response = $this->withHeaders($this->headers())
            ->get('/api/v1/audit-events/export?action=lead')
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('audit-events.csv', $response->headers->get('content-disposition'));

        $csv = $response->getContent();
        $this->assertStringContainsString('lead.created', $csv);
        $this->assertStringNotContainsString('invoice.approved', $csv);

        $exported = AuditEvent::query()->where('action', 'audit.exported')->sole();
        $this->assertSame(1, $exported->after['rows']);
        $this->assertSame('lead', $exported->after['filters']['action']);
    }

    public function test_export_requires_the_export_permission(): void
    {
        $this->user->revokePermissionTo('audit.export');

        $this->withHeaders($this->headers())
            ->get('/api/v1/audit-events/export')
            ->assertForbidden();
    }

    public function test_it_validates_the_date_range(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/audit-events?from=2026-02-01&to=2026-01-01')
            ->assertStatus(422);
    }

    public function test_audit_rows_take_the_branch_of_the_audited_entity_or_the_active_branch(): void
    {
        $customer = $this->customer();

        app(AuditService::class)->log('customer.updated', $customer);
        app(AuditService::class)->log('user.profile_updated');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'customer.updated',
            'entity_type' => 'Customer',
            'branch_id' => $this->branch->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.profile_updated',
            'branch_id' => $this->branch->id,
        ]);
    }
}
