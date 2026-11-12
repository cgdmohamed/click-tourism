<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Notification;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\CrmSeeder;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GAP-CORE-01 / GAP-CRM-06: per-user in-app notifications, emitted by lead
 * reassignment/escalation and by invoice/expense approval.
 */
class NotificationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'leads.manage_rules',
        'invoices.view', 'invoices.create', 'invoices.approve',
        'expenses.view', 'expenses.create', 'expenses.approve',
        'expense_categories.manage', 'accounts.post', 'journal.reverse',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);
        $this->seed(TreasurySeeder::class);

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function notification(User $user, string $type = 'test.notification'): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'branch_id' => $this->branch->id,
            'type' => $type,
            'title' => 'Test notification',
            'body' => 'Body',
            'data' => ['reference' => 'x'],
        ]);
    }

    private function lead(User $owner, array $overrides = [])
    {
        return app(LeadService::class)->create(array_merge([
            'name' => 'مصطفى كامل',
            'phone' => '01000000010',
            'email' => 'mostafa@example.com',
            'source' => 'facebook',
            'branch_id' => $this->branch->public_id,
            'consent' => true,
        ], $overrides), $owner->id);
    }

    private function customerInvoicePayload(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [[
                'account_id' => $this->account('4100')->public_id,
                'description' => 'Tour package sale',
                'credit_minor' => 100000,
            ]],
        ];
    }

    private function expensePayload(array $overrides = []): array
    {
        $cash = CashAccount::query()->whereHas('account', fn ($query) => $query->where('code', '1110'))->firstOrFail();
        $rent = ExpenseCategory::query()->where('code', 'RENT')->firstOrFail();

        return $overrides + [
            'category_id' => $rent->public_id,
            'expense_date' => now()->toDateString(),
            'amount_minor' => 115000,
            'tax_minor' => 15000,
            'currency' => 'EGP',
            'description' => 'Office rent',
            'cash_account_id' => $cash->public_id,
        ];
    }

    public function test_a_user_sees_only_their_own_notifications_with_an_unread_count(): void
    {
        $mineRead = $this->notification($this->user);
        $mineRead->update(['read_at' => now()]);
        $mineUnread = $this->notification($this->user);

        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);
        $theirs = $this->notification($other);

        $this->actAs($this->user);

        $response = $this->withHeaders($this->headers())->getJson('/api/v1/notifications')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.unread_count'));
        $this->assertEqualsCanonicalizing(
            [$mineRead->public_id, $mineUnread->public_id],
            array_column($response->json('data'), 'id'),
        );
        $this->assertNotContains($theirs->public_id, array_column($response->json('data'), 'id'));

        $unreadOnly = $this->withHeaders($this->headers())
            ->getJson('/api/v1/notifications?unread=1')
            ->assertOk();

        $this->assertSame(1, $unreadOnly->json('meta.total'));
        $this->assertSame($mineUnread->public_id, $unreadOnly->json('data.0.id'));
    }

    public function test_a_user_cannot_read_another_users_notification(): void
    {
        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);
        $theirs = $this->notification($other);

        $this->actAs($this->user);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/notifications/{$theirs->public_id}/read")
            ->assertStatus(403);

        $this->assertNull($theirs->refresh()->read_at);
    }

    public function test_marking_one_and_read_all_only_affects_the_signed_in_user(): void
    {
        $first = $this->notification($this->user);
        $this->notification($this->user);

        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);
        $theirs = $this->notification($other);

        $this->actAs($this->user);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/notifications/{$first->public_id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $first->public_id)
            ->assertJsonPath('data.read_at', $first->refresh()->read_at?->toIso8601String());

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, Notification::query()->where('user_id', $this->user->id)->whereNull('read_at')->count());
        $this->assertNull($theirs->refresh()->read_at);
    }

    public function test_reassignment_notifies_the_new_owner(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 14:00:00', 'Africa/Cairo'));
        app(SettingService::class)->set('crm', 'reassign_after_hours', '1');

        $newOwner = User::factory()->create();
        $newOwner->branches()->attach($this->branch->id);

        $lead = $this->lead($this->user);
        $lead->update(['last_activity_at' => Carbon::now()->subHours(2)]);

        $this->assertSame(1, app(LeadService::class)->reassignStale());

        $notification = Notification::query()->where('user_id', $newOwner->id)->sole();
        $this->assertSame('lead.reassigned', $notification->type);
        $this->assertSame($lead->public_id, $notification->data['lead_id']);
        $this->assertSame($this->branch->id, $notification->branch_id);
        $this->assertSame(0, Notification::query()->where('user_id', $this->user->id)->count());

        $this->travelBack();
    }

    public function test_escalation_notifies_supervisors_holding_the_rule_permission(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 14:00:00', 'Africa/Cairo'));
        app(SettingService::class)->set('crm', 'reassign_after_hours', '1');
        app(SettingService::class)->set('crm', 'reassignment_max_attempts', '1');

        // A supervisor who can manage rules but is not a lead assignee, so the
        // lead cannot be reassigned and must escalate instead.
        $supervisor = User::factory()->create(['is_available_for_leads' => false]);
        $supervisor->branches()->attach($this->branch->id);
        $supervisor->givePermissionTo('leads.manage_rules');

        $lead = $this->lead($this->user);
        $lead->update(['last_activity_at' => Carbon::now()->subHours(2)]);

        $this->assertSame(1, app(LeadService::class)->reassignStale());
        $this->assertNotNull($lead->refresh()->escalated_at);

        $notification = Notification::query()->where('user_id', $supervisor->id)->sole();
        $this->assertSame('lead.escalated', $notification->type);
        $this->assertSame($lead->public_id, $notification->data['lead_id']);
        $this->assertSame(1, $notification->data['attempts']);
        $this->assertSame(0, Notification::query()->where('user_id', $this->user->id)->count());

        $this->travelBack();
    }

    public function test_invoice_approval_notifies_the_creator_but_not_the_approver(): void
    {
        $this->user->givePermissionTo(['invoices.view', 'invoices.create']);

        $approver = User::factory()->create();
        $approver->branches()->attach($this->branch->id);
        $approver->givePermissionTo(['invoices.view', 'invoices.approve']);

        $this->actAs($this->user);
        $id = $this->withHeaders($this->headers())
            ->postJson('/api/v1/invoices', $this->customerInvoicePayload(['submit' => true]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data.id');

        $this->actAs($approver);
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/invoices/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $notification = Notification::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame('invoice.approved', $notification->type);
        $this->assertSame($id, $notification->data['invoice_id']);
        $this->assertSame(0, Notification::query()->where('user_id', $approver->id)->count());
    }

    public function test_expense_approval_and_rejection_notify_the_requester(): void
    {
        $this->user->givePermissionTo(['expenses.view', 'expenses.create', 'accounts.post']);

        $checker = User::factory()->create();
        $checker->branches()->attach($this->branch->id);
        $checker->givePermissionTo(['expenses.view', 'expenses.approve', 'accounts.post', 'journal.reverse']);

        $this->actAs($this->user);
        $approvedId = $this->withHeaders($this->headers())
            ->postJson('/api/v1/expenses', $this->expensePayload(['submit' => true]))
            ->assertCreated()
            ->json('data.id');
        $rejectedId = $this->withHeaders($this->headers())
            ->postJson('/api/v1/expenses', $this->expensePayload(['submit' => true, 'description' => 'Second rent']))
            ->assertCreated()
            ->json('data.id');

        $this->actAs($checker);
        $this->withHeaders($this->headers())->postJson("/api/v1/expenses/{$approvedId}/approve")->assertOk();
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/expenses/{$rejectedId}/reject", ['reason' => 'No receipt'])
            ->assertOk();

        $notifications = Notification::query()->where('user_id', $this->user->id)->orderBy('id')->get();

        $this->assertCount(2, $notifications);
        $this->assertSame(['expense.approved', 'expense.rejected'], $notifications->pluck('type')->all());
        $this->assertSame($approvedId, $notifications[0]->data['expense_id']);
        $this->assertSame($rejectedId, $notifications[1]->data['expense_id']);
        $this->assertSame(0, Notification::query()->where('user_id', $checker->id)->count());
    }

    public function test_a_self_approved_expense_does_not_notify_the_same_person(): void
    {
        app(SettingService::class)->set('expenses', 'segregation', 'false');
        $this->user->givePermissionTo(['expenses.view', 'expenses.create', 'expenses.approve', 'accounts.post', 'journal.reverse']);

        $this->actAs($this->user);
        $id = $this->withHeaders($this->headers())
            ->postJson('/api/v1/expenses', $this->expensePayload(['submit' => true]))
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/expenses/{$id}/approve")
            ->assertOk();

        $this->assertSame(0, Notification::query()->count());
    }
}
