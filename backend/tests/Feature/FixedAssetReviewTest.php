<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\FixedAssetDepreciationRun;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Services\SettingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Fixed-asset review (2026-11-11): foreign-currency acquisition, no double
 * recognition of cost, disposal to the disposal date, impairment re-spread,
 * rounding policy, run reversal and the GL reconciliation.
 */
class FixedAssetReviewTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'assets.view', 'assets.manage', 'assets.approve',
        'branches.view_all',
        'periods.view', 'periods.manage',
        'journal.view', 'journal.create',
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

    private function headers(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    private function nextPeriod(): string
    {
        return now()->startOfMonth()->addMonthNoOverflow()->format('Y-m');
    }

    private function openNextPeriod(): void
    {
        $start = now()->startOfMonth()->addMonthNoOverflow();
        FiscalPeriod::query()->create([
            'fiscal_year_id' => FiscalYear::query()->firstOrFail()->id,
            'name' => $start->format('Y-m'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function category(array $overrides = []): FixedAssetCategory
    {
        $response = $this->withHeaders($this->headers())->postJson('/api/v1/fixed-asset-categories', $overrides + [
            'code' => 'VEH',
            'name_ar' => 'مركبات',
            'name_en' => 'Vehicles',
            'default_useful_life_months' => 12,
        ])->assertCreated();

        return FixedAssetCategory::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function asset(array $overrides = [], ?FixedAssetCategory $category = null): FixedAsset
    {
        $category ??= FixedAssetCategory::query()->first() ?? $this->category();

        $response = $this->withHeaders($this->headers())->postJson('/api/v1/fixed-assets', $overrides + [
            'category_id' => $category->public_id,
            'description' => 'Delivery van',
            'capitalization_date' => now()->startOfMonth()->toDateString(),
            'in_service_date' => now()->startOfMonth()->toDateString(),
            'acquisition_value_minor' => 1_200_000,
            'residual_value_minor' => 0,
            'useful_life_months' => 12,
            'currency' => 'EGP',
        ])->assertCreated();

        return FixedAsset::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }

    private function capitalize(FixedAsset $asset): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/v1/fixed-assets/{$asset->public_id}/capitalize");
    }

    private function makeRun(string $period): FixedAssetDepreciationRun
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/depreciation-runs', ['period' => $period])->assertCreated();

        return FixedAssetDepreciationRun::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    }

    private function postRun(FixedAssetDepreciationRun $run): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/v1/depreciation-runs/{$run->public_id}/post");
    }

    private function net(string $code): int
    {
        $ids = $this->accountFamily($code);
        $lines = JournalLine::query()->whereIn('account_id', $ids)
            ->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'));

        return (int) (clone $lines)->sum('base_debit_minor') - (int) (clone $lines)->sum('base_credit_minor');
    }

    private function assertLedgerBalanced(): void
    {
        foreach (JournalEntry::query()->where('status', 'posted')->with('lines')->get() as $entry) {
            $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'), "Journal {$entry->number} is not balanced.");
        }
    }

    // ------------------------------------------------------------------
    // Foreign currency & supplier payable
    // ------------------------------------------------------------------

    public function test_foreign_currency_asset_keeps_the_offset_in_its_currency_and_cost_in_base(): void
    {
        $supplier = $this->supplier();
        $asset = $this->asset([
            'currency' => 'USD',
            'fx_rate' => '50.5',
            'acquisition_value_minor' => 10_001, // USD 100.01
            'supplier_id' => $supplier->public_id,
            'useful_life_months' => 12,
        ]);

        // 10 001 x 50.5 = 505 050.5 -> half-up 505 051 EGP minor.
        $this->assertSame(505_051, $asset->acquisition_value_base_minor);
        $this->assertSame('manual', $asset->fx_rate_source);

        $this->capitalize($asset)->assertOk();

        $entry = JournalEntry::query()->where('source_type', 'fixed_asset')->where('source_id', $asset->id)->firstOrFail();
        $cost = $entry->lines->firstWhere('account_id', $this->account('1300')->id);
        $payable = $entry->lines->firstWhere('currency_code', 'USD');

        $this->assertSame(505_051, $cost->base_debit_minor);
        $this->assertSame('EGP', $cost->currency_code);
        $this->assertSame(10_001, $payable->credit_minor);
        $this->assertSame(505_051, $payable->base_credit_minor);
        // ADR-0015: the supplier's own payable subaccount, tagged with the supplier.
        $this->assertSame('supplier', $payable->party_type);
        $this->assertSame($supplier->id, $payable->party_id);
        $this->assertSame('2100', $payable->account->parent->code);
        $this->assertLedgerBalanced();

        // Depreciation and disposal stay in base minor units; the register ties to the GL.
        $this->assertSame(505_051, $this->net('1300'));
        $this->assertSame(-505_051, $this->net('2100'));
        $reconciliation = $this->withHeaders($this->headers())->getJson('/api/v1/reports/fixed-assets/reconciliation')->assertOk();
        $this->assertTrue($reconciliation->json('data.is_reconciled'));
    }

    public function test_changing_the_currency_of_a_draft_does_not_reuse_the_old_rate(): void
    {
        $asset = $this->asset(['currency' => 'EGP']);

        $this->withHeaders($this->headers())->patchJson("/api/v1/fixed-assets/{$asset->public_id}", [
            'currency' => 'USD',
            'fx_rate' => '48',
        ])->assertOk()->assertJsonPath('data.acquisition_value_base_minor', 1_200_000 * 48);
    }

    // ------------------------------------------------------------------
    // No double recognition
    // ------------------------------------------------------------------

    private function postCostBill(int $amount, string $costCode = '1300'): JournalEntry
    {
        return app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->startOfMonth()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account($costCode), $amount),
                PostingLine::credit($this->account('2100'), $amount),
            ],
            memo: 'Vendor bill',
            sourceType: 'test_bill',
            sourceId: 1,
            event: 'posted',
        ));
    }

    public function test_an_asset_linked_to_a_booked_bill_does_not_post_the_cost_twice(): void
    {
        $bill = $this->postCostBill(1_200_000);
        $before = JournalEntry::query()->count();

        $asset = $this->asset(['acquisition_journal_entry_id' => $bill->public_id]);
        $response = $this->capitalize($asset)->assertOk();

        $this->assertSame($before, JournalEntry::query()->count());
        $this->assertSame($bill->public_id, $response->json('data.capitalization_journal_entry_id'));
        $this->assertSame(1_200_000, $this->net('1300'));

        $this->assertTrue($this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation')->json('data.is_reconciled'));
    }

    public function test_a_bill_cannot_back_more_cost_than_it_booked(): void
    {
        $bill = $this->postCostBill(1_200_000);
        $this->asset(['acquisition_journal_entry_id' => $bill->public_id]);

        $this->withHeaders($this->headers())->postJson('/api/v1/fixed-assets', [
            'category_id' => $this->category(['code' => 'OTH'])->public_id,
            'description' => 'Second asset on the same bill',
            'capitalization_date' => now()->startOfMonth()->toDateString(),
            'acquisition_value_minor' => 100,
            'currency' => 'EGP',
            'useful_life_months' => 12,
            'acquisition_journal_entry_id' => $bill->public_id,
        ])->assertStatus(422);

        $other = app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->startOfMonth()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [PostingLine::debit($this->account('6800'), 5_000), PostingLine::credit($this->account('2100'), 5_000)],
            sourceType: 'test_bill',
            sourceId: 2,
            event: 'posted',
        ));
        $this->withHeaders($this->headers())->postJson('/api/v1/fixed-assets', [
            'category_id' => $this->category(['code' => 'OT2'])->public_id,
            'description' => 'Entry without a cost debit',
            'capitalization_date' => now()->startOfMonth()->toDateString(),
            'acquisition_value_minor' => 5_000,
            'currency' => 'EGP',
            'useful_life_months' => 12,
            'acquisition_journal_entry_id' => $other->public_id,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Depreciation maths
    // ------------------------------------------------------------------

    public function test_divide_rounded_follows_the_rounding_policy(): void
    {
        $this->assertSame(3, Money::divideRounded(5, 2, Money::ROUND_HALF_UP));
        $this->assertSame(2, Money::divideRounded(5, 2, Money::ROUND_BANKERS));
        $this->assertSame(4, Money::divideRounded(7, 2, Money::ROUND_BANKERS));
        $this->assertSame(1, Money::divideRounded(4, 3, Money::ROUND_BANKERS));
        $this->assertSame(0, Money::divideRounded(0, 7));
    }

    public function test_monthly_amount_uses_the_rounding_policy_and_the_total_is_exact(): void
    {
        // 100 000 / 3 = 33 333.33 -> 33 333; 200 001 / 3 = 66 667 exactly; 100 001 / 2 = 50 000.5.
        app(SettingService::class)->set('accounting', 'rounding_policy', 'bankers');
        $asset = $this->asset(['acquisition_value_minor' => 100_001, 'useful_life_months' => 2]);
        $this->capitalize($asset)->assertOk();

        $schedule = $this->withHeaders($this->headers())->getJson("/api/v1/fixed-assets/{$asset->public_id}/schedule")->json('data.periods');

        // Bankers: 50 000.5 -> 50 000 (even); the last month takes the 50 001 remainder.
        $this->assertSame([50_000, 50_001], array_column($schedule, 'amount_minor'));
        $this->assertSame(100_001, end($schedule)['accumulated_minor']);

        app(SettingService::class)->set('accounting', 'rounding_policy', 'half_up');
        $schedule = $this->withHeaders($this->headers())->getJson("/api/v1/fixed-assets/{$asset->public_id}/schedule")->json('data.periods');
        $this->assertSame([50_001, 50_000], array_column($schedule, 'amount_minor'));
    }

    public function test_impairment_respreads_the_remaining_amount_over_the_remaining_months(): void
    {
        $this->openNextPeriod();
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();
        $this->postRun($this->makeRun(now()->format('Y-m')))->assertOk();
        $this->postRun($this->makeRun($this->nextPeriod()))->assertOk();

        // An applied impairment of 300 000: 1 200 000 - 200 000 - 300 000 = 700 000 left for 10 months.
        $asset->refresh()->update(['impairment_minor' => 300_000]);

        $periods = $this->withHeaders($this->headers())->getJson("/api/v1/fixed-assets/{$asset->public_id}/schedule")->json('data.periods');
        $this->assertCount(12, $periods);
        $this->assertSame([100_000, 100_000], array_column(array_slice($periods, 0, 2), 'amount_minor'));
        foreach (array_slice($periods, 2) as $row) {
            $this->assertSame(70_000, $row['amount_minor']);
        }
        // Depreciation + impairment still equals cost: the asset ends at zero, never below.
        $this->assertSame(900_000, end($periods)['accumulated_minor']);
        $this->assertSame(0, end($periods)['carrying_value_minor']);
    }

    // ------------------------------------------------------------------
    // Disposal
    // ------------------------------------------------------------------

    public function test_disposal_depreciates_to_the_disposal_date(): void
    {
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();

        $day = 15;
        $date = now()->startOfMonth()->addDays($day - 1);
        $expectedCharge = Money::divideRounded(100_000 * $day, $date->daysInMonth);

        $response = $this->withHeaders($this->headers())->postJson("/api/v1/fixed-assets/{$asset->public_id}/dispose", [
            'disposal_date' => $date->toDateString(),
            'disposal_type' => 'sale',
            'proceeds_minor' => 1_000_000,
        ])->assertOk();

        $carrying = 1_200_000 - $expectedCharge;
        $this->assertSame($expectedCharge, $response->json('data.disposal_depreciation_minor'));
        $this->assertSame(1_000_000 - $carrying, $response->json('data.disposal_gain_loss_minor'));
        $this->assertSame(0, $this->net('1300'));
        $this->assertSame(0, $this->net('1210'));
        $this->assertSame($expectedCharge, $this->net('6800'));
        $this->assertLedgerBalanced();

        $reconciliation = $this->withHeaders($this->headers())->getJson('/api/v1/reports/fixed-assets/reconciliation')->assertOk();
        $this->assertTrue($reconciliation->json('data.is_reconciled'));

        $roll = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/roll-forward?from='.now()->format('Y-m').'&to='.now()->format('Y-m'))
            ->assertOk();
        $this->assertSame($expectedCharge, $roll->json('data.accumulated_depreciation.depreciation_minor'));
        $this->assertSame($expectedCharge, $roll->json('data.accumulated_depreciation.disposals_minor'));
        $this->assertSame(0, $roll->json('data.accumulated_depreciation.closing_minor'));
        $this->assertSame(0, $roll->json('data.carrying_value.difference_minor'));
    }

    public function test_disposal_requires_earlier_months_to_be_depreciated_and_a_sensible_date(): void
    {
        $this->openNextPeriod();
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();

        // The current month is not depreciated yet, so a disposal next month is refused.
        $this->withHeaders($this->headers())->postJson("/api/v1/fixed-assets/{$asset->public_id}/dispose", [
            'disposal_date' => now()->startOfMonth()->addMonthNoOverflow()->addDays(9)->toDateString(),
        ])->assertStatus(422);

        $this->postRun($this->makeRun(now()->format('Y-m')))->assertOk();

        // The month-end run is posted: an earlier date inside that month would undo it.
        $this->withHeaders($this->headers())->postJson("/api/v1/fixed-assets/{$asset->public_id}/dispose", [
            'disposal_date' => now()->startOfMonth()->addDays(9)->toDateString(),
        ])->assertStatus(422);

        $this->assertSame(FixedAsset::STATUS_ACTIVE, $asset->refresh()->status);
    }

    public function test_disposal_proceeds_must_go_to_an_asset_account(): void
    {
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();

        $this->withHeaders($this->headers())->postJson("/api/v1/fixed-assets/{$asset->public_id}/dispose", [
            'disposal_date' => now()->endOfMonth()->toDateString(),
            'proceeds_minor' => 1_000,
            'proceeds_account_code' => '6800',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Run reversal
    // ------------------------------------------------------------------

    public function test_a_posted_run_can_be_reversed_and_run_again(): void
    {
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();
        $run = $this->makeRun(now()->format('Y-m'));
        $this->postRun($run)->assertOk();
        $this->assertSame(100_000, $asset->refresh()->accumulated_depreciation_minor);

        $this->withHeaders($this->headers())->deleteJson("/api/v1/depreciation-runs/{$run->public_id}")->assertStatus(422);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/depreciation-runs/{$run->public_id}/reverse", ['reason' => 'Wrong useful life'])
            ->assertOk()->assertJsonPath('data.status', 'reversed');

        $asset->refresh();
        $this->assertSame(0, $asset->accumulated_depreciation_minor);
        $this->assertNull($asset->last_depreciated_period);
        $this->assertSame(FixedAsset::STATUS_ACTIVE, $asset->status);
        $this->assertSame(0, $this->net('6800'));
        $this->assertSame(0, $this->net('1210'));
        $this->assertLedgerBalanced();

        // A reversed run can neither be posted again nor flipped to failed.
        $this->postRun($run)->assertStatus(422);
        $this->assertSame(FixedAssetDepreciationRun::STATUS_REVERSED, $run->refresh()->status);

        // Reversing twice is refused, and the month can be run again.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/depreciation-runs/{$run->public_id}/reverse", ['reason' => 'Again'])
            ->assertStatus(422);
        $again = $this->makeRun(now()->format('Y-m'));
        $this->assertSame(1, $again->assets_count);
        $this->postRun($again)->assertOk();
        $this->assertSame(100_000, $asset->refresh()->accumulated_depreciation_minor);
        $this->assertSame(100_000, $this->net('6800'));
    }

    public function test_only_the_latest_period_of_an_asset_can_be_reversed(): void
    {
        $this->openNextPeriod();
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();
        $first = $this->makeRun(now()->format('Y-m'));
        $this->postRun($first)->assertOk();
        $this->postRun($this->makeRun($this->nextPeriod()))->assertOk();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/depreciation-runs/{$first->public_id}/reverse", ['reason' => 'Too late'])
            ->assertStatus(422);

        $this->assertSame(200_000, $asset->refresh()->accumulated_depreciation_minor);
    }

    // ------------------------------------------------------------------
    // Reconciliation
    // ------------------------------------------------------------------

    public function test_reconciliation_handles_shared_accounts_and_unregistered_fixed_asset_balances(): void
    {
        // Two categories on the same default accounts: no false difference.
        $this->capitalize($this->asset(['description' => 'A'], $this->category(['code' => 'C1'])))->assertOk();
        $this->capitalize($this->asset(['description' => 'B', 'acquisition_value_minor' => 600_000], $this->category(['code' => 'C2'])))->assertOk();

        $response = $this->withHeaders($this->headers())->getJson('/api/v1/reports/fixed-assets/reconciliation')->assertOk();
        $this->assertTrue($response->json('data.is_reconciled'));
        $this->assertSame(1_800_000, collect($response->json('data.accounts'))->firstWhere('role', 'cost')['gl_minor']);
        $this->assertTrue($response->json('data.categories.0.shared_accounts'));

        // A balance on a fixed-asset account with no register category (party-less line) is a difference.
        $extra = Account::query()->create([
            'code' => '1310', 'name_ar' => 'معدات', 'name_en' => 'Equipment', 'type' => 'asset',
            'is_postable' => true, 'is_active' => true, 'classification' => 'fixed_asset',
        ]);
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->startOfMonth()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [PostingLine::debit($extra, 70_000), PostingLine::credit($this->account('1120'), 70_000)],
            sourceType: 'test_bill',
            sourceId: 9,
            event: 'posted',
        ));

        $response = $this->withHeaders($this->headers())->getJson('/api/v1/reports/fixed-assets/reconciliation')->assertOk();
        $this->assertFalse($response->json('data.is_reconciled'));
        $this->assertSame(-70_000, $response->json('data.cost_difference_minor'));
        $this->assertSame('1310', $response->json('data.unregistered_accounts.0.account.code'));
    }

    public function test_reconciliation_as_of_a_date_ignores_drafts_and_later_depreciation(): void
    {
        $this->openNextPeriod();
        $asset = $this->asset();
        $this->capitalize($asset)->assertOk();
        $this->asset(['description' => 'Draft only', 'acquisition_value_minor' => 999_000]); // never capitalized
        $this->postRun($this->makeRun(now()->format('Y-m')))->assertOk();

        // Mid-month the month-end run is not in the ledger yet, nor in the register.
        $mid = now()->startOfMonth()->addDays(9)->toDateString();
        $this->assertTrue($this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation?as_of='.$mid)->json('data.is_reconciled'));
        $this->assertTrue($this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation?as_of='.now()->endOfMonth()->toDateString())->json('data.is_reconciled'));

        $carrying = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/carrying-values?as_of='.$mid)->assertOk();
        $this->assertSame(1_200_000, $carrying->json('data.total_carrying_value_minor'));

        // Draft assets are not additions either.
        $roll = $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/roll-forward?from='.now()->format('Y-m').'&to='.now()->format('Y-m'))
            ->assertOk();
        $this->assertSame(1_200_000, $roll->json('data.cost.additions_minor'));
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    public function test_category_accounts_must_have_the_right_types(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/v1/fixed-asset-categories', [
            'code' => 'BAD',
            'asset_account_code' => '6800', // an expense account as the cost account
            'name_ar' => 'x',
            'name_en' => 'x',
        ])->assertStatus(422);

        $this->withHeaders($this->headers())->postJson('/api/v1/fixed-assets', [
            'category_id' => $this->category()->public_id,
            'code' => 'DUP',
            'description' => 'One',
            'capitalization_date' => now()->toDateString(),
            'acquisition_value_minor' => 1_000,
            'currency' => 'EGP',
        ])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/v1/fixed-assets', [
            'category_id' => FixedAssetCategory::query()->firstOrFail()->public_id,
            'code' => 'DUP',
            'description' => 'Two',
            'capitalization_date' => now()->toDateString(),
            'acquisition_value_minor' => 1_000,
            'currency' => 'EGP',
        ])->assertStatus(422);
    }
}
