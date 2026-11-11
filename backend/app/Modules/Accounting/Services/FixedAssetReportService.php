<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
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
            ->where('status', '!=', FixedAsset::STATUS_DRAFT)
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
        $next = $this->beforePeriodFor($date);

        $assets = FixedAsset::query()
            ->where('status', '!=', FixedAsset::STATUS_DRAFT)
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
            ->where('status', '!=', FixedAsset::STATUS_DRAFT)
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
     * An inter-branch transfer posts a journal per branch (cost and accumulated
     * depreciation move through the inter-branch clearing accounts), so the
     * branch view and the installation-wide view both reconcile. Transfers
     * recorded before that posting existed (no journals) still show as a
     * branch reallocation difference.
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

        // Draft assets are not in the ledger yet and are never compared.
        $assets = FixedAsset::query()
            ->with(['category', 'depreciationItems', 'transfers'])
            ->where('status', '!=', FixedAsset::STATUS_DRAFT)
            ->when($date !== null, fn ($query) => $query->whereDate('capitalization_date', '<=', $date))
            ->when($date !== null, fn ($query) => $query->where(function ($query) use ($date) {
                $query->whereNull('disposed_on')->orWhereDate('disposed_on', '>', $date);
            }))
            ->when($date === null, fn ($query) => $query->whereNull('disposed_on'))
            ->get()
            // A branch figure counts the assets the ledger had on that branch at
            // the date: inter-branch transfers move cost and accumulated
            // depreciation between branches in the GL, so the register follows.
            ->filter(fn (FixedAsset $asset) => $branch === null || $this->branchIdAt($asset, $date) === $branch->id);

        $registerCost = [];
        $registerAccumulated = [];

        foreach ($assets as $asset) {
            $categoryId = $asset->fixed_asset_category_id;
            $registerCost[$categoryId] = ($registerCost[$categoryId] ?? 0) + $asset->acquisition_value_base_minor;
            // As of a date the register only counts what the ledger had by then.
            $registerAccumulated[$categoryId] = ($registerAccumulated[$categoryId] ?? 0) + ($date !== null
                ? $this->accumulatedAsOf($asset, $this->beforePeriodFor($date), $date)
                : $asset->totalAccumulatedMinor());
        }

        // Categories may share GL accounts (the defaults do), so the control
        // comparison is made once per distinct account, never per category.
        $accountRows = [];
        foreach ($categories as $category) {
            $pairs = [
                ['cost', $category->assetAccount, false, $registerCost],
                ['accumulated', $category->accumulatedDepreciationAccount, true, $registerAccumulated],
            ];
            foreach ($pairs as [$role, $account, $creditNormal, $register]) {
                if (! $account) {
                    continue;
                }
                $key = $role.':'.$account->id;
                $accountRows[$key] ??= [
                    'role' => $role,
                    'account' => $account,
                    'credit_normal' => $creditNormal,
                    'register_minor' => 0,
                    'category_codes' => [],
                ];
                $accountRows[$key]['register_minor'] += $register[$category->id] ?? 0;
                $accountRows[$key]['category_codes'][] = $category->code;
            }
        }

        $accounts = [];
        $totalCostDifference = 0;
        $totalAccumulatedDifference = 0;
        $byAccount = [];

        foreach ($accountRows as $key => $row) {
            $gl = $this->glBalance($row['account']->id, $branch, $date, $row['credit_normal']);
            $difference = $row['register_minor'] - $gl;
            $byAccount[$key] = ['gl' => $gl, 'difference' => $difference, 'shared' => count($row['category_codes']) > 1];

            if ($row['role'] === 'cost') {
                $totalCostDifference += $difference;
            } else {
                $totalAccumulatedDifference += $difference;
            }

            $accounts[] = [
                'role' => $row['role'],
                'account' => $row['account']->toApiArray(),
                'category_codes' => $row['category_codes'],
                'register_minor' => $row['register_minor'],
                'gl_minor' => $gl,
                'difference_minor' => $difference,
                'is_reconciled' => $difference === 0,
                'is_classified_fixed_asset' => $row['account']->classification === 'fixed_asset',
            ];
        }

        // Ledger balances on fixed-asset accounts that no category maps to
        // (a bill or opening balance booked straight to a cost account, party-less
        // lines included) have no register counterpart: show them as a difference.
        $mappedIds = array_values(array_unique(array_map(fn (array $row) => $row['account']->id, $accountRows)));
        $unregistered = [];
        $candidates = Account::query()
            ->where('classification', 'fixed_asset')
            ->where('type', 'asset')
            ->whereNotIn('id', $mappedIds)
            ->orderBy('code')
            ->get();
        foreach ($candidates as $account) {
            $gl = $this->glBalance($account->id, $branch, $date, false);
            if ($gl === 0) {
                continue;
            }
            $totalCostDifference -= $gl;
            $unregistered[] = [
                'account' => $account->toApiArray(),
                'gl_minor' => $gl,
                'difference_minor' => -$gl,
            ];
        }

        $rows = [];
        foreach ($categories as $category) {
            $cost = $category->assetAccount ? $byAccount['cost:'.$category->assetAccount->id] : null;
            $acc = $category->accumulatedDepreciationAccount ? $byAccount['accumulated:'.$category->accumulatedDepreciationAccount->id] : null;

            // A shared account has one ledger balance for several categories:
            // those figures stay null on the row and live in `accounts`.
            $rows[] = [
                'category_id' => $category->public_id,
                'category_code' => $category->code,
                'category_name' => $category->name_en,
                'asset_account' => $category->assetAccount?->toApiArray(),
                'accumulated_depreciation_account' => $category->accumulatedDepreciationAccount?->toApiArray(),
                'depreciation_expense_account' => $category->depreciationExpenseAccount?->toApiArray(),
                'register_cost_minor' => $registerCost[$category->id] ?? 0,
                'gl_cost_minor' => $cost && ! $cost['shared'] ? $cost['gl'] : null,
                'cost_difference_minor' => $cost && ! $cost['shared'] ? $cost['difference'] : null,
                'register_accumulated_minor' => $registerAccumulated[$category->id] ?? 0,
                'gl_accumulated_minor' => $acc && ! $acc['shared'] ? $acc['gl'] : null,
                'accumulated_difference_minor' => $acc && ! $acc['shared'] ? $acc['difference'] : null,
                'shared_accounts' => ($cost['shared'] ?? false) || ($acc['shared'] ?? false),
                'is_reconciled' => ($cost['difference'] ?? 0) === 0 && ($acc['difference'] ?? 0) === 0,
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
                ? 'Branch figures follow inter-branch transfer journals; only transfers recorded before those journals existed can leave a branch reallocation difference.'
                : 'Register and GL control accounts must agree at installation level.',
            'categories' => $rows,
            'accounts' => $accounts,
            'unregistered_accounts' => $unregistered,
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

        // Depreciation charged inside the disposal entry counts from the disposal date.
        $atDisposal = $asset->disposed_on !== null && ($asOfDate === null || $asset->disposed_on->toDateString() <= $asOfDate)
            ? $asset->disposal_depreciation_minor
            : 0;

        return (int) $depreciation + $atDisposal + $this->impairmentAsOf($asset, $asOfDate);
    }

    /**
     * Items whose month-end run date is on or before the date: a mid-month
     * date does not yet include that month's depreciation (the GL doesn't).
     */
    private function beforePeriodFor(string $date): string
    {
        $day = Carbon::parse($date);

        return $day->isSameDay($day->copy()->endOfMonth())
            ? $this->nextPeriod($day->format('Y-m'))
            : $day->format('Y-m');
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
        // The disposal entry removes everything accumulated to the disposal
        // date (posted months, charge to date and applied impairments).
        return $asset->totalAccumulatedMinor();
    }

    private function depreciationInRange(FixedAsset $asset, string $fromPeriod, string $toPeriod): int
    {
        $items = $asset->relationLoaded('depreciationItems')
            ? $asset->depreciationItems
            : $asset->depreciationItems()->get();

        $inDisposalMonth = $asset->disposed_on !== null
            && $asset->disposed_on->format('Y-m') >= $fromPeriod
            && $asset->disposed_on->format('Y-m') <= $toPeriod
                ? $asset->disposal_depreciation_minor
                : 0;

        return $inDisposalMonth + (int) $items
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

    /**
     * The branch the ledger held the asset on at $date: the origin of the first
     * posted (journal-backed, not reversed) transfer after that date.
     */
    private function branchIdAt(FixedAsset $asset, ?string $date): int
    {
        if ($date === null) {
            return $asset->branch_id;
        }

        $later = $asset->transfers
            ->filter(fn ($transfer) => $transfer->source_journal_entry_id !== null
                && $transfer->reversed_at === null
                && $transfer->transfer_date->toDateString() > $date)
            ->sortBy(fn ($transfer) => $transfer->transfer_date->toDateString().'#'.str_pad((string) $transfer->id, 12, '0', STR_PAD_LEFT))
            ->first();

        return $later ? $later->from_branch_id : $asset->branch_id;
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
