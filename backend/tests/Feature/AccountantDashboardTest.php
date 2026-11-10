<?php

namespace Tests\Feature;

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
}
