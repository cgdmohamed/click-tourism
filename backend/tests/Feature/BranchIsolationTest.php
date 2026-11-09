<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * A user assigned only to the head office must not read or change records that
 * belong to another branch just by knowing their id (ADR-0005).
 */
class BranchIsolationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Branch $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);

        $this->other = Branch::query()->create([
            'code' => 'B2',
            'name_ar' => 'فرع',
            'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->owner = User::factory()->create();
        $this->owner->branches()->attach([$this->branch->id, $this->other->id]);

        $permissions = [
            'bookings.view', 'payments.view', 'customers.update', 'suppliers.update',
            'leads.update', 'leads.convert', 'journal.view', 'journal.create', 'journal.reverse',
        ];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permissions);
        $this->owner->givePermissionTo($permissions);

        Sanctum::actingAs($this->user);
    }

    private function booking(Branch $branch): Booking
    {
        return app(BookingService::class)->create([
            'branch_id' => $branch->public_id,
            'customer_id' => $this->customer($branch)->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [[
                'service_type' => 'hotel',
                'description' => 'Hotel',
                'quantity' => 1,
                'unit_cost_minor' => 0,
                'unit_sell_minor' => 100000,
            ]],
        ], $this->owner->id);
    }

    private function payment(Branch $branch): Payment
    {
        return app(PaymentService::class)->record([
            'branch_id' => $branch->public_id,
            'customer_id' => $this->customer($branch)->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'method' => 'cash',
            'amount_minor' => 50000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->owner->id);
    }

    private function lead(Branch $branch): Lead
    {
        return app(LeadService::class)->create([
            'name' => 'Lead',
            'phone' => '01000000099',
            'source' => 'facebook',
            'branch_id' => $branch->public_id,
            'consent' => true,
        ], $this->owner->id);
    }

    private function journalEntry(Branch $branch, bool $post): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);

        $id = $this->withHeaders(['X-Branch-Id' => $branch->public_id])
            ->postJson('/api/v1/journal-entries', [
                'entry_date' => now()->toDateString(),
                'currency' => 'EGP',
                'fx_rate' => '1',
                'post' => $post,
                'lines' => [
                    ['account_id' => '1110', 'debit_minor' => 1000, 'credit_minor' => 0],
                    ['account_id' => '4100', 'debit_minor' => 0, 'credit_minor' => 1000],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user);

        return $id;
    }

    public function test_a_booking_in_another_branch_cannot_be_viewed(): void
    {
        $this->getJson('/api/v1/bookings/'.$this->booking($this->other)->public_id)->assertStatus(403);
        $this->getJson('/api/v1/bookings/'.$this->booking($this->branch)->public_id)->assertOk();
    }

    public function test_a_payment_in_another_branch_cannot_be_viewed(): void
    {
        $this->getJson('/api/v1/payments/'.$this->payment($this->other)->public_id)->assertStatus(403);
        $this->getJson('/api/v1/payments/'.$this->payment($this->branch)->public_id)->assertOk();
    }

    public function test_a_customer_in_another_branch_cannot_be_updated(): void
    {
        $foreign = $this->customer($this->other);

        $this->patchJson('/api/v1/customers/'.$foreign->public_id, ['name_en' => 'Hijacked'])->assertStatus(403);
        $this->assertNotSame('Hijacked', $foreign->fresh()->name_en);

        $own = Customer::query()->create([
            'type' => 'individual', 'name_ar' => 'س', 'name_en' => 'Own', 'branch_id' => $this->branch->id,
            'credit_limit_minor' => 0, 'currency_code' => 'EGP', 'is_active' => true,
        ]);
        $this->patchJson('/api/v1/customers/'.$own->public_id, ['name_en' => 'Renamed'])->assertOk();
    }

    public function test_a_supplier_in_another_branch_cannot_be_updated(): void
    {
        $foreign = $this->supplier($this->other);

        $this->patchJson('/api/v1/suppliers/'.$foreign->public_id, ['name_en' => 'Hijacked'])->assertStatus(403);
        $this->assertNotSame('Hijacked', $foreign->fresh()->name_en);

        $own = $this->supplier();
        $this->patchJson('/api/v1/suppliers/'.$own->public_id, ['name_en' => 'Renamed'])->assertOk();
        $this->assertInstanceOf(Supplier::class, $own);
    }

    public function test_a_lead_in_another_branch_cannot_be_updated_or_converted(): void
    {
        $foreign = $this->lead($this->other);

        $this->patchJson('/api/v1/leads/'.$foreign->public_id, ['name' => 'Hijacked'])->assertStatus(403);
        $this->postJson('/api/v1/leads/'.$foreign->public_id.'/convert')->assertStatus(403);
        $this->assertSame('Lead', $foreign->fresh()->name);
        $this->assertNull($foreign->fresh()->customer_id ?? null);

        $own = $this->lead($this->branch);
        $this->patchJson('/api/v1/leads/'.$own->public_id, ['name' => 'Renamed'])->assertOk();
    }

    public function test_a_journal_entry_in_another_branch_cannot_be_viewed_posted_or_reversed(): void
    {
        $posted = $this->journalEntry($this->other, true);
        $draft = $this->journalEntry($this->other, false);

        $this->getJson("/api/v1/journal-entries/{$posted}")->assertStatus(403);
        $this->postJson("/api/v1/journal-entries/{$draft}/post")->assertStatus(403);
        $this->postJson("/api/v1/journal-entries/{$posted}/reverse", ['reason' => 'x'])->assertStatus(403);

        $own = $this->journalEntry($this->branch, true);
        $this->getJson("/api/v1/journal-entries/{$own}")->assertOk();
    }

    public function test_a_user_assigned_to_both_branches_can_open_either(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/bookings/'.$this->booking($this->other)->public_id)->assertOk();
        $this->getJson('/api/v1/bookings/'.$this->booking($this->branch)->public_id)->assertOk();
    }
}
