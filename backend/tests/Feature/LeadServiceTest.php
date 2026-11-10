<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\AssignmentRule;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Parties\Models\Customer;
use Database\Seeders\CrmSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class LeadServiceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);
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
            'consent' => true,
            'notes' => 'Asked about Luxor.',
        ], $overrides), $this->user->id);
    }

    public function test_it_creates_a_lead_with_source_stage_and_owner(): void
    {
        $lead = $this->lead();

        $this->assertSame('open', $lead->status);
        $this->assertSame('facebook', $lead->source?->code);
        $this->assertSame('new', $lead->stage?->code);
        $this->assertSame($this->user->id, $lead->owner_user_id);
        $this->assertSame(1, $lead->activities()->count());
    }

    public function test_moving_to_a_won_stage_marks_the_lead_won(): void
    {
        $lead = $this->lead();

        $updated = $this->leads()->update($lead, ['stage' => 'won'], $this->user->id);

        $this->assertSame('won', $updated->status);
        $this->assertSame('won', $updated->stage?->code);
    }

    public function test_converting_a_lead_creates_a_customer_and_prevents_double_conversion(): void
    {
        $lead = $this->lead();

        $customer = $this->leads()->convert($lead, $this->user->id);

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertSame('مصطفى كامل', $customer->name_ar);
        $this->assertSame($this->branch->id, $customer->branch_id);
        $this->assertSame($customer->id, $lead->refresh()->converted_customer_id);
        $this->assertSame('won', $lead->status);
        $this->assertSame(1, Customer::query()->count());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been converted');

        $this->leads()->convert($lead->refresh(), $this->user->id);
    }

    public function test_stale_leads_are_reassigned_to_another_owner(): void
    {
        app(SettingService::class)->set('crm', 'reassign_after_hours', '1');

        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);

        $lead = $this->lead();
        $lead->update(['last_activity_at' => Carbon::now()->subHours(2)]);

        $reassigned = $this->leads()->reassignStale();

        $this->assertSame(1, $reassigned);
        $this->assertSame($other->id, $lead->refresh()->owner_user_id);
        $this->assertSame(2, $lead->activities()->count());
    }

    public function test_active_leads_are_not_reassigned(): void
    {
        app(SettingService::class)->set('crm', 'reassign_after_hours', '24');

        User::factory()->create()->branches()->attach($this->branch->id);

        $lead = $this->lead();
        $lead->update(['last_activity_at' => Carbon::now()->subMinutes(10)]);

        $this->assertSame(0, $this->leads()->reassignStale());
        $this->assertSame($this->user->id, $lead->refresh()->owner_user_id);
    }

    public function test_assignment_rules_respect_priority_category_and_employee_availability(): void
    {
        $unavailable = User::factory()->create(['is_available_for_leads' => false]);
        $unavailable->branches()->attach($this->branch->id);
        $available = User::factory()->create();
        $available->branches()->attach($this->branch->id);
        AssignmentRule::query()->create([
            'priority' => 1,
            'branch_id' => $this->branch->id,
            'service_category' => 'flight',
            'strategy' => 'manual',
            'target_user_id' => $unavailable->id,
            'is_active' => true,
        ]);
        AssignmentRule::query()->create([
            'priority' => 2,
            'branch_id' => $this->branch->id,
            'service_category' => 'flight',
            'strategy' => 'manual',
            'target_user_id' => $available->id,
            'is_active' => true,
        ]);

        $lead = $this->lead(['service_category' => 'flight']);

        $this->assertSame($available->id, $lead->owner_user_id);
    }

    public function test_inactivity_counts_configured_business_hours_not_weekend_clock_hours(): void
    {
        app(SettingService::class)->set('crm', 'reassign_after_hours', '8');
        app(SettingService::class)->set('crm', 'business_days', '1,2,3,4,5');
        app(SettingService::class)->set('crm', 'business_day_start', '09:00');
        app(SettingService::class)->set('crm', 'business_day_end', '17:00');
        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);
        Carbon::setTestNow(Carbon::parse('2026-11-09 16:00:00', 'Africa/Cairo'));
        $lead = $this->lead();
        $lead->update(['last_activity_at' => Carbon::parse('2026-11-06 16:00:00', 'Africa/Cairo')]);

        $this->assertSame(0, $this->leads()->reassignStale());

        Carbon::setTestNow(Carbon::parse('2026-11-10 16:00:00', 'Africa/Cairo'));
        $this->assertSame(1, $this->leads()->reassignStale());
        $this->assertSame($other->id, $lead->refresh()->owner_user_id);
        Carbon::setTestNow();
    }
}
