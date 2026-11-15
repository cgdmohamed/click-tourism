<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Money\Services\FxService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Treasury\Models\CashAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class AccountantDashboardTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Permission::query()->firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');
        Sanctum::actingAs($this->user);
    }

    public function test_dashboard_has_separate_cash_and_accrual_series(): void
    {
        $response = $this->withHeader('X-Branch-Id', $this->branch->public_id)
            ->getJson('/api/v1/dashboard/summary?period=thisMonth')->assertOk();

        $response->assertJsonStructure(['data' => [
            'currency', 'period_from', 'period_to', 'cash_balance_minor',
            'cash_balance_mixed_currency', 'monthly_cash_flow',
            'monthly_profit_loss', 'aging_buckets', 'attention_items',
        ]]);
        $this->assertCount(6, $response->json('data.monthly_cash_flow'));
        $this->assertCount(6, $response->json('data.monthly_profit_loss'));
        $this->assertCount(5, $response->json('data.aging_buckets'));
        $this->assertSame(now()->startOfMonth()->toDateString(), $response->json('data.period_from'));
    }

    public function test_dashboard_rejects_unsupported_cross_currency_aggregation(): void
    {
        $this->withHeader('X-Branch-Id', $this->branch->public_id)
            ->getJson('/api/v1/dashboard/summary?currency=USD')
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    }

    public function test_dashboard_custom_date_range_is_enforced(): void
    {
        $from = now()->subDays(4)->toDateString();
        $to = now()->subDays(2)->toDateString();

        $this->withHeader('X-Branch-Id', $this->branch->public_id)
            ->getJson('/api/v1/dashboard/summary?'.http_build_query([
                'period' => 'customRange', 'from' => $from, 'to' => $to,
            ]))->assertOk()
            ->assertJsonPath('data.period_from', $from)
            ->assertJsonPath('data.period_to', $to);

        $this->withHeader('X-Branch-Id', $this->branch->public_id)
            ->getJson('/api/v1/dashboard/summary?period=customRange')
            ->assertStatus(422);
    }

    private function foreignCashAccount(string $code, string $currency): CashAccount
    {
        $account = Account::query()->create([
            'code' => $code, 'name_ar' => 'حساب '.$currency, 'name_en' => $currency.' account', 'type' => 'asset',
            'classification' => Account::defaultClassification('asset', $code),
            'is_postable' => true, 'is_active' => true, 'currency_code' => $currency,
        ]);

        return CashAccount::query()->create([
            'account_id' => $account->id, 'branch_id' => $this->branch->id, 'kind' => 'bank',
            'name_ar' => 'حساب '.$currency, 'name_en' => $currency.' account', 'currency_code' => $currency, 'is_active' => true,
        ]);
    }

    private function egpCashBox(): void
    {
        CashAccount::query()->create([
            'account_id' => $this->account('1110')->id, 'branch_id' => $this->branch->id, 'kind' => 'cash',
            'name_ar' => 'الخزينة', 'name_en' => 'Cash box', 'currency_code' => 'EGP', 'is_active' => true,
        ]);
    }

    private function receive(string $ledgerCode, int $minor, string $currency, string $rate): void
    {
        app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id, 'customer_id' => $this->customer()->public_id,
            'cash_account_id' => $this->account($ledgerCode)->public_id, 'amount_minor' => $minor, 'currency' => $currency,
            'fx_rate' => $rate, 'method' => 'bank_transfer', 'paid_at' => now()->toIso8601String(),
        ], $this->user->id);
    }

    private function summary()
    {
        return $this->withHeader('X-Branch-Id', $this->branch->public_id)->getJson('/api/v1/dashboard/summary?period=thisMonth')->assertOk();
    }

    public function test_cash_in_other_currencies_is_totalled_in_the_base_currency_at_each_latest_rate(): void
    {
        $this->egpCashBox();
        $this->receive('1110', 500000, 'EGP', '1');
        $this->foreignCashAccount('1125', 'USD');
        $this->receive('1125', 100000, 'USD', '48.5');
        app(FxService::class)->upsertRate('USD', '50.0000000000', now()->toDateString());

        $data = $this->summary()->json('data');

        // 1,000.00 USD at today's recorded 50 is 50,000.00 EGP, plus the 5,000.00 in the cash box.
        $this->assertSame(5_500_000, $data['cash_balance_minor']);
        $this->assertTrue($data['cash_balance_approximate']);
        $this->assertSame(now()->toDateString(), $data['cash_rates_as_of']);
        $this->assertSame([], $data['cash_rates_missing']);
        $usd = collect($data['cash_accounts'])->firstWhere('currency', 'USD');
        $this->assertSame(100000, $usd['balance_minor']);
        $this->assertSame(5_000_000, $usd['approx_base_minor']);
        $this->assertEquals(50, (float) $usd['rate']);
        $this->assertSame(4_850_000, $usd['base_balance_minor'], 'the booked base value is kept beside the approximation');
    }

    public function test_a_currency_with_no_rate_falls_back_to_its_booked_value_and_is_listed(): void
    {
        $this->foreignCashAccount('1126', 'EUR');
        $this->receive('1126', 20000, 'EUR', '52');

        $data = $this->summary()->json('data');

        $this->assertSame(1_040_000, $data['cash_balance_minor']);
        $this->assertSame(['EUR'], $data['cash_rates_missing']);
        $this->assertTrue(collect($data['cash_accounts'])->firstWhere('currency', 'EUR')['rate_missing']);
    }

    public function test_base_currency_postings_on_a_foreign_account_are_kept_in_the_total_and_flagged(): void
    {
        $this->foreignCashAccount('1125', 'USD');
        $this->receive('1125', 100000, 'USD', '48.5');
        // Unrestricted ledger account (currency_code null), as register() permits: a base-currency posting lands on it.
        $this->account('1125')->update(['currency_code' => null]);
        $this->receive('1125', 20000, 'EGP', '1');
        app(FxService::class)->upsertRate('USD', '50.0000000000', now()->toDateString());

        $data = $this->summary()->json('data');

        $usd = collect($data['cash_accounts'])->firstWhere('currency', 'USD');
        $this->assertSame(100000, $usd['balance_minor']);
        $this->assertSame(20000, $usd['other_currency_base_minor']);
        // 1,000.00 USD at 50 plus the 200.00 EGP already booked on the same account.
        $this->assertSame(5_020_000, $usd['approx_base_minor']);
        $this->assertSame(5_020_000, $data['cash_balance_minor']);
    }

    public function test_only_base_currency_cash_is_not_approximate(): void
    {
        $this->egpCashBox();
        $this->receive('1110', 250000, 'EGP', '1');

        $data = $this->summary()->json('data');

        $this->assertSame(250000, $data['cash_balance_minor']);
        $this->assertFalse($data['cash_balance_approximate']);
        $this->assertNull($data['cash_rates_as_of']);
    }
}
