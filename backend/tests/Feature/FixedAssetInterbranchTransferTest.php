<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\FixedAssetTransfer;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Inter-branch fixed-asset transfer (audit item 18 and 24): two balanced
 * journals at net book value through the inter-branch clearing accounts,
 * reversal, closed periods, foreign-currency assets, reconciliation per branch
 * and the branch-scope check on the destination.
 */
class FixedAssetInterbranchTransferTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'assets.view', 'assets.manage', 'assets.approve',
        'periods.view', 'periods.manage',
        'journal.view', 'journal.create', 'reports.view', 'branches.view_all',
    ];

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->other = Branch::query()->create([
            'code' => 'ALX', 'name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($this->other->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    /** @param array<string, mixed> $overrides */
    private function capitalizedAsset(array $overrides = []): FixedAsset
    {
        $category = FixedAssetCategory::query()->first();
        if (! $category) {
            $response = $this->withHeaders($this->headers())->postJson('/api/v1/fixed-asset-categories', [
                'code' => 'VEH', 'name_ar' => 'مركبات', 'name_en' => 'Vehicles', 'default_useful_life_months' => 12,
            ])->assertCreated();
            $category = FixedAssetCategory::query()->where('public_id', $response->json('data.id'))->firstOrFail();
        }

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
        $asset = FixedAsset::query()->where('public_id', $response->json('data.id'))->firstOrFail();

        $this->withHeaders($this->headers())->postJson("/api/v1/fixed-assets/{$asset->public_id}/capitalize")->assertOk();

        return $asset->refresh();
    }

    private function depreciateThisMonth(): void
    {
        $run = $this->withHeaders($this->headers())
            ->postJson('/api/v1/depreciation-runs', ['period' => now()->format('Y-m')])->assertCreated();
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/depreciation-runs/'.$run->json('data.id').'/post')->assertOk();
    }

    private function transfer(FixedAsset $asset, ?Branch $to = null, array $extra = [], ?Branch $as = null)
    {
        return $this->withHeaders($this->headers($as))
            ->postJson("/api/v1/fixed-assets/{$asset->public_id}/transfer", $extra + [
                'to_branch_id' => ($to ?? $this->other)->public_id,
                'transfer_date' => now()->toDateString(),
                'reason' => 'Relocation',
            ]);
    }

    /** Debit minus credit (base) of an account, optionally for one branch. */
    private function net(string $code, ?Branch $branch = null): int
    {
        $lines = JournalLine::query()
            ->where('account_id', $this->account($code)->id)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'));

        return (int) (clone $lines)->sum('base_debit_minor') - (int) (clone $lines)->sum('base_credit_minor');
    }

    private function assertLedgerBalanced(): void
    {
        foreach (JournalEntry::query()->where('status', 'posted')->with('lines')->get() as $entry) {
            $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'), "Journal {$entry->number} is not balanced.");
            $this->assertSame(1, $entry->lines->pluck('branch_id')->unique()->count(), "Journal {$entry->number} spans branches.");
        }
    }

    private function reconciliation(?Branch $branch): array
    {
        $url = '/api/v1/reports/fixed-assets/reconciliation'.($branch ? '' : '?global=1');

        return $this->withHeaders($this->headers($branch))->getJson($url)->assertOk()->json('data');
    }

    public function test_transfer_posts_one_balanced_journal_per_branch_at_net_book_value(): void
    {
        $asset = $this->capitalizedAsset();
        $this->depreciateThisMonth();
        $this->assertSame(100_000, $asset->refresh()->accumulated_depreciation_minor);

        $response = $this->transfer($asset)->assertCreated();

        $transfer = FixedAssetTransfer::query()->firstOrFail();
        $source = JournalEntry::query()->findOrFail($transfer->source_journal_entry_id);
        $destination = JournalEntry::query()->findOrFail($transfer->destination_journal_entry_id);

        $this->assertSame($this->branch->id, $source->branch_id);
        $this->assertSame($this->other->id, $destination->branch_id);
        $this->assertSame('fixed_asset_transfer', $source->source_type);
        $this->assertSame($transfer->id, $source->source_id);
        $this->assertSame($transfer->id, $destination->source_id);
        $this->assertNotSame($source->event, $destination->event);
        $this->assertSame(1_100_000, $response->json('data.transfer.net_book_value_minor'));
        $this->assertSame($source->number, $response->json('data.transfer.source_journal.number'));
        $this->assertLedgerBalanced();

        // Source: cost and accumulated depreciation out, NBV due from destination.
        $this->assertSame(0, $this->net('1300', $this->branch));
        $this->assertSame(0, $this->net('1210', $this->branch));
        $this->assertSame(1_100_000, $this->net('1180', $this->branch));
        // Destination: they come in, NBV due to source.
        $this->assertSame(1_200_000, $this->net('1300', $this->other));
        $this->assertSame(-100_000, $this->net('1210', $this->other));
        $this->assertSame(-1_100_000, $this->net('2160', $this->other));
        // Clearing nets to zero globally; the global control accounts are untouched.
        $this->assertSame(0, $this->net('1180') + $this->net('2160'));
        $this->assertSame(1_200_000, $this->net('1300'));
        $this->assertSame(-100_000, $this->net('1210'));
        $this->assertSame($this->other->id, $asset->refresh()->branch_id);
    }

    public function test_reconciliation_holds_per_branch_and_globally_and_trial_balance_shows_the_clearing_check(): void
    {
        $asset = $this->capitalizedAsset();
        $this->depreciateThisMonth();
        $this->transfer($asset)->assertCreated();

        foreach ([null, $this->branch, $this->other] as $branch) {
            $data = $this->reconciliation($branch);
            $this->assertTrue($data['is_reconciled'], 'Not reconciled for '.($branch?->code ?? 'global'));
            $this->assertSame(0, $data['cost_difference_minor']);
            $this->assertSame(0, $data['accumulated_difference_minor']);
        }

        // As of a date before the transfer the asset still sat on the source branch.
        $before = now()->startOfMonth()->toDateString();
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/reports/fixed-assets/reconciliation?as_of='.$before)->assertOk();

        $from = now()->startOfYear()->toDateString();
        $to = now()->endOfYear()->toDateString();
        $source = $this->withHeaders($this->headers())
            ->getJson("/api/v1/reports/trial-balance?from={$from}&to={$to}")->assertOk();
        $this->assertTrue($source->json('data.balanced'));
        $this->assertSame('branch', $source->json('data.interbranch.scope'));
        $this->assertSame(1_100_000, $source->json('data.interbranch.receivable_closing_minor'));
        $this->assertSame(0, $source->json('data.interbranch.payable_closing_minor'));
        $this->assertSame(0, $source->json('data.interbranch.global_net_minor'));
        $this->assertTrue($source->json('data.interbranch.balanced'));

        $destination = $this->withHeaders($this->headers($this->other))
            ->getJson("/api/v1/reports/trial-balance?from={$from}&to={$to}")->assertOk();
        $this->assertSame(-1_100_000, $destination->json('data.interbranch.payable_closing_minor'));
        $this->assertSame(1_100_000, $destination->json('data.interbranch.global_receivable_closing_minor'));
        $this->assertSame(-1_100_000, $destination->json('data.interbranch.global_payable_closing_minor'));
    }

    public function test_transfer_can_be_reversed_once_and_restores_the_branch_and_balances(): void
    {
        $asset = $this->capitalizedAsset();
        $this->transfer($asset)->assertCreated();
        $transfer = FixedAssetTransfer::query()->firstOrFail();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/fixed-asset-transfers/{$transfer->public_id}/reverse", ['reason' => 'Wrong branch'])
            ->assertOk()
            ->assertJsonPath('data.transfer.is_reversed', true);

        $this->assertSame($this->branch->id, $asset->refresh()->branch_id);
        $this->assertSame(1_200_000, $this->net('1300', $this->branch));
        $this->assertSame(0, $this->net('1300', $this->other));
        $this->assertSame(0, $this->net('1180'));
        $this->assertSame(0, $this->net('2160'));
        $this->assertLedgerBalanced();
        $this->assertTrue($this->reconciliation($this->branch)['is_reconciled']);
        $this->assertTrue($this->reconciliation($this->other)['is_reconciled']);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/fixed-asset-transfers/{$transfer->public_id}/reverse", ['reason' => 'again'])
            ->assertStatus(422);

        // It can be transferred again (new transfer, new event keys).
        $this->transfer($asset)->assertCreated();
        $this->assertSame(2, FixedAssetTransfer::query()->count());
        $this->assertLedgerBalanced();
    }

    public function test_transfer_cannot_be_reversed_after_later_depreciation(): void
    {
        $asset = $this->capitalizedAsset();
        $this->transfer($asset)->assertCreated();
        $transfer = FixedAssetTransfer::query()->firstOrFail();

        $run = $this->withHeaders($this->headers($this->other))
            ->postJson('/api/v1/depreciation-runs', ['period' => now()->format('Y-m')])->assertCreated();
        $this->withHeaders($this->headers($this->other))
            ->postJson('/api/v1/depreciation-runs/'.$run->json('data.id').'/post')->assertOk();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/fixed-asset-transfers/{$transfer->public_id}/reverse", ['reason' => 'late'])
            ->assertStatus(422);
        $this->assertSame($this->other->id, $asset->refresh()->branch_id);
    }

    public function test_closed_period_refuses_the_transfer_and_nothing_is_persisted(): void
    {
        $asset = $this->capitalizedAsset();
        $start = now()->startOfMonth()->addMonthNoOverflow();
        FiscalPeriod::query()->create([
            'fiscal_year_id' => FiscalYear::query()->firstOrFail()->id,
            'name' => $start->format('Y-m'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->endOfMonth()->toDateString(),
            'status' => 'closed',
        ]);
        $journals = JournalEntry::query()->count();

        $this->transfer($asset, null, ['transfer_date' => $start->toDateString()])
            ->assertStatus(422)->assertJsonPath('code', 'domain_error');

        $this->assertSame($journals, JournalEntry::query()->count());
        $this->assertSame(0, FixedAssetTransfer::query()->count());
        $this->assertSame($this->branch->id, $asset->refresh()->branch_id);
    }

    public function test_destination_outside_the_actor_branch_scope_is_refused(): void
    {
        $outside = Branch::query()->create([
            'code' => 'LXR', 'name_ar' => 'الأقصر', 'name_en' => 'Luxor',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $asset = $this->capitalizedAsset();

        $scoped = User::factory()->create();
        $scoped->branches()->attach($this->branch->id);
        $scoped->givePermissionTo(['assets.view', 'assets.manage', 'accounts.post']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($scoped);
        $journals = JournalEntry::query()->count();

        $this->transfer($asset, $outside)->assertForbidden();

        $this->assertSame($journals, JournalEntry::query()->count());
        $this->assertSame(0, FixedAssetTransfer::query()->count());
        $this->assertSame($this->branch->id, $asset->refresh()->branch_id);
    }

    public function test_foreign_currency_asset_moves_at_its_base_amounts(): void
    {
        $asset = $this->capitalizedAsset([
            'currency' => 'USD', 'fx_rate' => '50.5', 'acquisition_value_minor' => 10_001,
        ]);
        $this->assertSame(505_051, $asset->acquisition_value_base_minor);

        $this->transfer($asset)->assertCreated();

        $this->assertSame(505_051, $this->net('1300', $this->other));
        $this->assertSame(0, $this->net('1300', $this->branch));
        $this->assertSame(505_051, $this->net('1180', $this->branch));
        $this->assertSame(-505_051, $this->net('2160', $this->other));
        $this->assertSame(0, JournalLine::query()
            ->whereHas('journalEntry', fn ($q) => $q->where('source_type', 'fixed_asset_transfer'))
            ->where('currency_code', '!=', 'EGP')->count());
        $this->assertLedgerBalanced();
        $this->assertTrue($this->reconciliation(null)['is_reconciled']);
    }

    public function test_fully_depreciated_asset_posts_no_clearing_lines(): void
    {
        $asset = $this->capitalizedAsset();
        $asset->update(['accumulated_depreciation_minor' => 1_200_000, 'status' => FixedAsset::STATUS_FULLY_DEPRECIATED]);
        // Mirror the register in the ledger so the assertion is about the transfer only.
        $posting = app(PostingService::class);
        $posting->post(PostingRequest::make(
            now()->toDateString(), $this->branch, 'EGP',
            [
                PostingLine::debit($this->account('6800'), 1_200_000),
                PostingLine::credit($this->account('1210'), 1_200_000),
            ],
            'test depreciation', 'test', 1, 'dep', actorId: $this->user->id,
        ));

        $this->transfer($asset)->assertCreated();

        $transfer = FixedAssetTransfer::query()->firstOrFail();
        $this->assertSame(2, JournalEntry::query()->findOrFail($transfer->source_journal_entry_id)->lines()->count());
        $this->assertSame(0, $this->net('1180') + $this->net('2160'));
        $this->assertSame(0, $this->net('1180'));
        $this->assertLedgerBalanced();
    }

    public function test_clearing_accounts_are_seeded_classified_and_configurable(): void
    {
        $receivable = $this->account('1180');
        $payable = $this->account('2160');
        $this->assertSame('asset', $receivable->type);
        $this->assertSame('current_asset', $receivable->classification);
        $this->assertSame('liability', $payable->type);
        $this->assertSame('current_liability', $payable->classification);

        $settings = app(SettingService::class);
        $this->assertNull($settings->accountMappingError('accounting', 'interbranch_receivable_account_code', '1180'));
        $this->assertNull($settings->accountMappingError('accounting', 'interbranch_payable_account_code', '2160'));
        // Type validation: a liability cannot be the receivable, an asset cannot be the payable.
        $this->assertNotNull($settings->accountMappingError('accounting', 'interbranch_receivable_account_code', '2160'));
        $this->assertNotNull($settings->accountMappingError('accounting', 'interbranch_payable_account_code', '1180'));
    }
}
