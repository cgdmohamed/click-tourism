<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class TreasuryTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'accounts.view', 'cash_accounts.manage', 'transfers.view', 'transfers.create',
        'transfers.reverse', 'journal.view', 'journal.create',
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

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function register(string $code, string $currency = 'EGP', string $kind = 'cash', array $extra = []): array
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/cash-accounts', $extra + [
            'kind' => $kind,
            'name_ar' => 'حساب '.$code,
            'name_en' => 'Account '.$code,
            'currency' => $currency,
            'account_code' => $code,
        ])->assertCreated()->json('data');
    }

    private function fund(string $debitCode, int $amountMinor, string $currency = 'EGP', string $fx = '1'): void
    {
        $this->withHeaders($this->headers())->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'currency' => $currency,
            'fx_rate' => $fx,
            'lines' => [
                ['account_id' => $debitCode, 'debit_minor' => $amountMinor, 'credit_minor' => 0],
                ['account_id' => '3200', 'debit_minor' => 0, 'credit_minor' => $amountMinor],
            ],
        ])->assertCreated();
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

    private function transfer(array $cash, array $bank, array $overrides = [], array $headers = []): TestResponse
    {
        return $this->withHeaders($this->headers(null, $headers))->postJson('/api/v1/transfers', $overrides + [
            'from_cash_account_id' => $cash['cash_account_id'],
            'to_cash_account_id' => $bank['cash_account_id'],
            'transfer_date' => now()->toDateString(),
            'amount_minor' => 100000,
        ]);
    }

    public function test_the_cash_account_list_falls_back_to_legacy_accounts_until_one_is_registered(): void
    {
        $legacy = $this->withHeaders($this->headers())->getJson('/api/v1/cash-accounts')->assertOk();
        $codes = array_column($legacy->json('data'), 'code');
        $this->assertContains('1110', $codes);
        $this->assertNotContains('1100', $codes);
        $this->assertFalse($legacy->json('data.0.registered'));
        $this->assertContains($legacy->json('data.0.type'), ['cash', 'bank']);

        $this->register('1110');

        $registered = $this->withHeaders($this->headers())->getJson('/api/v1/cash-accounts')->assertOk();
        $this->assertCount(1, $registered->json('data'));
        $this->assertTrue($registered->json('data.0.registered'));
        $this->assertSame($this->account('1110')->public_id, $registered->json('data.0.id'));
    }

    public function test_registration_validates_the_gl_account_and_rejects_duplicates(): void
    {
        $this->register('1110');

        $this->withHeaders($this->headers())->postJson('/api/v1/cash-accounts', [
            'kind' => 'cash', 'name_ar' => 'x', 'name_en' => 'x', 'currency' => 'EGP', 'account_code' => '1110',
        ])->assertStatus(422);

        $this->withHeaders($this->headers())->postJson('/api/v1/cash-accounts', [
            'kind' => 'cash', 'name_ar' => 'x', 'name_en' => 'x', 'currency' => 'EGP', 'account_code' => '4100',
        ])->assertStatus(422);

        $this->withHeaders($this->headers())->postJson('/api/v1/cash-accounts', [
            'kind' => 'bank', 'name_ar' => 'x', 'name_en' => 'x', 'currency' => 'EGP', 'account_code' => '1199',
            'account_last4' => '12345',
        ])->assertStatus(422);

        $this->withHeaders($this->headers())->postJson('/api/v1/cash-accounts', [
            'kind' => 'wallet', 'name_ar' => 'x', 'name_en' => 'x', 'currency' => 'EGP', 'account_code' => '1199',
        ])->assertStatus(422);
    }

    public function test_a_same_currency_transfer_with_a_fee_posts_one_balanced_entry(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1110', 1000000);

        $response = $this->transfer($cash, $bank, ['amount_minor' => 100000, 'fee_minor' => 500])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.fee_minor', 500);

        $this->assertSame(1000000 - 100500, $this->balance('1110'));
        $this->assertSame(100000, $this->balance('1120'));
        $this->assertSame(500, $this->balance('6500'));

        $entry = JournalEntry::query()->where('source_type', 'transfer')->firstOrFail();
        $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));
        $this->assertStringStartsWith('TR-', $response->json('data.number'));
    }

    public function test_a_cross_currency_transfer_posts_in_base_currency_and_books_the_fx_difference(): void
    {
        $usd = $this->register('1130', 'USD');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1130', 500000);

        // 100.00 USD at 50 = 5,000.00 EGP given up; 5,100.00 EGP received => 100.00 EGP gain.
        $this->transfer($usd, $bank, [
            'amount_minor' => 10000,
            'to_amount_minor' => 510000,
            'from_fx_rate' => '50',
        ])->assertCreated()
            ->assertJsonPath('data.from_currency', 'USD')
            ->assertJsonPath('data.to_currency', 'EGP');

        $this->assertSame(510000, $this->balance('1120'));
        $this->assertSame(500000 - 500000, $this->balance('1130'));
        $this->assertSame(-10000, $this->balance('7900'));

        $entry = JournalEntry::query()->where('source_type', 'transfer')->firstOrFail();
        $this->assertSame('EGP', $entry->currency_code);
        $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));
    }

    public function test_a_cross_currency_transfer_with_a_shortfall_books_an_fx_loss(): void
    {
        $usd = $this->register('1130', 'USD');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1130', 500000);

        $this->transfer($usd, $bank, [
            'amount_minor' => 10000,
            'to_amount_minor' => 490000,
            'from_fx_rate' => '50',
        ])->assertCreated();

        $this->assertSame(10000, $this->balance('6900'));
    }

    public function test_transfer_amount_rules_are_enforced(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $usd = $this->register('1130', 'USD');

        $this->transfer($cash, $cash)->assertStatus(422);
        $this->transfer($cash, $bank, ['amount_minor' => 0])->assertStatus(422);
        $this->transfer($cash, $bank, ['to_amount_minor' => 99999])->assertStatus(422);
        $this->transfer($cash, $usd, ['from_fx_rate' => null])->assertStatus(422);
        $this->assertSame(0, Transfer::query()->count());
    }

    public function test_overdrawing_is_allowed_by_default_and_blocked_when_the_policy_is_on(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1110', 50000);

        $this->transfer($cash, $bank, ['amount_minor' => 80000])->assertCreated();
        $this->assertSame(-30000, $this->balance('1110'));

        app(SettingService::class)->set('treasury', 'block_negative_cash', 'true');

        $this->transfer($cash, $bank, ['amount_minor' => 1])->assertStatus(422);
        $this->assertSame(1, Transfer::query()->count());
    }

    public function test_a_repeated_idempotency_key_creates_one_transfer(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1110', 1000000);

        $headers = ['Idempotency-Key' => 'transfer-key-1'];
        $first = $this->transfer($cash, $bank, [], $headers)->assertCreated();
        $second = $this->transfer($cash, $bank, [], $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Transfer::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'transfer')->count());
    }

    public function test_a_closed_period_rejects_the_transfer_and_leaves_nothing_behind(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1110', 1000000);
        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->transfer($cash, $bank)->assertStatus(422);

        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'transfer')->count());
    }

    public function test_a_transfer_can_be_reversed_exactly_once(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1110', 1000000);

        $id = $this->transfer($cash, $bank, ['fee_minor' => 500])->assertCreated()->json('data.id');

        $this->withHeaders($this->headers())->postJson("/api/v1/transfers/{$id}/reverse", ['reason' => 'Wrong account'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertSame(1000000, $this->balance('1110'));
        $this->assertSame(0, $this->balance('1120'));
        $this->assertSame(0, $this->balance('6500'));

        $this->withHeaders($this->headers())->postJson("/api/v1/transfers/{$id}/reverse", ['reason' => 'Again'])
            ->assertStatus(422);
        $this->withHeaders($this->headers())->postJson("/api/v1/transfers/{$id}/reverse", [])->assertStatus(422);
    }

    public function test_a_cash_account_bound_to_another_branch_cannot_be_used(): void
    {
        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($other->id);

        $restricted = $this->register('1110', 'EGP', 'cash', ['branch_id' => $other->public_id]);
        $bank = $this->register('1120', 'EGP', 'bank');

        $this->transfer($restricted, $bank)->assertStatus(422);
        $this->assertSame(0, Transfer::query()->count());
    }

    public function test_transfers_are_branch_scoped_and_permission_protected(): void
    {
        $cash = $this->register('1110');
        $bank = $this->register('1120', 'EGP', 'bank');
        $this->fund('1110', 1000000);
        $id = $this->transfer($cash, $bank)->assertCreated()->json('data.id');

        $other = Branch::query()->create([
            'code' => 'B3', 'name_ar' => 'ف', 'name_en' => 'Branch 3', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);

        $this->withHeaders($this->headers($other))->getJson('/api/v1/transfers')->assertStatus(403);

        $stranger = User::factory()->create();
        $stranger->branches()->attach($other->id);
        $stranger->givePermissionTo(['transfers.view', 'transfers.reverse']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);

        $this->withHeaders($this->headers($other))->getJson("/api/v1/transfers/{$id}")->assertStatus(403);
        $this->withHeaders($this->headers($other))->postJson("/api/v1/transfers/{$id}/reverse", ['reason' => 'x'])->assertStatus(403);

        $nobody = User::factory()->create();
        $nobody->branches()->attach($this->branch->id);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($nobody);

        $this->withHeaders($this->headers())->getJson('/api/v1/transfers')->assertStatus(403);
        $this->withHeaders($this->headers())->postJson('/api/v1/transfers', [])->assertStatus(403);
        $this->withHeaders($this->headers())->postJson('/api/v1/cash-accounts', [])->assertStatus(403);
    }

    public function test_the_cash_account_balance_is_reported_from_the_ledger(): void
    {
        $this->register('1110');
        $this->fund('1110', 123456);

        $this->withHeaders($this->headers())->getJson('/api/v1/cash-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.balance_minor', 123456);

        $this->assertInstanceOf(Account::class, $this->account('1110'));
        $this->assertSame(1, CashAccount::query()->count());
    }
}
