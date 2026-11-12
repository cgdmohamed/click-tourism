<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CRM-01: the advertised 24-hour lead reassignment must actually run on a
 * schedule, reassign only stale open leads, and stay idempotent.
 */
class LeadReassignmentCommandTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);
    }

    private function lead(string $name): Lead
    {
        return app(LeadService::class)->create([
            'name' => $name,
            'phone' => '01000000010',
            'email' => 'lead@example.com',
            'source' => 'facebook',
            'branch_id' => $this->branch->public_id,
            'consent' => true,
        ], $this->user->id);
    }

    public function test_command_reassigns_only_stale_leads_and_is_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 14:00:00', 'Africa/Cairo'));
        app(SettingService::class)->set('crm', 'reassign_after_hours', '1');

        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);

        $stale = $this->lead('Stale lead');
        $stale->update([
            'owner_user_id' => $this->user->id,
            'last_activity_at' => Carbon::now()->subHours(2),
        ]);

        $active = $this->lead('Active lead');
        $active->update([
            'owner_user_id' => $this->user->id,
            'last_activity_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->artisan('crm:reassign-stale-leads')
            ->expectsOutput('1 lead(s) reassigned or escalated.')
            ->assertSuccessful();

        $this->assertSame($other->id, $stale->refresh()->owner_user_id);
        $this->assertSame($this->user->id, $active->refresh()->owner_user_id);
        $this->assertSame('reassigned', $stale->activities()->latest('id')->first()?->type);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'lead.reassigned',
            'entity_type' => 'Lead',
            'entity_id' => $stale->id,
        ]);

        // Reassignment moves last_activity_at to now, so an immediate rerun must
        // not bounce the lead again before the configured window elapses.
        $this->artisan('crm:reassign-stale-leads')
            ->expectsOutput('0 lead(s) reassigned or escalated.')
            ->assertSuccessful();

        $this->assertSame($other->id, $stale->refresh()->owner_user_id);
    }

    public function test_command_is_registered_on_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:reassign-stale-leads')
            ->assertSuccessful();
    }
}
