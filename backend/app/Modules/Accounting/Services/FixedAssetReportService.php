<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\FixedAssetDepreciationItem;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Fixed-asset reporting and control reconciliation (Prompt 25).
 *
 * These reports are read-only projections of the register and the posted
 * ledger. Nothing here creates journals. The reconciliation deliberately
 * compares the register (cost, accumulated depreciation and impairment) to
 * the GL control accounts so any drift is visible instead of hidden.
 */
class FixedAssetReportService
{
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly SettingService $settings,
    ) {}

    /**
     * Roll-forward of cost, accumulated depreciation and carrying value for a
     * month range. Amounts are base-currency minor units.
     *
     * @return array<string, mixed>
     */
    public function rollForward(?Branch $branch, string $from, string $to): array
    {
        [$fromPeriod, $toPeriod, $fromDate, $toDate] = $this->range($from, $to);

        $assets = FixedAsset::query()
            ->with(['category', 'depreciationItems'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->get();

        $openingCost = 0;
        $openingAccumulated = 0;
        $additions = 0;
        $depreciation = 0;
        $impairment = 0;
        $disposalsCost = 0;
        $disposalsAccumulated = 0;
        $closingCost = 0;
        $closingAccumulated = 0;
        $closingCarrying = 0;

        foreach ($assets as $asset) {
            $capitalizedOn = $asset->capitalization_date->toDateString();
            $disposedOn = $asset->disposed_on?->toDateString();

            $existedBefore = $capitalizedOn < $fromDate;
            $addedInRange = $capitalizedOn >= $fromDate && $capitalizedOn <= $toDate;
            $disposedInRange = $disposedOn !== null && $disposedOn >= $fromDate && $disposedOn <= $toDate;
            $disposedBefore = $disposedOn !== null && $disposedOn < $fromDate;

            if ($existedBefore && ! $disposedBefore) {
                $openingCost += $asset->acquisition_value_base_minor;
                $openingAccumulated += $this->accumulatedAsOf(
                    $asset,
                    $fromPeriod,
                    Carbon::parse($fromDate)->subDay()->toDateString(),
                );
            }

            if ($addedInRange) {
                $additions += $asset->acquisition_value_base_minor;
            }

            $depreciation += $this->depreciationInRange($asset, $fromPeriod, $toPeriod);
            $impairment += $this->impairmentInRange($asset, $fromDate, $toDate);

            if ($disposedInRange) {
                $disposalsCost += $asset->acquisition_value_base_minor;
                $disposalsAccumulated += $this->accumulatedAtDisposal($asset);
            }

            $stillOpenAtEnd = $disposedOn === null || $disposedOn > $toDate;
            if ($capitalizedOn <= $toDate && $stillOpenAtEnd) {
                $closingCost += $asset->acquisition_value_base_minor;
                $closingAccumulated += $this->accumulatedAsOf($asset, $this->nextPeriod($toPeriod), $toDate);
            }
        }

        $closingCarrying = max(0, $closingCost - $closingAccumulated);

        return [
            'branch_id' => $branch?->public_id,
            'from' => $fromPeriod,
            'to' => $toPeriod,
            'currency' => $this->settings->baseCurrency(),
            'cost' => [
                'opening_minor' => $openingCost,
                'additions_minor' => $additions,
                'disposals_minor' => $disposalsCost,
                'closing_minor' => $openingCost + $additions - $disposalsCost,
            ],
            'accumulated_depreciation' => [
                'opening_minor' => $openingAccumulated,
                'depreciation_minor' => $depreciation,
                'impairment_minor' => $impairment,
                'disposals_minor' => $disposalsAccumulated,
                'closing_minor' => $openingAccumulated + $depreciation + $impairment - $disposalsAccumulated,
            ],
            'carrying_value' => [
                'opening_minor' => max(0, $openingCost - $openingAccumulated),
                'closing_minor' => $closingCarrying,
                'register_closing_minor' => $closingCost - $closingAccumulated,
                'difference_minor' => ($openingCost + $additions - $disposalsCost - $closingCost)
                    + ($closingAccumulated - ($openingAccumulated + $depreciation + $impairment - $disposalsAccumulated)),
            ],
        ];
    }

    /**
     * Register carrying values at a point in time.
     *
     * @return array<string, mixed>
     */
    public function carryingValues(?Branch $branch, ?string $asOf = null, bool $includeDisposed = false): array
    {
        $date = $asOf !== null ? $this->assertDate($asOf) : Carbon::now()->toDateString();
        $period = Carbon::parse($date)->format('Y-m');
        $next = $this->nextPeriod($period);

        $assets = FixedAsset::query()
            ->with(['branch', 'category'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when(! $includeDisposed, fn ($query) => $query->where(function ($query) use ($date) {
                $query->whereNull('disposed_on')->orWhereDate('disposed_on', '>', $date);
            }))
            ->whereDate('capitalization_date', '<=', $date)
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalCost = 0;
        $totalAccumulated = 0;

        foreach ($assets as $asset) {
            $accumulated = $this->accumulatedAsOf($asset, $next, $date);
            $cost = $asset->acquisition_value_base_minor;
            $carrying = max(0, $cost - $accumulated);

            $totalCost += $cost;
            $totalAccumulated += $accumulated;

            $rows[] = [
                'asset_id' => $asset->public_id,
                'code' => $asset->code,
                'description' => $asset->description,
                'branch' => $asset->branch?->toApiArray(),
                'category' => $asset->category?->code,
                'status' => $asset->status,
                'capitalization_date' => $asset->capitalization_date?->toDateString(),
                'disposed_on' => $asset->disposed_on?->toDateString(),
                'cost_minor' => $cost,
                'accumulated_minor' => $accumulated,
                'carrying_value_minor' => $carrying,
            ];
        }

        return [
            'as_of' => $date,
            'branch_id' => $branch?->public_id,
            'currency' => $this->settings->baseCurrency(),
            'assets_count' => count($rows),
            'total_cost_minor' => $totalCost,
            'total_accumulated_minor' => $totalAccumulated,
            'total_carrying_value_minor' => max(0, $totalCost - $totalAccumulated),
            'assets' => $rows,
        ];
    }

    /**
     * Additions and disposals that occurred inside a month range.
     *
     * @return array<string, mixed>
     */
    public function movements(?Branch $branch, string $from, string $to): array
    {
        [$fromPeriod, $toPeriod, $fromDate, $toDate] = $this->range($from, $to);

        $base = FixedAsset::query()
            ->with(['branch', 'category'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $additions = (clone $base)
            ->whereDate('capitalization_date', '>=', $fromDate)
            ->whereDate('capitalization_date', '<=', $toDate)
            ->orderBy('capitalization_date')
            ->get()
            ->map(fn (FixedAsset $asset) => [
                'asset_id' => $asset->public_id,
                'code' => $asset->code,
                'description' => $asset->description,
                'capitalization_date' => $asset->capitalization_date?->toDateString(),
                'category' => $asset->category?->code,
                'branch' => $asset->branch?->toApiArray(),
                'cost_minor' => $asset->acquisition_value_base_minor,
            ])
            ->values()
            ->all();

        $disposals = (clone $base)
            ->whereDate('disposed_on', '>=', $fromDate)
            ->whereDate('disposed_on', '<=', $toDate)
            ->orderBy('disposed_on')
            ->get()
            ->map(fn (FixedAsset $asset) => [
                'asset_id' => $asset->public_id,
                'code' => $asset->code,
                'description' => $asset->description,
                'disposal_date' => $asset->disposed_on?->toDateString(),
                'disposal_type' => $asset->disposal_type,
                'category' => $asset->category?->code,
                'branch' => $asset->branch?->toApiArray(),
                'cost_minor' => $asset->acquisition_value_base_minor,
                'accumulated_minor' => $this->accumulatedAtDisposal($asset),
                'proceeds_minor' => $asset->disposal_proceeds_minor,
                'gain_loss_minor' => $asset->disposal_gain_loss_minor,
                'journal_entry_id' => $asset->disposalJournalEntry?->public_id,
            ])
            ->values()
            ->all();

        return [
            'branch_id' => $branch?->public_id,
            'from' => $fromPeriod,
            'to' => $toPeriod,
            'currency' => $this->settings->baseCurrency(),
            'additions' => $additions,
            'disposals' => $disposals,
            'additions_total_minor' => array_sum(array_column($additions, 'cost_minor')),
            'disposals_total_minor' => array_sum(array_column($disposals, 'cost_minor')),
        ];
    }

    /**
     * Compare register totals with the posted GL control accounts.
     *
     * Branch transfers intentionally never rewrite the GL, so a branch filter
     * can legitimately show a reallocation difference. The installation-wide
     * reconciliation (no branch filter) must balance.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(?Branch $branch, ?string $asOf = null): array
    {
        $date = $asOf !== null ? $this->assertDate($asOf) : null;

        $categories = FixedAssetCategory::query()
            ->with('assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount')
            ->orderBy('code')
            ->get();

        $assets = FixedAsset::query()
            ->with('category')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($date !== null, fn ($query) => $query->whereDate('capitalization_date', '<=', $date))
            ->when($date !== null, fn ($query) => $query->where(function ($query) use ($date) {
                $query->whereNull('disposed_on')->orWhereDate('disposed_on', '>', $date);
            }))
            ->when($date === null, fn ($query) => $query->whereNull('disposed_on'))
            ->get();

        $registerCost = [];
        $registerAccumulated = [];

        foreach ($assets as $asset) {
            $categoryId = $asset->fixed_asset_category_id;
            $registerCost[$categoryId] = ($registerCost[$categoryId] ?? 0) + $asset->acquisition_value_base_minor;
            $registerAccumulated[$categoryId] = ($registerAccumulated[$categoryId] ?? 0) + $asset->totalAccumulatedMinor();
        }

        $rows = [];
        $totalCostDifference = 0;
        $totalAccumulatedDifference = 0;

        foreach ($categories as $category) {
            $costBalance = $category->assetAccount
                ? $this->glBalance($category->assetAccount->id, $branch, $date, false)
                : 0;
            $accumulatedBalance = $category->accumulatedDepreciationAccount
                ? $this->glBalance($category->accumulatedDepreciationAccount->id, $branch, $date, true)
                : 0;

            $costRegister = $registerCost[$category->id] ?? 0;
            $accumulatedRegister = $registerAccumulated[$category->id] ?? 0;
            $costDifference = $costRegister - $costBalance;
            $accumulatedDifference = $accumulatedRegister - $accumulatedBalance;

            $totalCostDifference += $costDifference;
            $totalAccumulatedDifference += $accumulatedDifference;

            $rows[] = [
                'category_id' => $category->public_id,
                'category_code' => $category->code,
                'category_name' => $category->name_en,
                'asset_account' => $category->assetAccount?->toApiArray(),
                'accumulated_depreciation_account' => $category->accumulatedDepreciationAccount?->toApiArray(),
                'depreciation_expense_account' => $category->depreciationExpenseAccount?->toApiArray(),
                'register_cost_minor' => $costRegister,
                'gl_cost_minor' => $costBalance,
                'cost_difference_minor' => $costDifference,
                'register_accumulated_minor' => $accumulatedRegister,
                'gl_accumulated_minor' => $accumulatedBalance,
                'accumulated_difference_minor' => $accumulatedDifference,
                'is_reconciled' => $costDifference === 0 && $accumulatedDifference === 0,
            ];
        }

        return [
            'as_of' => $date,
            'branch_id' => $branch?->public_id,
            'scope' => $branch ? 'branch' : 'global',
            'currency' => $this->settings->baseCurrency(),
            'is_reconciled' => $totalCostDifference === 0 && $totalAccumulatedDifference === 0,
            'cost_difference_minor' => $totalCostDifference,
            'accumulated_difference_minor' => $totalAccumulatedDifference,
            'note' => $branch
                ? 'Branch figures can differ by the cost of assets operationally transferred between branches; the GL control accounts are global.'
                : 'Register and GL control accounts must agree at installation level.',
            'categories' => $rows,
        ];
    }

    /**
     * Depreciation schedule for one asset or for the whole register.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schedule(?FixedAsset $asset = null, ?Branch $branch = null): array
    {
        if ($asset !== null) {
            return $this->assets->depreciationSchedule($asset);
        }

        return FixedAsset::query()
            ->with(['branch', 'category'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->orderBy('code')
            ->get()
            ->map(fn (FixedAsset $asset) => [
                'asset_id' => $asset->public_id,
                'code' => $asset->code,
                'description' => $asset->description,
                'category' => $asset->category?->code,
                'branch' => $asset->branch?->toApiArray(),
                'useful_life_months' => $asset->useful_life_months,
                'depreciable_minor' => $asset->depreciableMinor(),
                'carrying_value_minor' => $asset->carryingValueMinor(),
                'periods' => $this->assets->depreciationSchedule($asset),
            ])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function range(string $from, string $to): array
    {
        $fromPeriod = $this->assertPeriod($from);
        $toPeriod = $this->assertPeriod($to);

        if ($fromPeriod > $toPeriod) {
            throw new DomainException('The start period must be on or before the end period.');
        }

        $fromDate = Carbon::createFromFormat('!Y-m', $fromPeriod)->startOfMonth()->toDateString();
        $toDate = Carbon::createFromFormat('!Y-m', $toPeriod)->endOfMonth()->toDateString();

        return [$fromPeriod, $toPeriod, $fromDate, $toDate];
    }

    private function assertPeriod(string $period): string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new DomainException('The period must use the YYYY-MM format.');
        }

        return $period;
    }

    private function assertDate(string $date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    private function nextPeriod(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->startOfMonth()->addMonthNoOverflow()->format('Y-m');
    }

    /**
     * Accumulated depreciation and impairment recorded before a period, up to
     * an optional calendar date.
     */
    private function accumulatedAsOf(FixedAsset $asset, string $beforePeriod, ?string $asOfDate = null): int
    {
        $items = $asset->relationLoaded('depreciationItems')
            ? $asset->depreciationItems
            : $asset->depreciationItems()->get();

        $depreciation = $items
            ->where('status', FixedAssetDepreciationItem::STATUS_POSTED)
            ->filter(fn (FixedAssetDepreciationItem $item) => $item->period < $beforePeriod)
            ->sum('amount_minor');

        return (int) $depreciation + $this->impairmentAsOf($asset, $asOfDate);
    }

    private function impairmentAsOf(FixedAsset $asset, ?string $asOfDate): int
    {
        $ids = $asset->applied_impairment_document_ids ?? [];
        if ($ids === []) {
            return 0;
        }

        return (int) AccountingDocument::query()
            ->whereIn('public_id', $ids)
            ->when($asOfDate, fn ($query) => $query->whereDate('document_date', '<=', $asOfDate))
            ->sum('total_minor');
    }

    private function accumulatedAtDisposal(FixedAsset $asset): int
    {
        if (! $asset->disposed_on) {
            return $asset->totalAccumulatedMinor();
        }

        return $this->accumulatedAsOf(
            $asset,
            $asset->disposed_on->format('Y-m'),
            $asset->disposed_on->toDateString(),
        );
    }

    private function depreciationInRange(FixedAsset $asset, string $fromPeriod, string $toPeriod): int
    {
        $items = $asset->relationLoaded('depreciationItems')
            ? $asset->depreciationItems
            : $asset->depreciationItems()->get();

        return (int) $items
            ->where('status', FixedAssetDepreciationItem::STATUS_POSTED)
            ->filter(fn (FixedAssetDepreciationItem $item) => $item->period >= $fromPeriod && $item->period <= $toPeriod)
            ->sum('amount_minor');
    }

    private function impairmentInRange(FixedAsset $asset, string $fromDate, string $toDate): int
    {
        $ids = $asset->applied_impairment_document_ids ?? [];
        if ($ids === []) {
            return 0;
        }

        return (int) AccountingDocument::query()
            ->whereIn('public_id', $ids)
            ->whereDate('document_date', '>=', $fromDate)
            ->whereDate('document_date', '<=', $toDate)
            ->sum('total_minor');
    }

    /** Signed balance in the account's normal direction. */
    private function glBalance(int $accountId, ?Branch $branch, ?string $asOf, bool $creditNormal): int
    {
        $query = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->when($branch, fn ($query) => $query->where('journal_lines.branch_id', $branch->id))
            ->when($asOf, fn ($query) => $query->whereDate('journal_entries.entry_date', '<=', $asOf));

        $debit = (int) (clone $query)->sum('journal_lines.base_debit_minor');
        $credit = (int) (clone $query)->sum('journal_lines.base_credit_minor');

        return $creditNormal ? $credit - $debit : $debit - $credit;
    }
}
