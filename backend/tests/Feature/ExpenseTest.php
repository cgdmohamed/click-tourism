<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    private CashAccount $cash;

    private ExpenseCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(TreasurySeeder::class);

        foreach (['expenses.view', 'expenses.create', 'expenses.approve', 'expense_categories.manage'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->maker = $this->user;
        $this->maker->givePermissionTo(['expenses.view', 'expenses.create']);

        $this->checker = User::factory()->create();
        $this->checker->branches()->attach($this->branch->id);
        $this->checker->givePermissionTo(['expenses.view', 'expenses.approve']);

        $this->cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->rent = ExpenseCategory::query()->where('code', 'RENT')->firstOrFail();

        $this->actAs($this->maker);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function h(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'category_id' => $this->rent->public_id,
            'expense_date' => now()->toDateString(),
            'amount_minor' => 115000,
            'tax_minor' => 15000,
            'currency' => 'EGP',
            'description' => 'Office rent',
            'cash_account_id' => $this->cash->public_id,
        ];
    }

    private function create(array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->h())->postJson('/api/v1/expenses', $this->payload($overrides));
    }

    private function approveAsChecker(string $id, array $headers = []): TestResponse
    {
        $this->actAs($this->checker);

        return $this->withHeaders($this->h(null, $headers))->postJson("/api/v1/expenses/{$id}/approve");
    }

    private function balance(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $this->account($code)->id)
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    public function test_an_expense_moves_through_submit_and_approval_and_posts_once(): void
    {
        $id = $this->create()->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.net_minor', 100000)
            ->json('data.id');

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());

        $this->postJson("/api/v1/expenses/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());

        $this->approveAsChecker($id)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'expense')->count());
        $this->assertSame(100000, $this->balance('6100'));
        $this->assertSame(15000, $this->balance('1160'));
        $this->assertSame(-115000, $this->balance('1110'));

        $entry = JournalEntry::query()->where('source_type', 'expense')->firstOrFail();
        $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));

        $this->withHeaders($this->h())->postJson("/api/v1/expenses/{$id}/approve")->assertStatus(422);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    public function test_approval_is_idempotent_over_http(): void
    {
        $id = $this->create(['submit' => true])->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');

        $first = $this->approveAsChecker($id, ['Idempotency-Key' => 'approve-1'])->assertOk();
        $second = $this->approveAsChecker($id, ['Idempotency-Key' => 'approve-1'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    public function test_the_creator_cannot_approve_their_own_expense_unless_segregation_is_off(): void
    {
        $this->maker->givePermissionTo('expenses.approve');
        $id = $this->create(['submit' => true])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/expenses/{$id}/approve")->assertStatus(422);
        $this->assertSame('submitted', Expense::query()->firstOrFail()->status);

        app(SettingService::class)->set('expenses', 'segregation', 'false');

        $this->postJson("/api/v1/expenses/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_a_rejected_expense_posts_nothing_and_cannot_be_approved(): void
    {
        $id = $this->create(['submit' => true])->assertCreated()->json('data.id');

        $this->actAs($this->checker);
        $this->postJson("/api/v1/expenses/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/expenses/{$id}/reject", ['reason' => 'No receipt'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'No receipt');

        $this->postJson("/api/v1/expenses/{$id}/approve")->assertStatus(422);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    public function test_an_expense_can_be_booked_on_account_to_a_supplier(): void
    {
        $supplier = $this->supplier();

        $id = $this->create([
            'cash_account_id' => null,
            'payee_type' => 'supplier',
            'payee_id' => $supplier->public_id,
            'submit' => true,
        ])->assertCreated()->assertJsonPath('data.payee_name', 'Test Supplier')->json('data.id');

        $this->approveAsChecker($id)->assertOk();

        $this->assertSame(-115000, $this->balance('2100'));
        $line = DB::table('journal_lines')->where('account_id', $this->account('2100')->id)->first();
        $this->assertSame('supplier', $line->party_type);
        $this->assertSame($supplier->id, (int) $line->party_id);
    }

    public function test_foreign_currency_expenses_post_with_the_fx_snapshot(): void
    {
        $account = Account::query()->create([
            'code' => '1130', 'name_ar' => 'دولار', 'name_en' => 'USD cash', 'type' => 'asset',
            'is_postable' => true, 'is_active' => true,
        ]);
        $usd = CashAccount::query()->create([
            'account_id' => $account->id, 'kind' => 'cash', 'name_ar' => 'دولار', 'name_en' => 'USD cash',
            'currency_code' => 'USD', 'is_active' => true,
        ]);

        $id = $this->create([
            'currency' => 'USD',
            'fx_rate' => '50',
            'amount_minor' => 10000,
            'tax_minor' => 0,
            'cash_account_id' => $usd->public_id,
            'submit' => true,
        ])->assertCreated()->assertJsonPath('data.fx_rate', '50.0000000000')->json('data.id');

        $this->approveAsChecker($id)->assertOk();

        $this->assertSame(500000, $this->balance('6100'));
        $this->assertSame(-500000, $this->balance('1130'));
    }

    public function test_validation_rules_are_enforced(): void
    {
        $this->create(['tax_minor' => 115000])->assertStatus(422);
        $this->create(['tax_minor' => -1])->assertStatus(422);
        $this->create(['amount_minor' => 0])->assertStatus(422);
        $this->create(['cash_account_id' => null])->assertStatus(422);
        $this->create(['currency' => 'USD'])->assertStatus(422);
        $this->create(['payee_type' => 'supplier', 'payee_id' => 'missing'])->assertStatus(422);
        $this->create(['payee_type' => 'employee'])->assertStatus(422);
        $this->create(['category_id' => 'missing'])->assertStatus(422);
        $this->assertSame(0, Expense::query()->count());

        $this->rent->update(['is_active' => false]);
        $this->create()->assertStatus(422);
    }

    public function test_a_closed_period_blocks_approval_and_leaves_the_expense_submitted(): void
    {
        $id = $this->create(['submit' => true])->assertCreated()->json('data.id');
        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->approveAsChecker($id)->assertStatus(422);

        $expense = Expense::query()->firstOrFail();
        $this->assertSame('submitted', $expense->status);
        $this->assertNull($expense->journal_entry_id);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    public function test_only_drafts_are_editable_and_unposted_expenses_can_be_cancelled(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/expenses/{$id}", ['amount_minor' => 200000, 'tax_minor' => 0])
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 200000);

        $this->postJson("/api/v1/expenses/{$id}/submit")->assertOk();
        $this->patchJson("/api/v1/expenses/{$id}", ['amount_minor' => 1])->assertStatus(422);

        $this->postJson("/api/v1/expenses/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actAs($this->checker);
        $this->postJson("/api/v1/expenses/{$id}/approve")->assertStatus(422);
    }

    public function test_an_approved_expense_is_reversed_not_deleted(): void
    {
        $id = $this->create(['submit' => true])->assertCreated()->json('data.id');
        $this->approveAsChecker($id)->assertOk();

        $this->postJson("/api/v1/expenses/{$id}/cancel")->assertStatus(403);
        $this->postJson("/api/v1/expenses/{$id}/reverse", [])->assertStatus(422);
        $this->postJson("/api/v1/expenses/{$id}/reverse", ['reason' => 'Duplicate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertSame(0, $this->balance('6100'));
        $this->assertSame(0, $this->balance('1110'));
        $this->postJson("/api/v1/expenses/{$id}/reverse", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_branch_scope_and_permissions_are_enforced(): void
    {
        $id = $this->create(['submit' => true])->assertCreated()->json('data.id');

        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $stranger = User::factory()->create();
        $stranger->branches()->attach($other->id);
        $stranger->givePermissionTo(['expenses.view', 'expenses.approve', 'expenses.create']);

        $this->actAs($stranger);
        $this->withHeaders($this->h($other))->getJson("/api/v1/expenses/{$id}")->assertStatus(403);
        $this->withHeaders($this->h($other))->postJson("/api/v1/expenses/{$id}/approve")->assertStatus(403);
        $this->withHeaders($this->h($other))->postJson("/api/v1/expenses/{$id}/reject", ['reason' => 'x'])->assertStatus(403);
        $this->assertSame(0, count($this->withHeaders($this->h($other))->getJson('/api/v1/expenses')->json('data')));

        $this->actAs($this->maker);
        $this->postJson("/api/v1/expenses/{$id}/approve")->assertStatus(403);

        $nobody = User::factory()->create();
        $nobody->branches()->attach($this->branch->id);
        $this->actAs($nobody);
        $this->withHeaders($this->h())->getJson('/api/v1/expenses')->assertStatus(403);
        $this->withHeaders($this->h())->postJson('/api/v1/expenses', $this->payload())->assertStatus(403);
    }

    public function test_categories_must_map_to_an_active_postable_expense_account(): void
    {
        $this->actAs($this->checker);
        $this->checker->givePermissionTo('expense_categories.manage');

        $this->postJson('/api/v1/expense-categories', [
            'code' => 'ASSET', 'name_ar' => 'x', 'name_en' => 'x', 'account_id' => $this->account('1110')->public_id,
        ])->assertStatus(422);

        $this->postJson('/api/v1/expense-categories', [
            'code' => 'rent', 'name_ar' => 'x', 'name_en' => 'x', 'account_id' => $this->account('6100')->public_id,
        ])->assertStatus(422);

        $this->postJson('/api/v1/expense-categories', [
            'code' => 'fuel', 'name_ar' => 'وقود', 'name_en' => 'Fuel', 'account_id' => $this->account('6600')->public_id,
        ])->assertCreated()->assertJsonPath('data.code', 'FUEL');

        $this->getJson('/api/v1/expense-categories')->assertOk()->assertJsonCount(7, 'data');
    }
}
