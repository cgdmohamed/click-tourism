<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class OpeningBalanceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'opening_balances.view', 'opening_balances.manage', 'opening_balances.post', 'opening_balances.reverse',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);
        Sanctum::actingAs($this->user);
    }

    private function h(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    /** @return array<int, array<string, mixed>> */
    private function egpLines(): array
    {
        return [
            ['account_id' => '1110', 'debit_minor' => 10000000],
            ['account_id' => '1100', 'debit_minor' => 5000000, 'party_type' => 'customer', 'party_id' => $this->customer()->public_id],
            ['account_id' => '2100', 'credit_minor' => 3000000, 'party_type' => 'supplier', 'party_id' => $this->supplier()->public_id],
            ['account_id' => '3200', 'credit_minor' => 12000000],
        ];
    }

    private function create(?array $lines = null, array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->h())->postJson('/api/v1/opening-balances', $overrides + [
            'as_of_date' => now()->startOfMonth()->toDateString(),
            'memo' => 'Cutover',
            'lines' => $lines ?? $this->egpLines(),
        ]);
    }

    private function postBatch(string $id, array $headers = []): TestResponse
    {
        return $this->withHeaders($this->h(null, $headers))->postJson("/api/v1/opening-balances/{$id}/post");
    }

    private function balance(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    public function test_a_balanced_batch_is_saved_as_a_draft_then_posted_once(): void
    {
        $id = $this->create()->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(4, 'data.lines')
            ->json('data.id');

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'opening_balance')->count());

        $this->postBatch($id)->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->count());
        $this->assertSame(10000000, $this->balance('1110'));
        $this->assertSame(5000000, $this->balance('1100'));
        $this->assertSame(-3000000, $this->balance('2100'));
        $this->assertSame(-12000000, $this->balance('3200'));

        $entry = JournalEntry::query()->where('source_type', 'opening_balance')->firstOrFail();
        $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));
        $this->assertSame(
            $this->customer()->id,
            (int) DB::table('journal_lines')->where('account_id', $this->account('1100')->id)->value('party_id'),
        );

        $this->postBatch($id)->assertStatus(422);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->count());
    }

    public function test_posting_is_idempotent_over_http(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');

        $first = $this->postBatch($id, ['Idempotency-Key' => 'ob-1'])->assertOk();
        $second = $this->postBatch($id, ['Idempotency-Key' => 'ob-1'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->count());
    }

    public function test_invalid_batches_are_rejected(): void
    {
        $unbalanced = $this->egpLines();
        $unbalanced[3]['credit_minor'] = 11999999;
        $this->create($unbalanced)->assertStatus(422);

        $this->create([['account_id' => '1110', 'debit_minor' => 100]])->assertStatus(422);

        $both = $this->egpLines();
        $both[0]['credit_minor'] = 5;
        $this->create($both)->assertStatus(422);

        $zero = $this->egpLines();
        $zero[0]['debit_minor'] = 0;
        $this->create($zero)->assertStatus(422);

        $missingParty = $this->egpLines();
        unset($missingParty[1]['party_type'], $missingParty[1]['party_id']);
        $this->create($missingParty)->assertStatus(422);

        $wrongParty = $this->egpLines();
        $wrongParty[1]['party_type'] = 'supplier';
        $wrongParty[1]['party_id'] = $this->supplier()->public_id;
        $this->create($wrongParty)->assertStatus(422);

        $unknownParty = $this->egpLines();
        $unknownParty[2]['party_id'] = 'missing';
        $this->create($unknownParty)->assertStatus(422);

        $this->account('1110')->update(['is_active' => false]);
        $this->create()->assertStatus(422);

        $this->assertSame(0, OpeningBalanceBatch::query()->count());
    }

    public function test_each_currency_must_balance_on_its_own_and_posts_as_its_own_entry(): void
    {
        Account::query()->create([
            'code' => '1130', 'name_ar' => 'دولار', 'name_en' => 'USD cash', 'type' => 'asset',
            'is_postable' => true, 'is_active' => true,
        ]);

        $lines = array_merge($this->egpLines(), [
            ['account_id' => '1130', 'currency' => 'USD', 'fx_rate' => '50', 'debit_minor' => 100000],
            ['account_id' => '3200', 'currency' => 'USD', 'fx_rate' => '50', 'credit_minor' => 100000],
        ]);

        $crossCurrencyOffset = array_merge($this->egpLines(), [
            ['account_id' => '1130', 'currency' => 'USD', 'fx_rate' => '50', 'debit_minor' => 100000],
        ]);
        $crossCurrencyOffset[3]['credit_minor'] += 5000000;
        $this->create($crossCurrencyOffset)->assertStatus(422);

        $id = $this->create($lines)->assertCreated()->json('data.id');
        $this->postBatch($id)->assertOk();

        $this->assertSame(2, JournalEntry::query()->where('source_type', 'opening_balance')->count());
        $this->assertSame(5000000, $this->balance('1130'));
        $this->assertSame(-12000000 - 5000000, $this->balance('3200'));
    }

    public function test_only_one_batch_may_be_posted_per_branch_and_date_until_it_is_reversed(): void
    {
        $first = $this->create()->assertCreated()->json('data.id');
        $second = $this->create()->assertCreated()->json('data.id');

        $this->postBatch($first)->assertOk();
        $this->postBatch($second)->assertStatus(422)->assertJsonPath('code', 'domain_error');

        $this->withHeaders($this->h())->postJson("/api/v1/opening-balances/{$first}/reverse", [])->assertStatus(422);
        $this->withHeaders($this->h())->postJson("/api/v1/opening-balances/{$first}/reverse", ['reason' => 'Wrong figures'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertSame(0, $this->balance('1110'));
        $this->assertSame(0, $this->balance('3200'));

        $this->postBatch($first)->assertStatus(422);
        $this->postBatch($second)->assertOk();
        $this->assertSame(10000000, $this->balance('1110'));
    }

    public function test_a_closed_period_rejects_posting_and_the_batch_stays_a_draft(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->postBatch($id)->assertStatus(422);

        $this->assertSame('draft', OpeningBalanceBatch::query()->firstOrFail()->status);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'opening_balance')->count());
    }

    public function test_drafts_can_be_discarded_but_posted_batches_cannot(): void
    {
        $draft = $this->create()->assertCreated()->json('data.id');
        $this->withHeaders($this->h())->deleteJson("/api/v1/opening-balances/{$draft}")->assertNoContent();
        $this->assertSame(0, OpeningBalanceBatch::query()->count());

        $posted = $this->create()->assertCreated()->json('data.id');
        $this->postBatch($posted)->assertOk();
        $this->withHeaders($this->h())->deleteJson("/api/v1/opening-balances/{$posted}")->assertStatus(422);
        $this->assertSame(1, OpeningBalanceBatch::query()->count());
    }

    public function test_the_reconciliation_report_ties_the_batch_to_the_ledger(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');

        $draft = $this->withHeaders($this->h())->getJson("/api/v1/opening-balances/{$id}/reconciliation")->assertOk();
        $this->assertTrue($draft->json('data.reconciled'));
        $this->assertNull($draft->json('data.accounts.0.matches_ledger'));

        $this->postBatch($id)->assertOk();

        $report = $this->withHeaders($this->h())->getJson("/api/v1/opening-balances/{$id}/reconciliation")->assertOk();
        $this->assertTrue($report->json('data.reconciled'));
        $this->assertTrue($report->json('data.currency_totals.0.balanced'));
        $this->assertSame(5000000, $report->json('data.control_account_detail.0.party_detail_minor'));
        $this->assertSame(-3000000, $report->json('data.control_account_detail.1.party_detail_minor'));
        $this->assertTrue(collect($report->json('data.accounts'))->every(fn ($row) => $row['matches_ledger'] === true));
    }

    public function test_branch_scope_and_permissions_are_enforced(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');

        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $stranger = User::factory()->create();
        $stranger->branches()->attach($other->id);
        $stranger->givePermissionTo(self::PERMISSIONS);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);
        $this->withHeaders($this->h($other))->getJson("/api/v1/opening-balances/{$id}")->assertStatus(403);
        $this->withHeaders($this->h($other))->postJson("/api/v1/opening-balances/{$id}/post")->assertStatus(403);
        $this->withHeaders($this->h($other))->deleteJson("/api/v1/opening-balances/{$id}")->assertStatus(403);
        $this->assertCount(0, $this->withHeaders($this->h($other))->getJson('/api/v1/opening-balances')->json('data'));

        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo('opening_balances.view');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);
        $this->withHeaders($this->h())->getJson("/api/v1/opening-balances/{$id}")->assertOk();
        $this->withHeaders($this->h())->postJson('/api/v1/opening-balances', [])->assertStatus(403);
        $this->postBatch($id)->assertStatus(403);
        $this->withHeaders($this->h())->postJson("/api/v1/opening-balances/{$id}/reverse", ['reason' => 'x'])->assertStatus(403);
    }
}
