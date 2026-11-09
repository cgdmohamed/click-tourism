<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
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

        foreach (['journal.view', 'journal.create'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['journal.view', 'journal.create']);

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
}
