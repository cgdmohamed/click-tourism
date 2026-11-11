<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class JournalEntryApiTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (['journal.view', 'journal.create', 'accounts.post', 'journal.post_own_drafts', 'journal.delete_drafts'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['journal.view', 'journal.create']);

        // Separate user for posting (maker-checker: creator != poster).
        $this->poster = User::factory()->create();
        $this->poster->branches()->attach($this->branch->id);
        $this->poster->givePermissionTo(['accounts.post', 'journal.view', 'journal.create']);

        Sanctum::actingAs($this->user);
    }

    private function payload(bool $post): array
    {
        return [
            'entry_date' => now()->toDateString(),
            'branch_id' => $this->branch->public_id,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'fx_rate_date' => now()->toDateString(),
            'fx_rate_source' => 'manual',
            'memo' => 'Manual journal',
            'post' => $post,
            'lines' => [
                [
                    'account_id' => $this->account('1110')->public_id,
                    'debit_minor' => 12345,
                    'credit_minor' => 0,
                ],
                [
                    'account_id' => $this->account('4100')->public_id,
                    'debit_minor' => 0,
                    'credit_minor' => 12345,
                ],
            ],
        ];
    }

    private function headers(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    public function test_a_manual_entry_can_be_saved_as_a_draft_and_posted_later(): void
    {
        $created = $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $this->payload(false))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $id = $created->json('data.id');

        $this->assertSame(0, JournalEntry::query()->where('status', 'posted')->count());

        Sanctum::actingAs($this->poster);
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/journal-entries/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->where('status', 'posted')->count());
    }

    public function test_a_manual_entry_is_posted_immediately_by_default(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $this->payload(true))
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->where('status', 'posted')->count());
    }

    public function test_an_unbalanced_manual_entry_is_rejected(): void
    {
        $payload = $this->payload(false);
        $payload['lines'][1]['credit_minor'] = 12000;

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422);
    }

    private function makeDraft(string $memo = 'Draft'): string
    {
        $payload = $this->payload(false);
        $payload['memo'] = $memo;

        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    public function test_index_search_matches_memo_and_number(): void
    {
        $this->makeDraft('Office rent October');
        $this->makeDraft('Fuel purchase');

        $byMemo = $this->withHeaders($this->headers())
            ->getJson('/api/v1/journal-entries?search=rent')
            ->assertOk();
        $this->assertCount(1, $byMemo->json('data'));
        $this->assertSame('Office rent October', $byMemo->json('data.0.memo'));

        $number = $byMemo->json('data.0.number');
        $byNumber = $this->withHeaders($this->headers())
            ->getJson('/api/v1/journal-entries?search='.urlencode($number))
            ->assertOk();
        $this->assertCount(1, $byNumber->json('data'));

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/journal-entries?search=nomatch')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // LIKE wildcards are treated literally.
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/journal-entries?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_can_hide_reversal_entries(): void
    {
        $created = $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $this->payload(true))
            ->assertCreated();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries/'.$created->json('data.id').'/reverse', ['reason' => 'mistake'])
            ->assertOk();

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/journal-entries')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/journal-entries?include_reversals=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_line_party_public_id_is_resolved_to_the_internal_id(): void
    {
        $customer = $this->customer();
        $payload = $this->payload(false);
        $payload['lines'][0]['account_id'] = $this->account('1100')->public_id;
        $payload['lines'][0]['party_type'] = 'customer';
        $payload['lines'][0]['party_id'] = $customer->public_id;

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('journal_lines', [
            'party_type' => 'customer',
            'party_id' => $customer->id,
        ]);
    }

    public function test_an_unknown_party_is_rejected(): void
    {
        $payload = $this->payload(false);
        $payload['lines'][0]['party_type'] = 'supplier';
        $payload['lines'][0]['party_id'] = 'does-not-exist';

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'party_not_found');

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_a_customer_public_id_is_not_accepted_as_a_supplier(): void
    {
        $payload = $this->payload(false);
        $payload['lines'][0]['party_type'] = 'supplier';
        $payload['lines'][0]['party_id'] = $this->customer()->public_id;

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422);
    }

    public function test_party_type_and_id_must_be_sent_together(): void
    {
        $payload = $this->payload(false);
        $payload['lines'][0]['party_type'] = 'customer';

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422);
    }

    public function test_manual_posting_is_blocked_until_the_policy_is_approved(): void
    {
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $this->payload(true))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Accounting posting is disabled until the accounting policy is approved by an accountant. This applies to automatic and manual journal entries.');
    }

    public function test_accounts_expose_the_control_party_type(): void
    {
        Permission::query()->firstOrCreate(['name' => 'accounts.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo('accounts.view');

        $accounts = collect($this->withHeaders($this->headers())->getJson('/api/v1/accounts')->json('data'));

        $this->assertSame('customer', $accounts->firstWhere('code', '1100')['control_party_type']);
        $this->assertSame('supplier', $accounts->firstWhere('code', '2100')['control_party_type']);
        $this->assertNull($accounts->firstWhere('code', '4100')['control_party_type']);
    }

    public function test_plain_accountant_cannot_post_own_draft(): void
    {
        $this->user->givePermissionTo('accounts.post');
        $id = $this->makeDraft();

        $this->withHeaders($this->headers())->postJson("/api/v1/journal-entries/{$id}/post")->assertStatus(422);
        $this->assertSame('draft', JournalEntry::query()->first()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'journal.posted_own_draft')->count());
    }

    public function test_senior_accountant_can_post_own_draft_with_audit_event(): void
    {
        $this->user->givePermissionTo(['accounts.post', 'journal.post_own_drafts']);
        $id = $this->makeDraft();

        $this->withHeaders($this->headers())->postJson("/api/v1/journal-entries/{$id}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $event = AuditEvent::query()->where('action', 'journal.posted_own_draft')->firstOrFail();
        $this->assertSame($this->user->id, $event->actor_id);
        $this->assertSame('draft', $event->before['status']);
        $this->assertSame('posted', $event->after['status']);
    }

    public function test_senior_accountant_can_post_another_users_draft_without_override_event(): void
    {
        $id = $this->makeDraft();
        $this->poster->givePermissionTo('journal.post_own_drafts');
        Sanctum::actingAs($this->poster);

        $this->withHeaders($this->headers())->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();
        $this->assertSame(0, AuditEvent::query()->where('action', 'journal.posted_own_draft')->count());
    }

    public function test_creator_can_delete_own_draft_and_it_is_audited(): void
    {
        $id = $this->makeDraft();
        $entry = JournalEntry::query()->firstOrFail();

        $this->withHeaders($this->headers())->deleteJson("/api/v1/journal-entries/{$id}")->assertOk();

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, \DB::table('journal_lines')->count());
        $event = AuditEvent::query()->where('action', 'journal.draft_deleted')->firstOrFail();
        $this->assertSame($entry->number, $event->before['number']);
        $this->assertSame(12345, $event->before['debit_minor']);
        $this->assertSame(12345, $event->before['credit_minor']);
    }

    public function test_other_user_needs_delete_permission_to_delete_a_draft(): void
    {
        $id = $this->makeDraft();

        Sanctum::actingAs($this->poster);
        $this->withHeaders($this->headers())->deleteJson("/api/v1/journal-entries/{$id}")->assertForbidden();
        $this->assertSame(1, JournalEntry::query()->count());

        $this->poster->givePermissionTo('journal.delete_drafts');
        $this->withHeaders($this->headers())->deleteJson("/api/v1/journal-entries/{$id}")->assertOk();
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_user_without_any_journal_permission_cannot_delete(): void
    {
        $id = $this->makeDraft();
        $other = User::factory()->create();
        $other->branches()->attach($this->branch->id);
        Sanctum::actingAs($other);

        $this->withHeaders($this->headers())->deleteJson("/api/v1/journal-entries/{$id}")->assertForbidden();
    }

    public function test_draft_deletion_respects_branch_scope(): void
    {
        $id = $this->makeDraft();
        $this->poster->givePermissionTo('journal.delete_drafts');
        $this->poster->branches()->detach();
        $other = Branch::query()->create(['code' => 'OTH', 'name_ar' => 'آخر', 'name_en' => 'Other', 'is_active' => true]);
        $this->poster->branches()->attach($other->id);
        Sanctum::actingAs($this->poster);

        $this->withHeaders(['X-Branch-Id' => $other->public_id])->deleteJson("/api/v1/journal-entries/{$id}")->assertForbidden();
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_posted_entries_cannot_be_deleted_and_stay_immutable(): void
    {
        $this->user->givePermissionTo('journal.delete_drafts');
        $id = $this->withHeaders($this->headers())
            ->postJson('/api/v1/journal-entries', $this->payload(true))->assertCreated()->json('data.id');

        $this->withHeaders($this->headers())->deleteJson("/api/v1/journal-entries/{$id}")->assertStatus(422);

        $entry = JournalEntry::query()->with('lines')->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertSame(2, $entry->lines->count());

        try {
            $entry->delete();
            $this->fail('Posted entry delete must throw.');
        } catch (\DomainException) {
        }
        try {
            $entry->lines->first()->delete();
            $this->fail('Line delete must throw.');
        } catch (\DomainException) {
        }
        $this->assertSame(2, \DB::table('journal_lines')->count());
    }

    public function test_seeder_grants_override_only_to_senior_accountant_and_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = fn (string $n) => Role::findByName($n, 'web');

        foreach (['journal.post_own_drafts', 'journal.delete_drafts'] as $p) {
            $this->assertTrue($role('senior_accountant')->hasPermissionTo($p));
            $this->assertTrue($role('admin')->hasPermissionTo($p));
            $this->assertFalse($role('accountant')->hasPermissionTo($p));
        }
        $this->assertTrue($role('senior_accountant')->hasPermissionTo('journal.reverse'));
    }
}
