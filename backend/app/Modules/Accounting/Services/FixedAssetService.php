<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\FixedAssetDepreciationItem;
use App\Modules\Accounting\Models\FixedAssetDepreciationRun;
use App\Modules\Accounting\Models\FixedAssetTransfer;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\RoundingPolicy;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Support\CodeGenerator;
use App\Support\Money;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fixed-asset register and straight-line depreciation subledger (Prompt 25).
 *
 * All GL effects go through PostingService. Depreciation is calculated in
 * base-currency minor units with an explicit convention, a final-period
 * correction and a hard per-asset/period uniqueness guard. Nothing in this
 * service edits a posted journal. Accounting policies are read from the
 * `assets` settings group (DEC-039, provisional until sign-off).
 */
class FixedAssetService
{
    public const SOURCE_TYPE = 'fixed_asset';

    public const RUN_SOURCE_TYPE = 'fixed_asset_depreciation_run';

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountingDocumentService $documents,
        private readonly SettingService $settings,
        private readonly RoundingPolicy $rounding,
        private readonly SequenceService $sequences,
        private readonly FxService $fx,
        private readonly AuditService $audit,
        private readonly PartyLedgerService $ledger,
        private readonly BranchScope $branchScope,
    ) {}

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function createCategory(array $data, ?int $actorId): FixedAssetCategory
    {
        // A blank code is generated (FAC-001, ...); a supplied one is kept as typed.
        $category = CodeGenerator::create((string) ($data['code'] ?? ''), 'fixed_asset_categories', 'code', 'FAC', 3, function (string $code) use ($data, $actorId) {
            $attributes = $this->categoryAttributes(['code' => $code] + $data);

            if (FixedAssetCategory::query()->where('code', $attributes['code'])->exists()) {
                throw new DomainException('A category with this code already exists.');
            }

            $category = FixedAssetCategory::query()->create($attributes + ['created_by' => $actorId]);

            $this->audit->log('fixed_asset_category.created', $category, null, [
                'code' => $category->code,
            ], $actorId);

            return $category;
        });

        return $category->refresh()->load(
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'disposalGainAccount',
            'disposalLossAccount',
        );
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(FixedAssetCategory $category, array $data, ?int $actorId): FixedAssetCategory
    {
        $merged = array_merge([
            'code' => $category->code,
            'name_ar' => $category->name_ar,
            'name_en' => $category->name_en,
            'asset_account_code' => $category->assetAccount?->code,
            'accumulated_depreciation_account_code' => $category->accumulatedDepreciationAccount?->code,
            'depreciation_expense_account_code' => $category->depreciationExpenseAccount?->code,
            'disposal_gain_account_code' => $category->disposalGainAccount?->code,
            'disposal_loss_account_code' => $category->disposalLossAccount?->code,
            'default_useful_life_months' => $category->default_useful_life_months,
            'default_residual_value_minor' => $category->default_residual_value_minor,
            'is_active' => $category->is_active,
        ], $data);

        $category->update($this->categoryAttributes($merged));

        $this->audit->log('fixed_asset_category.updated', $category, null, [
            'code' => $category->code,
        ], $actorId);

        return $category->refresh()->load(
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'disposalGainAccount',
            'disposalLossAccount',
        );
    }

    // ------------------------------------------------------------------
    // Register
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function createAsset(array $data, Branch $branch, ?int $actorId): FixedAsset
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $attributes = $this->assetAttributes($data, $branch);

            if (isset($data['code']) && trim((string) $data['code']) !== ''
                && FixedAsset::query()->where('code', trim((string) $data['code']))->exists()) {
                throw new DomainException('An asset with this code already exists.');
            }

            $asset = FixedAsset::query()->create($attributes + [
                'code' => isset($data['code']) && trim((string) $data['code']) !== ''
                    ? trim((string) $data['code'])
                    : $this->sequences->next('asset', $branch, Carbon::parse($attributes['capitalization_date'])),
                'branch_id' => $branch->id,
                'status' => FixedAsset::STATUS_DRAFT,
                'created_by' => $actorId,
            ]);

            $this->audit->log('fixed_asset.created', $asset, null, [
                'code' => $asset->code,
                'value_minor' => $asset->acquisition_value_minor,
                'currency' => $asset->currency_code,
            ], $actorId);

            return $this->load($asset);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateAsset(FixedAsset $asset, array $data, ?int $actorId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $actorId) {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status !== FixedAsset::STATUS_DRAFT) {
                throw new DomainException('Only draft assets can be edited. Posted capitalization is immutable.');
            }

            $editable = $this->editableAsset($asset);
            // A rate snapshotted for the old currency must not be reused for a new one.
            if (isset($data['currency']) && strtoupper((string) $data['currency']) !== $asset->currency_code && ! isset($data['fx_rate'])) {
                unset($editable['fx_rate']);
            }
            $merged = array_merge($editable, $data);
            $asset->update($this->assetAttributes($merged, $asset->branch, $asset->id));

            $this->audit->log('fixed_asset.updated', $asset, null, ['code' => $asset->code], $actorId);

            return $this->load($asset);
        });
    }

    /**
     * Capitalization: the approval step that records the asset in the ledger.
     * The entry is posted in base currency using the snapshotted FX rate.
     */
    public function capitalize(FixedAsset $asset, ?int $actorId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actorId) {
            $asset = FixedAsset::query()->with('branch', 'category')->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status !== FixedAsset::STATUS_DRAFT) {
                throw new DomainException('Only draft assets can be capitalized.');
            }

            $threshold = $this->settings->int('assets', 'capitalization_threshold_minor', 0);
            if ($threshold > 0 && $asset->acquisition_value_base_minor < $threshold) {
                throw new DomainException('This asset is below the capitalization threshold configured for the installation.');
            }

            $existing = JournalEntry::query()
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $asset->id)
                ->where('event', 'capitalization')
                ->first();

            if (! $existing && $asset->acquisition_journal_entry_id) {
                // The cost is already in the ledger (supplier bill, expense or
                // opening balance): link it and never recognise it a second time.
                $existing = $this->assertAcquisitionEntry($asset, $asset->category->assetAccount);
            } elseif (! $existing) {
                $assetAccount = $asset->category->assetAccount;
                $offsetAccount = $this->posting->account(
                    (string) $this->settings->get('assets', 'acquisition_offset_account_code', '2100'),
                );

                if (! $assetAccount) {
                    throw new DomainException('The asset category has no cost account configured.');
                }
                if ($assetAccount->id === $offsetAccount->id) {
                    throw new DomainException('The acquisition offset account must differ from the asset cost account.');
                }

                $base = $this->settings->baseCurrency();
                $description = 'Capitalize '.$asset->code;

                // ADR-0015: a payable to a known supplier lives on that
                // supplier's own subaccount, like every other payable.
                $partyType = null;
                $partyId = null;
                $creditAccount = $offsetAccount;
                $apCode = (string) $this->settings->get('accounting', 'ap_account_code', '2100');
                if ($asset->supplier_id !== null && $offsetAccount->code === $apCode) {
                    $creditAccount = $this->ledger->payable($asset->supplier_id);
                    $partyType = 'supplier';
                    $partyId = $asset->supplier_id;
                }

                // Cost is a non-monetary item kept at the historical base
                // amount. The offset is monetary and keeps the acquisition
                // currency, so settlement (realized FX) and revaluation work.
                $credit = $asset->currency_code === $base
                    ? PostingLine::credit($creditAccount, $asset->acquisition_value_base_minor, $partyType, $partyId, $description)
                    : PostingLine::credit($creditAccount, $asset->acquisition_value_minor, $partyType, $partyId, $description)
                        ->in($asset->currency_code, (string) $asset->fx_rate);

                $existing = $this->posting->post(PostingRequest::make(
                    entryDate: $asset->capitalization_date->toDateString(),
                    branch: $asset->branch,
                    currency: $base,
                    lines: [
                        PostingLine::debit($assetAccount, $asset->acquisition_value_base_minor, null, null, $description),
                        $credit,
                    ],
                    memo: Str::limit('Capitalization '.$asset->code.' · '.$asset->description, 250, ''),
                    sourceType: self::SOURCE_TYPE,
                    sourceId: $asset->id,
                    event: 'capitalization',
                    actorId: $actorId,
                ));
            }

            $asset->update([
                'status' => FixedAsset::STATUS_ACTIVE,
                'capitalization_journal_entry_id' => $existing->id,
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);

            $this->audit->log('fixed_asset.capitalized', $asset, ['status' => FixedAsset::STATUS_DRAFT], [
                'status' => FixedAsset::STATUS_ACTIVE,
                'journal' => $existing->number,
            ], $actorId);

            return $this->load($asset);
        });
    }

    // ------------------------------------------------------------------
    // Depreciation runs
    // ------------------------------------------------------------------

    /**
     * Create a draft depreciation run (preview persisted for approval) for one
     * branch and month. Assets already claimed by any item for that period are
     * skipped, so a second run can never post the same asset-month twice.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDepreciationRun(array $data, Branch $branch, ?int $actorId): FixedAssetDepreciationRun
    {
        $period = $this->assertPeriod((string) ($data['period'] ?? ''));
        $runDate = $this->periodEnd($period);

        return DB::transaction(function () use ($data, $branch, $period, $runDate, $actorId) {
            $run = FixedAssetDepreciationRun::query()->create([
                'number' => $this->sequences->next('depreciation_run', $branch, $runDate),
                'branch_id' => $branch->id,
                'period' => $period,
                'run_date' => $runDate->toDateString(),
                'status' => FixedAssetDepreciationRun::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $assets = FixedAsset::query()
                ->with('category')
                ->where('branch_id', $branch->id)
                ->where('status', FixedAsset::STATUS_ACTIVE)
                ->orderBy('code')
                ->lockForUpdate()
                ->get();

            $total = 0;
            $count = 0;

            foreach ($assets as $asset) {
                if (FixedAssetDepreciationItem::query()
                    ->where('fixed_asset_id', $asset->id)
                    ->where('period', $period)
                    ->exists()) {
                    continue;
                }

                $calculation = $this->amountForPeriod($asset, $period);
                if (! $calculation || $calculation['amount'] <= 0) {
                    continue;
                }

                $before = $asset->accumulated_depreciation_minor;
                $after = $before + $calculation['amount'];
                $carryingBefore = $asset->carryingValueMinor();
                $carryingAfter = max(0, $carryingBefore - $calculation['amount']);

                $run->items()->create([
                    'fixed_asset_id' => $asset->id,
                    'period' => $period,
                    'amount_minor' => $calculation['amount'],
                    'accumulated_before_minor' => $before,
                    'accumulated_after_minor' => $after,
                    'carrying_before_minor' => $carryingBefore,
                    'carrying_after_minor' => $carryingAfter,
                    'status' => FixedAssetDepreciationItem::STATUS_PENDING,
                ]);

                $total += $calculation['amount'];
                $count++;
            }

            $run->update([
                'assets_count' => $count,
                'total_amount_minor' => $total,
            ]);

            $this->audit->log('fixed_asset.depreciation_run.created', $run, null, [
                'period' => $period,
                'assets' => $count,
                'total_minor' => $total,
            ], $actorId);

            return $this->loadRun($run);
        });
    }

    /**
     * Read-only preview for the UI: no run or item is stored.
     *
     * @return array<string, mixed>
     */
    public function previewDepreciationRun(Branch $branch, string $period): array
    {
        $period = $this->assertPeriod($period);

        $rows = [];
        $total = 0;

        $assets = FixedAsset::query()
            ->with('category')
            ->where('branch_id', $branch->id)
            ->where('status', FixedAsset::STATUS_ACTIVE)
            ->orderBy('code')
            ->get();

        foreach ($assets as $asset) {
            if (FixedAssetDepreciationItem::query()
                ->where('fixed_asset_id', $asset->id)
                ->where('period', $period)
                ->exists()) {
                continue;
            }

            $calculation = $this->amountForPeriod($asset, $period);
            if (! $calculation || $calculation['amount'] <= 0) {
                continue;
            }

            $total += $calculation['amount'];
            $rows[] = [
                'asset_id' => $asset->public_id,
                'asset_code' => $asset->code,
                'asset_description' => $asset->description,
                'category' => $asset->category?->code,
                'amount_minor' => $calculation['amount'],
                'accumulated_before_minor' => $asset->accumulated_depreciation_minor,
                'accumulated_after_minor' => $asset->accumulated_depreciation_minor + $calculation['amount'],
                'carrying_before_minor' => $asset->carryingValueMinor(),
                'carrying_after_minor' => max(0, $asset->carryingValueMinor() - $calculation['amount']),
            ];
        }

        return [
            'period' => $period,
            'run_date' => $this->periodEnd($period)->toDateString(),
            'currency' => $this->settings->baseCurrency(),
            'assets_count' => count($rows),
            'total_amount_minor' => $total,
            'items' => $rows,
        ];
    }

    /**
     * Post (and retry) a depreciation run. A closed period refuses the whole
     * run and leaves it in the failed state so it can be re-run after reopening.
     */
    public function postDepreciationRun(FixedAssetDepreciationRun $run, ?int $actorId): FixedAssetDepreciationRun
    {
        if ($run->status === FixedAssetDepreciationRun::STATUS_REVERSED) {
            throw new DomainException('A reversed depreciation run cannot be posted again; create a new run for the period.');
        }

        try {
            return DB::transaction(function () use ($run, $actorId) {
                $run = FixedAssetDepreciationRun::query()->with('branch')->lockForUpdate()->findOrFail($run->id);

                if ($run->status === FixedAssetDepreciationRun::STATUS_POSTED) {
                    return $this->loadRun($run);
                }

                $items = $run->items()->with('asset.category')->get();
                if ($items->isEmpty()) {
                    throw new DomainException('This run has no assets to depreciate.');
                }

                $lines = [];
                $total = 0;
                $postable = [];

                foreach ($items as $item) {
                    $asset = FixedAsset::query()->with('category')->lockForUpdate()->find($item->fixed_asset_id);

                    // An asset transferred to another branch after the draft was
                    // created belongs to that branch's run, not to this journal.
                    if (! $asset || ! $asset->isOpen() || $asset->branch_id !== $run->branch_id) {
                        $item->delete();

                        continue;
                    }

                    $calculation = $this->amountForPeriod($asset, $run->period);
                    if (! $calculation || $calculation['amount'] <= 0) {
                        $item->delete();

                        continue;
                    }

                    $amount = $calculation['amount'];
                    $before = $asset->accumulated_depreciation_minor;
                    $carryingBefore = $asset->carryingValueMinor();

                    $item->update([
                        'amount_minor' => $amount,
                        'accumulated_before_minor' => $before,
                        'accumulated_after_minor' => $before + $amount,
                        'carrying_before_minor' => $carryingBefore,
                        'carrying_after_minor' => max(0, $carryingBefore - $amount),
                    ]);

                    $expense = $asset->category?->depreciationExpenseAccount;
                    $accumulated = $asset->category?->accumulatedDepreciationAccount;
                    if (! $expense || ! $accumulated) {
                        throw new DomainException("Asset {$asset->code} has no depreciation accounts configured.");
                    }

                    $description = Str::limit($asset->code.' · '.$run->period, 250, '');
                    $lines[] = PostingLine::debit($expense, $amount, null, null, $description);
                    $lines[] = PostingLine::credit($accumulated, $amount, null, null, $description);

                    $total += $amount;
                    $postable[] = [$item, $asset, $amount];
                }

                if ($postable === [] || $total <= 0) {
                    throw new DomainException('No depreciation is due for this period.');
                }

                $existing = JournalEntry::query()
                    ->where('source_type', self::RUN_SOURCE_TYPE)
                    ->where('source_id', $run->id)
                    ->where('event', 'posted')
                    ->first();

                $entry = $existing ?? $this->posting->post(PostingRequest::make(
                    entryDate: $run->run_date->toDateString(),
                    branch: $run->branch,
                    currency: $this->settings->baseCurrency(),
                    lines: $lines,
                    memo: Str::limit('Depreciation run '.$run->number.' · '.$run->period, 250, ''),
                    sourceType: self::RUN_SOURCE_TYPE,
                    sourceId: $run->id,
                    event: 'posted',
                    actorId: $actorId,
                ));

                foreach ($postable as [$item, $asset, $amount]) {
                    $item->update([
                        'status' => FixedAssetDepreciationItem::STATUS_POSTED,
                        'failure_reason' => null,
                    ]);

                    $remainingAfter = $asset->remainingDepreciableMinor() - $amount;

                    $asset->update([
                        'accumulated_depreciation_minor' => $asset->accumulated_depreciation_minor + $amount,
                        'last_depreciated_period' => $run->period,
                        'status' => $remainingAfter <= 0
                            ? FixedAsset::STATUS_FULLY_DEPRECIATED
                            : FixedAsset::STATUS_ACTIVE,
                    ]);
                }

                $run->update([
                    'status' => FixedAssetDepreciationRun::STATUS_POSTED,
                    'assets_count' => count($postable),
                    'total_amount_minor' => $total,
                    'journal_entry_id' => $entry->id,
                    'failure_reason' => null,
                    'approved_by' => $actorId,
                    'approved_at' => now(),
                    'posted_at' => now(),
                ]);

                $this->audit->log('fixed_asset.depreciation_run.posted', $run, null, [
                    'period' => $run->period,
                    'assets' => count($postable),
                    'total_minor' => $total,
                    'journal' => $entry->number,
                ], $actorId);

                return $this->loadRun($run);
            });
        } catch (DomainException|AuthorizationException $exception) {
            FixedAssetDepreciationRun::query()
                ->whereKey($run->id)
                ->update([
                    'status' => FixedAssetDepreciationRun::STATUS_FAILED,
                    'failure_reason' => Str::limit($exception->getMessage(), 250, ''),
                ]);

            FixedAssetDepreciationItem::query()
                ->where('fixed_asset_depreciation_run_id', $run->id)
                ->where('status', FixedAssetDepreciationItem::STATUS_PENDING)
                ->update([
                    'status' => FixedAssetDepreciationItem::STATUS_FAILED,
                    'failure_reason' => Str::limit($exception->getMessage(), 250, ''),
                ]);

            throw $exception;
        } catch (Throwable $exception) {
            FixedAssetDepreciationRun::query()
                ->whereKey($run->id)
                ->update([
                    'status' => FixedAssetDepreciationRun::STATUS_FAILED,
                    'failure_reason' => Str::limit($exception->getMessage(), 250, ''),
                ]);

            throw $exception;
        }
    }

    public function deleteDepreciationRun(FixedAssetDepreciationRun $run, ?int $actorId): void
    {
        DB::transaction(function () use ($run, $actorId) {
            $run = FixedAssetDepreciationRun::query()->lockForUpdate()->findOrFail($run->id);

            if (in_array($run->status, [FixedAssetDepreciationRun::STATUS_POSTED, FixedAssetDepreciationRun::STATUS_REVERSED], true)) {
                throw new DomainException('A posted depreciation run cannot be deleted; reverse it instead.');
            }

            $run->items()->delete();
            $this->audit->log('fixed_asset.depreciation_run.deleted', $run, [
                'number' => $run->number,
                'period' => $run->period,
            ], null, $actorId);
            $run->delete();
        });
    }

    /**
     * Correct a posted run. The journal is reversed through PostingService
     * (dated today when its period is closed, ADR-0004), the register is
     * rolled back and the asset-month claims are released so the period can
     * be run again. Only the latest posted period of each asset can be undone.
     */
    public function reverseDepreciationRun(FixedAssetDepreciationRun $run, string $reason, ?int $actorId): FixedAssetDepreciationRun
    {
        return DB::transaction(function () use ($run, $reason, $actorId) {
            $run = FixedAssetDepreciationRun::query()->with('journalEntry')->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== FixedAssetDepreciationRun::STATUS_POSTED || ! $run->journalEntry) {
                throw new DomainException('Only a posted depreciation run can be reversed.');
            }
            if (trim($reason) === '') {
                throw new DomainException('A reversal reason is required.');
            }

            $items = $run->items()->get();
            $assets = [];
            foreach ($items as $item) {
                $asset = FixedAsset::query()->lockForUpdate()->findOrFail($item->fixed_asset_id);

                if ($asset->status === FixedAsset::STATUS_DISPOSED) {
                    throw new DomainException("Asset {$asset->code} has been disposed; this run can no longer be reversed.");
                }
                if ($asset->last_depreciated_period !== $run->period) {
                    throw new DomainException("Asset {$asset->code} was depreciated in a later period; reverse the later run first.");
                }
                $assets[$item->id] = $asset;
            }

            $this->posting->reverse($run->journalEntry, $reason, $actorId);

            foreach ($items as $item) {
                $asset = $assets[$item->id];
                $previous = FixedAssetDepreciationItem::query()
                    ->where('fixed_asset_id', $asset->id)
                    ->where('status', FixedAssetDepreciationItem::STATUS_POSTED)
                    ->where('id', '!=', $item->id)
                    ->max('period');

                $asset->update([
                    'accumulated_depreciation_minor' => max(0, $asset->accumulated_depreciation_minor - $item->amount_minor),
                    'last_depreciated_period' => $previous,
                    'status' => FixedAsset::STATUS_ACTIVE,
                ]);
                $item->delete();
            }

            $run->update(['status' => FixedAssetDepreciationRun::STATUS_REVERSED, 'failure_reason' => Str::limit($reason, 250, '')]);

            $this->audit->log('fixed_asset.depreciation_run.reversed', $run, ['status' => FixedAssetDepreciationRun::STATUS_POSTED], [
                'status' => FixedAssetDepreciationRun::STATUS_REVERSED,
                'reason' => $reason,
                'assets' => $items->count(),
                'total_minor' => $run->total_amount_minor,
            ], $actorId);

            return $this->loadRun($run);
        });
    }

    // ------------------------------------------------------------------
    // Transfers
    // ------------------------------------------------------------------

    public const TRANSFER_SOURCE_TYPE = 'fixed_asset_transfer';

    /**
     * Transfer an asset to another branch, cost center or custodian.
     *
     * A change of branch posts two balanced journals at net book value, one in
     * each branch, through the inter-branch clearing accounts (Odoo-style):
     *   source branch:      Dr accumulated depreciation, Dr inter-branch receivable (NBV), Cr cost
     *   destination branch: Dr cost, Cr accumulated depreciation, Cr inter-branch payable (NBV)
     * The two clearing balances net to zero installation-wide. Amounts are the
     * register's base-currency figures, so foreign-currency assets move at
     * their historical base cost. Cost-center / custodian changes inside the
     * same branch post nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function transfer(FixedAsset $asset, array $data, ?int $actorId): FixedAssetTransfer
    {
        return DB::transaction(function () use ($asset, $data, $actorId) {
            $asset = FixedAsset::query()->with('branch', 'category.assetAccount', 'category.accumulatedDepreciationAccount')
                ->lockForUpdate()->findOrFail($asset->id);

            if (! $asset->isOpen()) {
                throw new DomainException('Only active or fully depreciated assets can be transferred.');
            }

            $toBranch = isset($data['to_branch_id']) && $data['to_branch_id'] !== ''
                ? Branch::query()->where('public_id', $data['to_branch_id'])->firstOrFail()
                : $asset->branch;

            if (! $toBranch->is_active) {
                throw new DomainException('The destination branch is inactive.');
            }

            $branchChanges = $toBranch->id !== $asset->branch_id;
            if ($branchChanges) {
                // Both sides of an inter-branch move must be in the actor's scope.
                $this->assertActorBranchScope($actorId, $asset->branch);
                $this->assertActorBranchScope($actorId, $toBranch);
            }

            $toCostCenterId = array_key_exists('to_cost_center_id', $data)
                ? $this->resolveCostCenterId($data['to_cost_center_id'])
                : $asset->cost_center_id;

            $toEmployeeId = array_key_exists('to_employee_id', $data)
                ? $this->resolveEmployeeId($data['to_employee_id'])
                : $asset->employee_id;

            if (! $branchChanges
                && $toCostCenterId === $asset->cost_center_id
                && $toEmployeeId === $asset->employee_id) {
                throw new DomainException('The transfer does not change the branch, cost center or custodian.');
            }

            $transferDate = (string) ($data['transfer_date'] ?? now()->toDateString());
            if ($branchChanges && Carbon::parse($transferDate)->lt($asset->capitalization_date)) {
                throw new DomainException('The transfer date cannot be before the capitalization date.');
            }

            $cost = $branchChanges ? (int) $asset->acquisition_value_base_minor : 0;
            $accumulated = $branchChanges ? $asset->totalAccumulatedMinor() : 0;

            $transfer = FixedAssetTransfer::query()->create([
                'fixed_asset_id' => $asset->id,
                'from_branch_id' => $asset->branch_id,
                'to_branch_id' => $toBranch->id,
                'from_cost_center_id' => $asset->cost_center_id,
                'to_cost_center_id' => $toCostCenterId,
                'from_employee_id' => $asset->employee_id,
                'to_employee_id' => $toEmployeeId,
                'transfer_date' => $transferDate,
                'reason' => $data['reason'] ?? null,
                'cost_minor' => $cost,
                'accumulated_minor' => $accumulated,
                'created_by' => $actorId,
            ]);

            $fromBranch = $asset->branch;
            $journals = null;
            if ($branchChanges) {
                $journals = $this->postBranchTransfer($asset, $transfer, $fromBranch, $toBranch, $cost, $accumulated, $transferDate, $actorId);
                $transfer->update([
                    'source_journal_entry_id' => $journals['source']->id,
                    'destination_journal_entry_id' => $journals['destination']->id,
                ]);
            }

            $before = [
                'branch' => $fromBranch?->code,
                'cost_center_id' => $asset->cost_center_id,
                'employee_id' => $asset->employee_id,
            ];

            $asset->update([
                'branch_id' => $toBranch->id,
                'cost_center_id' => $toCostCenterId,
                'employee_id' => $toEmployeeId,
            ]);

            $this->audit->log('fixed_asset.transferred', $asset, $before, [
                'branch' => $toBranch->code,
                'cost_center_id' => $toCostCenterId,
                'employee_id' => $toEmployeeId,
                'transfer' => $transfer->public_id,
                'gl_rewritten' => false,
                'source_journal' => $journals['source']->number ?? null,
                'destination_journal' => $journals['destination']->number ?? null,
                'net_book_value_minor' => $cost - $accumulated,
            ], $actorId);

            return $transfer->refresh()->load(self::TRANSFER_RELATIONS);
        });
    }

    /** @var list<string> */
    private const TRANSFER_RELATIONS = [
        'asset',
        'fromBranch',
        'toBranch',
        'fromCostCenter',
        'toCostCenter',
        'fromEmployee',
        'toEmployee',
        'sourceJournalEntry',
        'destinationJournalEntry',
    ];

    /**
     * Reverse an inter-branch transfer: both journals are reversed through
     * PostingService and the asset returns to its previous branch, cost center
     * and custodian. Only the latest transfer of an asset can be reversed, and
     * only while nothing was depreciated or impaired since (reverse those first).
     */
    public function reverseTransfer(FixedAssetTransfer $transfer, string $reason, ?int $actorId): FixedAssetTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $actorId) {
            $transfer = FixedAssetTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($transfer->fixed_asset_id);

            if (trim($reason) === '') {
                throw new DomainException('A reversal reason is required.');
            }
            if ($transfer->isReversed()) {
                throw new DomainException('This transfer has already been reversed.');
            }
            if ($transfer->source_journal_entry_id === null || $transfer->destination_journal_entry_id === null) {
                throw new DomainException('This transfer posted no journals (it predates inter-branch posting or only changed cost center or custodian) and cannot be reversed.');
            }
            if ($asset->status === FixedAsset::STATUS_DISPOSED) {
                throw new DomainException('The asset has been disposed; the transfer can no longer be reversed.');
            }
            $later = FixedAssetTransfer::query()
                ->where('fixed_asset_id', $asset->id)
                ->where('id', '>', $transfer->id)
                ->whereNull('reversed_at')
                ->exists();
            if ($later || $asset->branch_id !== $transfer->to_branch_id) {
                throw new DomainException('Only the latest transfer of an asset can be reversed.');
            }
            if ($asset->totalAccumulatedMinor() !== (int) $transfer->accumulated_minor) {
                throw new DomainException('The asset was depreciated or impaired after the transfer; reverse those entries first.');
            }

            $source = JournalEntry::query()->findOrFail($transfer->source_journal_entry_id);
            $destination = JournalEntry::query()->findOrFail($transfer->destination_journal_entry_id);
            $this->assertActorBranchScope($actorId, $source->branch);
            $this->assertActorBranchScope($actorId, $destination->branch);

            $this->posting->reverse($source, $reason, $actorId);
            $this->posting->reverse($destination, $reason, $actorId);

            $asset->update([
                'branch_id' => $transfer->from_branch_id,
                'cost_center_id' => $transfer->from_cost_center_id,
                'employee_id' => $transfer->from_employee_id,
            ]);
            $transfer->update([
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => Str::limit($reason, 250, ''),
            ]);

            $this->audit->log('fixed_asset.transfer_reversed', $asset, ['branch_id' => $transfer->to_branch_id], [
                'branch_id' => $transfer->from_branch_id,
                'transfer' => $transfer->public_id,
                'reason' => $reason,
            ], $actorId);

            return $transfer->refresh()->load(self::TRANSFER_RELATIONS);
        });
    }

    /**
     * Post the two linked journals of a branch transfer. Each is balanced on
     * its own; they share the source (the transfer) and carry the event keys
     * `transfer_out` / `transfer_in` (the unique key is source + event).
     *
     * @return array{source: JournalEntry, destination: JournalEntry}
     */
    private function postBranchTransfer(
        FixedAsset $asset,
        FixedAssetTransfer $transfer,
        Branch $from,
        Branch $to,
        int $cost,
        int $accumulated,
        string $date,
        ?int $actorId,
    ): array {
        $category = $asset->category;
        $costAccount = $category->assetAccount;
        $accumulatedAccount = $category->accumulatedDepreciationAccount;
        if (! $costAccount || ! $accumulatedAccount) {
            throw new DomainException('The asset category needs cost and accumulated depreciation accounts to post a branch transfer.');
        }

        $receivable = $this->posting->account((string) $this->settings->get('accounting', 'interbranch_receivable_account_code', '1180'));
        $payable = $this->posting->account((string) $this->settings->get('accounting', 'interbranch_payable_account_code', '2160'));
        $nbv = $cost - $accumulated;
        $base = $this->settings->baseCurrency();
        $label = $asset->code.' '.$from->code.' -> '.$to->code;

        // Source: remove cost and accumulated depreciation, book the NBV due from the destination.
        $sourceLines = [PostingLine::credit($costAccount, $cost, null, null, 'Transfer out '.$label)];
        if ($accumulated > 0) {
            $sourceLines[] = PostingLine::debit($accumulatedAccount, $accumulated, null, null, 'Transfer out '.$label);
        }
        if ($nbv > 0) {
            $sourceLines[] = PostingLine::debit($receivable, $nbv, null, null, 'Due from '.$to->code.' for '.$asset->code);
        } elseif ($nbv < 0) {
            $sourceLines[] = PostingLine::credit($receivable, -$nbv, null, null, 'Due from '.$to->code.' for '.$asset->code);
        }

        // Destination: mirror image, NBV due to the source.
        $destinationLines = [PostingLine::debit($costAccount, $cost, null, null, 'Transfer in '.$label)];
        if ($accumulated > 0) {
            $destinationLines[] = PostingLine::credit($accumulatedAccount, $accumulated, null, null, 'Transfer in '.$label);
        }
        if ($nbv > 0) {
            $destinationLines[] = PostingLine::credit($payable, $nbv, null, null, 'Due to '.$from->code.' for '.$asset->code);
        } elseif ($nbv < 0) {
            $destinationLines[] = PostingLine::debit($payable, -$nbv, null, null, 'Due to '.$from->code.' for '.$asset->code);
        }

        $memo = Str::limit('Inter-branch transfer '.$label.' · '.$asset->description, 250, '');
        $make = fn (Branch $branch, array $lines, string $event) => PostingRequest::make(
            entryDate: $date,
            branch: $branch,
            currency: $base,
            lines: $lines,
            memo: $memo,
            sourceType: self::TRANSFER_SOURCE_TYPE,
            sourceId: $transfer->id,
            event: $event,
            actorId: $actorId,
        );

        return [
            'source' => $this->posting->post($make($from, $sourceLines, 'transfer_out')),
            'destination' => $this->posting->post($make($to, $destinationLines, 'transfer_in')),
        ];
    }

    private function assertActorBranchScope(?int $actorId, Branch $branch): void
    {
        if ($actorId === null) {
            return;
        }
        $user = User::query()->find($actorId);
        if ($user) {
            $this->branchScope->assertBranch($user, $branch);
        }
    }

    // ------------------------------------------------------------------
    // Disposal
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function dispose(FixedAsset $asset, array $data, ?int $actorId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $actorId) {
            $asset = FixedAsset::query()->with('branch', 'category')->lockForUpdate()->findOrFail($asset->id);

            if (! $asset->isOpen()) {
                throw new DomainException('Only active or fully depreciated assets can be disposed.');
            }

            $date = Carbon::parse((string) ($data['disposal_date'] ?? now()->toDateString()))->startOfDay();
            if ($date->lt($asset->capitalization_date)) {
                throw new DomainException('The disposal date cannot be before the capitalization date.');
            }

            $type = (string) ($data['disposal_type'] ?? FixedAsset::DISPOSAL_SALE);
            if (! in_array($type, FixedAsset::DISPOSAL_TYPES, true)) {
                throw new DomainException('Choose a valid disposal type.');
            }

            $proceeds = (int) ($data['proceeds_minor'] ?? 0);
            if ($proceeds < 0) {
                throw new DomainException('Disposal proceeds cannot be negative.');
            }

            $category = $asset->category;
            $assetAccount = $category?->assetAccount;
            $accumulatedAccount = $category?->accumulatedDepreciationAccount;
            $gainAccount = $category?->disposalGainAccount
                ?? $this->posting->account((string) $this->settings->get('assets', 'disposal_gain_account_code', '7910'));
            $lossAccount = $category?->disposalLossAccount
                ?? $this->posting->account((string) $this->settings->get('assets', 'disposal_loss_account_code', '6915'));

            if (! $assetAccount || ! $accumulatedAccount) {
                throw new DomainException('The asset category has no cost or accumulated depreciation account configured.');
            }

            $proceedsAccount = $this->posting->account(
                (string) ($data['proceeds_account_code'] ?? $this->settings->get('assets', 'disposal_proceeds_account_code', '1120')),
            );
            if ($proceeds > 0 && ($proceedsAccount->type !== 'asset' || ! $proceedsAccount->is_active || ! $proceedsAccount->is_postable
                || in_array($proceedsAccount->id, [$assetAccount->id, $accumulatedAccount->id], true))) {
                throw new DomainException('The proceeds account must be an active, postable cash, bank or receivable account.');
            }

            // Depreciation runs to the disposal date (Odoo / Zoho). Earlier
            // months must already be posted; the disposal month is charged
            // pro rata inside the disposal entry. A month-end run that is
            // already posted must not be undone by an earlier disposal date.
            $disposalPeriod = $date->format('Y-m');
            if ($asset->last_depreciated_period !== null) {
                if ($date->lt($this->periodEnd($asset->last_depreciated_period))) {
                    throw new DomainException("Depreciation is already posted through {$asset->last_depreciated_period}; the disposal date cannot be earlier than the end of that period.");
                }
                $nextUnposted = Carbon::createFromFormat('!Y-m', $asset->last_depreciated_period)->addMonthNoOverflow()->format('Y-m');
            } else {
                $nextUnposted = $asset->firstDepreciationPeriod();
            }
            if ($nextUnposted < $disposalPeriod && ($this->amountForPeriod($asset, $nextUnposted)['amount'] ?? 0) > 0) {
                throw new DomainException("Post the depreciation run for {$nextUnposted} before disposing this asset.");
            }

            $catchUp = 0;
            if ($nextUnposted <= $disposalPeriod) {
                $full = $this->amountForPeriod($asset, $disposalPeriod)['amount'] ?? 0;
                $catchUp = min(
                    $asset->remainingDepreciableMinor(),
                    Money::divideRounded($full * $date->day, $date->daysInMonth, $this->rounding->mode()),
                );
            }
            $expenseAccount = $category?->depreciationExpenseAccount;
            if ($catchUp > 0 && ! $expenseAccount) {
                throw new DomainException('The asset category has no depreciation expense account configured.');
            }

            $accumulated = $asset->totalAccumulatedMinor() + $catchUp;
            $carrying = max(0, $asset->acquisition_value_base_minor - $accumulated);
            $difference = $proceeds - $carrying;

            $lines = [];
            $description = Str::limit('Disposal '.$asset->code.' · '.$asset->description, 250, '');

            if ($catchUp > 0) {
                $lines[] = PostingLine::debit($expenseAccount, $catchUp, null, null, $description);
                $lines[] = PostingLine::credit($accumulatedAccount, $catchUp, null, null, $description);
            }
            if ($accumulated > 0) {
                $lines[] = PostingLine::debit($accumulatedAccount, $accumulated, null, null, $description);
            }
            if ($proceeds > 0) {
                $lines[] = PostingLine::debit($proceedsAccount, $proceeds, null, null, $description);
            }
            if ($difference < 0) {
                $lines[] = PostingLine::debit($lossAccount, abs($difference), null, null, $description);
            }

            $lines[] = PostingLine::credit($assetAccount, $asset->acquisition_value_base_minor, null, null, $description);

            if ($difference > 0) {
                $lines[] = PostingLine::credit($gainAccount, $difference, null, null, $description);
            }

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date->toDateString(),
                branch: $asset->branch,
                currency: $this->settings->baseCurrency(),
                lines: $lines,
                memo: $description,
                sourceType: self::SOURCE_TYPE,
                sourceId: $asset->id,
                event: 'disposal',
                actorId: $actorId,
            ));

            $asset->update([
                'status' => FixedAsset::STATUS_DISPOSED,
                'disposed_on' => $date->toDateString(),
                'disposal_type' => $type,
                'disposal_proceeds_minor' => $proceeds,
                'disposal_gain_loss_minor' => $difference,
                'disposal_depreciation_minor' => $catchUp,
                'accumulated_depreciation_minor' => $asset->accumulated_depreciation_minor + $catchUp,
                'disposal_journal_entry_id' => $entry->id,
            ]);

            $this->audit->log('fixed_asset.disposed', $asset, null, [
                'type' => $type,
                'proceeds_minor' => $proceeds,
                'carrying_minor' => $carrying,
                'depreciation_to_date_minor' => $catchUp,
                'gain_loss_minor' => $difference,
                'journal' => $entry->number,
            ], $actorId);

            return $this->load($asset);
        });
    }

    // ------------------------------------------------------------------
    // Impairment (only through an approved accounting document)
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function recordImpairment(FixedAsset $asset, array $data, ?int $actorId): AccountingDocument
    {
        return DB::transaction(function () use ($asset, $data, $actorId) {
            $asset = FixedAsset::query()->with('branch', 'category')->lockForUpdate()->findOrFail($asset->id);

            if (! $asset->isOpen()) {
                throw new DomainException('Only active or fully depreciated assets can be impaired.');
            }

            $amount = (int) ($data['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('The impairment amount must be greater than zero.');
            }
            if ($amount > $asset->carryingValueMinor()) {
                throw new DomainException('The impairment amount cannot exceed the carrying value.');
            }

            $accumulated = $asset->category?->accumulatedDepreciationAccount;
            if (! $accumulated) {
                throw new DomainException('The asset category has no accumulated depreciation account configured.');
            }

            $loss = $this->posting->account(
                (string) $this->settings->get('assets', 'impairment_loss_account_code', '6910'),
            );

            $document = $this->documents->create([
                'type' => AccountingDocument::TYPE_ADJUSTMENT_VOUCHER,
                'document_date' => (string) ($data['document_date'] ?? now()->toDateString()),
                'currency' => $this->settings->baseCurrency(),
                'memo' => (string) ($data['memo'] ?? ('Impairment of fixed asset '.$asset->code)),
                'lines' => [
                    ['account_id' => $loss->public_id, 'debit_minor' => $amount, 'description' => 'Impairment '.$asset->code],
                    ['account_id' => $accumulated->public_id, 'credit_minor' => $amount, 'description' => 'Impairment '.$asset->code],
                ],
                'submit' => (bool) ($data['submit'] ?? false),
            ], $asset->branch, $actorId);

            $ids = $asset->impairment_document_ids ?? [];
            $ids[] = $document->public_id;
            $asset->update(['impairment_document_ids' => array_values(array_unique($ids))]);

            $this->audit->log('fixed_asset.impairment_drafted', $asset, null, [
                'document' => $document->public_id,
                'amount_minor' => $amount,
            ], $actorId);

            return $document->load('lines.account');
        });
    }

    /**
     * Apply a posted impairment voucher to the register. The voucher must have
     * gone through the normal document approval and posting workflow first.
     */
    public function applyImpairment(FixedAsset $asset, AccountingDocument $document, ?int $actorId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $document, $actorId) {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $document = AccountingDocument::query()->findOrFail($document->id);

            $linked = $asset->impairment_document_ids ?? [];
            if (! in_array($document->public_id, $linked, true)) {
                throw new DomainException('This voucher is not linked to the asset.');
            }

            $applied = $asset->applied_impairment_document_ids ?? [];
            if (in_array($document->public_id, $applied, true)) {
                throw new DomainException('This impairment voucher has already been applied.');
            }

            if ($document->status !== AccountingDocument::STATUS_POSTED) {
                throw new DomainException('Only a posted impairment voucher can be applied to the register.');
            }

            $amount = (int) $document->total_minor;
            if ($amount > $asset->carryingValueMinor()) {
                throw new DomainException('The impairment amount cannot exceed the current carrying value.');
            }

            $applied[] = $document->public_id;

            $asset->update([
                'impairment_minor' => $asset->impairment_minor + $amount,
                'applied_impairment_document_ids' => array_values(array_unique($applied)),
            ]);

            if ($asset->remainingDepreciableMinor() === 0 && $asset->isOpen()) {
                $asset->update(['status' => FixedAsset::STATUS_FULLY_DEPRECIATED]);
            }

            $this->audit->log('fixed_asset.impairment_applied', $asset, null, [
                'document' => $document->public_id,
                'amount_minor' => $amount,
            ], $actorId);

            return $this->load($asset);
        });
    }

    // ------------------------------------------------------------------
    // Reporting support & loading
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function depreciationSchedule(FixedAsset $asset): array
    {
        $first = Carbon::createFromFormat('!Y-m', $asset->firstDepreciationPeriod())->startOfMonth();
        $depreciable = $asset->depreciableMinor();
        $accumulated = 0;
        $rows = [];
        $life = $asset->useful_life_months;
        $posted = $asset->depreciationItems()->with('run')->get()->keyBy('period');
        $disposed = $asset->status === FixedAsset::STATUS_DISPOSED;

        for ($index = 1; $index <= $life; $index++) {
            $period = $first->copy()->addMonthsNoOverflow($index - 1)->format('Y-m');
            $item = $posted->get($period);

            if ($item !== null && $item->status === FixedAssetDepreciationItem::STATUS_POSTED) {
                // Posted months show what was actually booked (incl. any impairment at the time).
                $accumulated += $item->amount_minor;
                $rows[] = [
                    'period' => $period,
                    'amount_minor' => $item->amount_minor,
                    'accumulated_minor' => $accumulated,
                    'carrying_value_minor' => $item->carrying_after_minor,
                    'status' => $item->status,
                    'run_id' => $item->run?->public_id,
                ];

                continue;
            }

            if ($disposed) {
                break;
            }

            // Projection from the current state: an applied impairment has
            // already lowered the remaining depreciable amount.
            $remaining = max(0, $depreciable - $asset->impairment_minor - $accumulated);
            if ($remaining === 0) {
                break;
            }

            $amount = $this->amountAt($asset, $index, $remaining);
            $accumulated += $amount;

            $rows[] = [
                'period' => $period,
                'amount_minor' => $amount,
                'accumulated_minor' => $accumulated,
                'carrying_value_minor' => max(0, $asset->acquisition_value_base_minor - $accumulated - $asset->impairment_minor),
                'status' => $item?->status ?? 'scheduled',
                'run_id' => $item?->run?->public_id,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function auditTrail(FixedAsset $asset): array
    {
        $events = AuditEvent::query()
            ->where('entity_type', 'FixedAsset')
            ->where('entity_id', $asset->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => (string) $event->id,
                'action' => $event->action,
                'actor_id' => $event->actor_id,
                'before' => $event->before,
                'after' => $event->after,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $transfers = $asset->transfers()
            ->with(self::TRANSFER_RELATIONS)
            ->orderBy('transfer_date')
            ->get()
            ->map(fn (FixedAssetTransfer $transfer) => $transfer->toApiArray())
            ->values()
            ->all();

        return [
            'asset' => $this->load($asset)->toApiArray(),
            'base_currency' => $this->settings->baseCurrency(),
            'events' => $events,
            'transfers' => $transfers,
        ];
    }

    public function load(FixedAsset $asset): FixedAsset
    {
        return $asset->refresh()->load(
            'branch',
            'category.assetAccount',
            'category.accumulatedDepreciationAccount',
            'category.depreciationExpenseAccount',
            'category.disposalGainAccount',
            'category.disposalLossAccount',
            'supplier',
            'costCenter',
            'employee',
            'capitalizationJournalEntry',
            'disposalJournalEntry',
            'acquisitionJournalEntry',
            'transfers',
        );
    }

    public function loadRun(FixedAssetDepreciationRun $run): FixedAssetDepreciationRun
    {
        return $run->refresh()->load(
            'branch',
            'journalEntry',
            'items.asset.branch',
            'items.asset.category',
        );
    }

    // ------------------------------------------------------------------
    // Calculations
    // ------------------------------------------------------------------

    /**
     * Monthly straight-line amount for one asset and period.
     *
     * The final period absorbs the rounding residual so the sum of all posted
     * periods equals cost less residual exactly, and no amount may take the
     * carrying value below the residual (never negative).
     *
     * @return array{index: int, amount: int}|null
     */
    private function amountForPeriod(FixedAsset $asset, string $period): ?array
    {
        $first = $asset->firstDepreciationPeriod();

        if ($period < $first) {
            return null;
        }

        $start = Carbon::createFromFormat('!Y-m', $first)->startOfMonth();
        $current = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        $index = (($current->year - $start->year) * 12) + ($current->month - $start->month) + 1;

        $remaining = $asset->remainingDepreciableMinor();
        if ($remaining <= 0) {
            return null;
        }

        return ['index' => $index, 'amount' => $this->amountAt($asset, $index, $remaining)];
    }

    /**
     * Amount for the index-th month given what is still depreciable.
     *
     * Rounded by the installation rounding policy (DEC-010). The final period
     * (or a catch-up past it) takes the remainder. After an applied
     * impairment the remaining amount is re-spread over the remaining months,
     * as Odoo recomputes the plan after a revaluation, so the schedule never
     * keeps charging the pre-impairment rate.
     */
    private function amountAt(FixedAsset $asset, int $index, int $remaining): int
    {
        if ($remaining <= 0) {
            return 0;
        }

        $life = max(1, $asset->useful_life_months);
        if ($index >= $life) {
            return $remaining;
        }

        $mode = $this->rounding->mode();
        $monthly = $asset->monthlyDepreciationMinor($mode);

        if ($index === 1 && $asset->depreciation_convention === FixedAsset::CONVENTION_PRORATE_DAYS) {
            $daysInMonth = $asset->in_service_date->daysInMonth;
            $activeDays = $daysInMonth - $asset->in_service_date->day + 1;
            $amount = Money::divideRounded($monthly * $activeDays, $daysInMonth, $mode);
        } elseif ($asset->impairment_minor > 0 && $index > 1) {
            $amount = Money::divideRounded($remaining, $life - $index + 1, $mode);
        } else {
            $amount = $monthly;
        }

        return max(0, min(max(1, $amount), $remaining));
    }

    // ------------------------------------------------------------------
    // Input handling
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function categoryAttributes(array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            throw new DomainException('A category code is required.');
        }

        $asset = $this->posting->account((string) ($data['asset_account_code'] ?? $this->settings->get('assets', 'asset_account_code', '1300')));
        $accumulated = $this->posting->account((string) ($data['accumulated_depreciation_account_code'] ?? $this->settings->get('assets', 'accumulated_depreciation_account_code', '1210')));
        $expense = $this->posting->account((string) ($data['depreciation_expense_account_code'] ?? $this->settings->get('assets', 'depreciation_expense_account_code', '6800')));

        if (count(array_unique([$asset->id, $accumulated->id, $expense->id])) < 3) {
            throw new DomainException('Cost, accumulated depreciation and depreciation expense accounts must be different.');
        }
        foreach ([[$asset, 'asset', 'cost'], [$accumulated, 'asset', 'accumulated depreciation'], [$expense, 'expense', 'depreciation expense']] as [$account, $type, $label]) {
            if ($account->type !== $type || ! $account->is_active || ! $account->is_postable) {
                throw new DomainException("The {$label} account {$account->code} must be an active, postable {$type} account.");
            }
        }

        $gainCode = trim((string) ($data['disposal_gain_account_code'] ?? $this->settings->get('assets', 'disposal_gain_account_code', '7910')));
        $lossCode = trim((string) ($data['disposal_loss_account_code'] ?? $this->settings->get('assets', 'disposal_loss_account_code', '6915')));
        $gain = $gainCode !== '' ? $this->posting->account($gainCode) : null;
        $loss = $lossCode !== '' ? $this->posting->account($lossCode) : null;

        return [
            'code' => $code,
            'name_ar' => (string) ($data['name_ar'] ?? $code),
            'name_en' => (string) ($data['name_en'] ?? $code),
            'asset_account_id' => $asset->id,
            'accumulated_depreciation_account_id' => $accumulated->id,
            'depreciation_expense_account_id' => $expense->id,
            'disposal_gain_account_id' => $gain?->id,
            'disposal_loss_account_id' => $loss?->id,
            'default_useful_life_months' => isset($data['default_useful_life_months']) && $data['default_useful_life_months'] !== null
                ? max(1, (int) $data['default_useful_life_months'])
                : null,
            'default_residual_value_minor' => max(0, (int) ($data['default_residual_value_minor'] ?? 0)),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function assetAttributes(array $data, Branch $branch, ?int $assetId = null): array
    {
        $category = FixedAssetCategory::query()
            ->where('public_id', (string) ($data['category_id'] ?? ''))
            ->first();

        if (! $category) {
            throw new DomainException('Choose a valid fixed-asset category.');
        }
        if (! $category->is_active) {
            throw new DomainException('The selected category is inactive.');
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new DomainException('An asset description is required.');
        }

        $capitalization = Carbon::parse((string) ($data['capitalization_date'] ?? now()->toDateString()))->startOfDay();
        $inService = Carbon::parse((string) ($data['in_service_date'] ?? $capitalization->toDateString()))->startOfDay();
        if ($inService->lt($capitalization)) {
            throw new DomainException('The in-service date cannot be before the capitalization date.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? $this->settings->baseCurrency()));
        if (strlen($currency) !== 3) {
            throw new DomainException('Currency must be a 3-letter code.');
        }

        $value = (int) ($data['acquisition_value_minor'] ?? 0);
        if ($value <= 0) {
            throw new DomainException('The acquisition value must be greater than zero.');
        }

        $base = $this->settings->baseCurrency();
        $rate = $currency === $base
            ? '1'
            : (isset($data['fx_rate']) && $data['fx_rate'] !== '' ? (string) $data['fx_rate'] : $this->fx->rateFor($currency, $capitalization->toDateString()));

        if (! is_numeric($rate) || bccomp((string) $rate, '0', 12) <= 0) {
            throw new DomainException('The FX rate must be greater than zero.');
        }

        $valueBase = (new Money($value, $currency))->convertTo($base, $rate, $this->rounding->mode())->amountMinor;

        $residual = max(0, (int) ($data['residual_value_minor'] ?? $category->default_residual_value_minor ?? $this->settings->int('assets', 'default_residual_value_minor', 0)));
        if ($residual >= $valueBase) {
            throw new DomainException('The residual value must be lower than the acquisition value.');
        }

        $life = (int) ($data['useful_life_months'] ?? $category->default_useful_life_months ?? $this->settings->int('assets', 'default_useful_life_months', 60));
        if ($life < 1 || $life > 600) {
            throw new DomainException('The useful life must be between 1 and 600 months.');
        }

        $convention = (string) ($data['depreciation_convention'] ?? $this->settings->get('assets', 'depreciation_convention', FixedAsset::CONVENTION_MONTH_OF_PURCHASE));
        if (! in_array($convention, FixedAsset::CONVENTIONS, true)) {
            throw new DomainException('Choose a valid depreciation convention.');
        }

        $start = $inService->copy()->startOfMonth();
        if ($convention === FixedAsset::CONVENTION_NEXT_MONTH) {
            $start->addMonthNoOverflow();
        }

        return [
            'fixed_asset_category_id' => $category->id,
            'description' => $description,
            'location' => $data['location'] ?? null,
            'supplier_id' => $this->resolveSupplierId($data['supplier_id'] ?? null),
            'cost_center_id' => $this->resolveCostCenterId($data['cost_center_id'] ?? null),
            'employee_id' => $this->resolveEmployeeId($data['employee_id'] ?? null),
            'purchase_date' => isset($data['purchase_date']) && $data['purchase_date'] !== '' ? (string) $data['purchase_date'] : null,
            'capitalization_date' => $capitalization->toDateString(),
            'in_service_date' => $inService->toDateString(),
            'currency_code' => $currency,
            'fx_rate' => $rate,
            'fx_rate_date' => $capitalization->toDateString(),
            'fx_rate_source' => $currency === $base
                ? 'base'
                : (isset($data['fx_rate']) && $data['fx_rate'] !== '' ? 'manual' : 'market'),
            'acquisition_journal_entry_id' => $this->resolveAcquisitionEntryId(
                $data['acquisition_journal_entry_id'] ?? null,
                $category,
                $valueBase,
                $assetId,
            ),
            'acquisition_value_minor' => $value,
            'acquisition_value_base_minor' => $valueBase,
            'residual_value_minor' => $residual,
            'useful_life_months' => $life,
            'depreciation_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'depreciation_convention' => $convention,
            'depreciation_start_on' => $start->toDateString(),
            'attachments' => $this->normaliseAttachments($data['attachments'] ?? null),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function editableAsset(FixedAsset $asset): array
    {
        return [
            'category_id' => $asset->category?->public_id,
            'description' => $asset->description,
            'location' => $asset->location,
            'supplier_id' => $asset->supplier?->public_id,
            'cost_center_id' => $asset->costCenter?->public_id,
            'employee_id' => $asset->employee?->public_id,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'capitalization_date' => $asset->capitalization_date->toDateString(),
            'in_service_date' => $asset->in_service_date->toDateString(),
            'currency' => $asset->currency_code,
            // A market rate is re-read for the (possibly changed) date; a manual one is kept.
            'fx_rate' => $asset->fx_rate_source === 'manual' ? (string) $asset->fx_rate : null,
            'acquisition_journal_entry_id' => $asset->acquisitionJournalEntry?->public_id,
            'acquisition_value_minor' => $asset->acquisition_value_minor,
            'residual_value_minor' => $asset->residual_value_minor,
            'useful_life_months' => $asset->useful_life_months,
            'depreciation_convention' => $asset->depreciation_convention,
            'attachments' => $asset->attachments,
            'notes' => $asset->notes,
        ];
    }

    /**
     * A posted, un-reversed entry that already debited the category's cost
     * account for at least this asset (and the assets already linked to it).
     */
    private function resolveAcquisitionEntryId(mixed $reference, FixedAssetCategory $category, int $valueBase, ?int $assetId): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $entry = JournalEntry::query()->where('public_id', (string) $reference)->first();
        if (! $entry) {
            throw new DomainException('The acquisition journal entry does not exist.');
        }

        $this->assertEntryCoversCost($entry, $category->assetAccount, $valueBase, $assetId);

        return $entry->id;
    }

    private function assertAcquisitionEntry(FixedAsset $asset, ?Account $costAccount): JournalEntry
    {
        $entry = JournalEntry::query()->findOrFail($asset->acquisition_journal_entry_id);
        $this->assertEntryCoversCost($entry, $costAccount, $asset->acquisition_value_base_minor, $asset->id);

        return $entry;
    }

    private function assertEntryCoversCost(JournalEntry $entry, ?Account $costAccount, int $valueBase, ?int $assetId): void
    {
        if ($entry->status !== 'posted' || $entry->reversal_of_id !== null
            || JournalEntry::query()->where('reversal_of_id', $entry->id)->exists()) {
            throw new DomainException('The acquisition journal entry must be posted and not reversed.');
        }
        if (! $costAccount) {
            throw new DomainException('The asset category has no cost account configured.');
        }

        $debited = (int) JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $costAccount->id)
            ->sum('base_debit_minor');

        $alreadyLinked = (int) FixedAsset::query()
            ->where('acquisition_journal_entry_id', $entry->id)
            ->when($assetId, fn ($query) => $query->where('id', '!=', $assetId))
            ->sum('acquisition_value_base_minor');

        if ($debited - $alreadyLinked < $valueBase) {
            throw new DomainException(
                "Journal {$entry->number} did not debit {$costAccount->code} for this asset's cost (or the amount is already assigned to other assets).",
            );
        }
    }

    private function resolveSupplierId(mixed $reference): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return Supplier::query()->where('public_id', (string) $reference)->firstOrFail()->id;
    }

    private function resolveCostCenterId(mixed $reference): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return CostCenter::query()
            ->where('public_id', (string) $reference)
            ->firstOrFail()
            ->id;
    }

    private function resolveEmployeeId(mixed $reference): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return Employee::query()->where('public_id', (string) $reference)->firstOrFail()->id;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function normaliseAttachments(mixed $attachments): ?array
    {
        if (! is_array($attachments)) {
            return null;
        }

        return array_values(array_map(static function ($attachment): array {
            if (is_string($attachment)) {
                return ['name' => $attachment];
            }

            return [
                'name' => (string) ($attachment['name'] ?? 'attachment'),
                'url' => $attachment['url'] ?? null,
                'uploaded_at' => $attachment['uploaded_at'] ?? null,
            ];
        }, $attachments));
    }

    private function assertPeriod(string $period): string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new DomainException('The depreciation period must use the YYYY-MM format.');
        }

        return $period;
    }

    private function periodEnd(string $period): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->startOfDay();
    }
}
