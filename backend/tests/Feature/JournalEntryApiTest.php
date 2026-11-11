<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
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

        foreach (['journal.view', 'journal.create', 'accounts.post'] as $permission) {
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

    private function makeDraft(string $memo): string
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
}
