<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CRM-04: a capture matching an existing lead by normalized phone/email
 * inside the configured window is kept but flagged and linked instead of
 * silently duplicated.
 */
class LeadDuplicateTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);

        foreach (['leads.view', 'leads.create', 'leads.update'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['leads.view', 'leads.create', 'leads.update']);
    }

    private function leads(): LeadService
    {
        return app(LeadService::class);
    }

    private function lead(array $overrides = []): Lead
    {
        return $this->leads()->create(array_merge([
            'name' => 'مصطفى كامل',
            'phone' => '01000000010',
            'email' => 'mostafa@example.com',
            'source' => 'facebook',
            'branch_id' => $this->branch->public_id,
        ], $overrides), $this->user->id);
    }

    public function test_a_capture_with_the_same_normalized_phone_is_flagged_and_linked(): void
    {
        $original = $this->lead();

        // Same number, different formatting and email.
        $duplicate = $this->lead([
            'name' => 'Mostafa K.',
            'phone' => '+20 100 000 0010',
            'email' => 'other@example.com',
        ]);

        $this->assertSame($original->id, $duplicate->duplicate_of_lead_id);
        $this->assertSame('phone', $duplicate->duplicate_reason);
        $this->assertSame($original->public_id, $duplicate->duplicateOf?->public_id);
        $this->assertSame('duplicate_flagged', $duplicate->activities()->orderByDesc('id')->first()?->type);
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'lead.duplicate_flagged')
                ->where('entity_id', $duplicate->id)
                ->exists()
        );
    }

    public function test_a_capture_with_the_same_email_is_flagged_case_insensitively(): void
    {
        $original = $this->lead(['phone' => '01000000010']);

        $duplicate = $this->lead([
            'phone' => '01111111111',
            'email' => '  MOSTAFA@Example.com ',
        ]);

        $this->assertSame($original->id, $duplicate->duplicate_of_lead_id);
        $this->assertSame('email', $duplicate->duplicate_reason);
    }

    public function test_both_phone_and_email_matches_are_reported(): void
    {
        $original = $this->lead();

        $duplicate = $this->lead([
            'phone' => '0100 000 0010',
            'email' => 'MOSTAFA@example.com',
        ]);

        $this->assertSame($original->id, $duplicate->duplicate_of_lead_id);
        $this->assertSame('phone,email', $duplicate->duplicate_reason);
    }

    public function test_a_match_outside_the_configured_window_is_not_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Africa/Cairo'));
        $this->lead();

        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Africa/Cairo'));
        $duplicate = $this->lead(['phone' => '01000000010']);

        $this->assertNull($duplicate->duplicate_of_lead_id);
        $this->assertNull($duplicate->duplicate_reason);
        Carbon::setTestNow();
    }

    public function test_the_window_can_be_shortened_or_disabled(): void
    {
        app(SettingService::class)->set('crm', 'duplicate_window_days', '0');
        $this->lead();

        $duplicate = $this->lead(['phone' => '01000000010']);

        $this->assertNull($duplicate->duplicate_of_lead_id);
    }

    public function test_a_match_in_another_branch_is_not_flagged(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2',
            'name_ar' => 'فرع',
            'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->leads()->create([
            'name' => 'Foreign lead',
            'phone' => '01000000010',
            'email' => 'mostafa@example.com',
            'source' => 'facebook',
            'branch_id' => $other->public_id,
        ], null);

        $duplicate = $this->lead(['phone' => '01000000010']);

        $this->assertNull($duplicate->duplicate_of_lead_id);
    }

    public function test_the_api_returns_the_duplicate_reference(): void
    {
        $original = $this->lead();
        Sanctum::actingAs($this->user);

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->postJson('/api/v1/leads', [
                'name' => 'Mostafa again',
                'phone' => '0100-000-0010',
                'email' => 'new@example.com',
                'source' => 'facebook',
                'branch_id' => $this->branch->public_id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.duplicate_of.id', $original->public_id)
            ->assertJsonPath('data.duplicate_of.name', $original->name)
            ->assertJsonPath('data.duplicate_reason', 'phone');
    }

    public function test_a_converted_lead_inside_the_window_still_flags_the_match(): void
    {
        $original = $this->lead();
        $this->leads()->convert($original, $this->user->id);

        $duplicate = $this->lead(['phone' => '01000000010']);

        $this->assertSame($original->id, $duplicate->duplicate_of_lead_id);
    }

    public function test_leads_without_phone_or_email_are_never_flagged(): void
    {
        $this->lead(['phone' => null, 'email' => null]);

        $lead = $this->lead(['phone' => null, 'email' => null]);

        $this->assertNull($lead->duplicate_of_lead_id);
        $this->assertSame(2, Lead::query()->count());
    }
}
