<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One fixed asset in the register (Prompt 25).
 *
 * Cost and accumulated depreciation are stored in base-currency minor units;
 * the acquisition currency, rate and date are snapshotted for traceability.
 * Depreciation never rewrites these columns outside FixedAssetService.
 */
class FixedAsset extends Model
{
    use HasPublicId;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FULLY_DEPRECIATED = 'fully_depreciated';

    public const STATUS_DISPOSED = 'disposed';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_FULLY_DEPRECIATED,
        self::STATUS_DISPOSED,
    ];

    public const METHOD_STRAIGHT_LINE = 'straight_line';

    public const CONVENTION_MONTH_OF_PURCHASE = 'month_of_purchase';

    public const CONVENTION_NEXT_MONTH = 'next_month';

    public const CONVENTION_PRORATE_DAYS = 'prorate_days';

    /** @var array<int, string> */
    public const CONVENTIONS = [
        self::CONVENTION_MONTH_OF_PURCHASE,
        self::CONVENTION_NEXT_MONTH,
        self::CONVENTION_PRORATE_DAYS,
    ];

    public const DISPOSAL_SALE = 'sale';

    public const DISPOSAL_RETIREMENT = 'retirement';

    public const DISPOSAL_SCRAP = 'scrap';

    /** @var array<int, string> */
    public const DISPOSAL_TYPES = [
        self::DISPOSAL_SALE,
        self::DISPOSAL_RETIREMENT,
        self::DISPOSAL_SCRAP,
    ];

    protected $fillable = [
        'code',
        'branch_id',
        'fixed_asset_category_id',
        'description',
        'location',
        'supplier_id',
        'cost_center_id',
        'employee_id',
        'purchase_date',
        'capitalization_date',
        'in_service_date',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'acquisition_value_minor',
        'acquisition_value_base_minor',
        'residual_value_minor',
        'useful_life_months',
        'depreciation_method',
        'depreciation_convention',
        'depreciation_start_on',
        'status',
        'accumulated_depreciation_minor',
        'impairment_minor',
        'last_depreciated_period',
        'disposed_on',
        'disposal_type',
        'disposal_proceeds_minor',
        'disposal_gain_loss_minor',
        'capitalization_journal_entry_id',
        'disposal_journal_entry_id',
        'attachments',
        'impairment_document_ids',
        'applied_impairment_document_ids',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'capitalization_date' => 'date',
            'in_service_date' => 'date',
            'fx_rate' => 'decimal:10',
            'fx_rate_date' => 'date',
            'acquisition_value_minor' => 'integer',
            'acquisition_value_base_minor' => 'integer',
            'residual_value_minor' => 'integer',
            'useful_life_months' => 'integer',
            'depreciation_start_on' => 'date',
            'accumulated_depreciation_minor' => 'integer',
            'impairment_minor' => 'integer',
            'disposed_on' => 'date',
            'disposal_proceeds_minor' => 'integer',
            'disposal_gain_loss_minor' => 'integer',
            'attachments' => 'array',
            'impairment_document_ids' => 'array',
            'applied_impairment_document_ids' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'fixed_asset_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function capitalizationJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'capitalization_journal_entry_id');
    }

    public function disposalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposal_journal_entry_id');
    }

    public function depreciationItems(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationItem::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(FixedAssetTransfer::class);
    }

    public function depreciableMinor(): int
    {
        return max(0, $this->acquisition_value_base_minor - $this->residual_value_minor);
    }

    public function totalAccumulatedMinor(): int
    {
        return $this->accumulated_depreciation_minor + $this->impairment_minor;
    }

    public function carryingValueMinor(): int
    {
        if ($this->status === self::STATUS_DISPOSED) {
            return 0;
        }

        return max(0, $this->acquisition_value_base_minor - $this->totalAccumulatedMinor());
    }

    public function remainingDepreciableMinor(): int
    {
        return max(0, $this->depreciableMinor() - $this->totalAccumulatedMinor());
    }

    public function monthlyDepreciationMinor(): int
    {
        if ($this->useful_life_months < 1) {
            return 0;
        }

        return intdiv($this->depreciableMinor(), $this->useful_life_months);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_FULLY_DEPRECIATED], true);
    }

    public function firstDepreciationPeriod(): string
    {
        $start = Carbon::parse($this->in_service_date->toDateString())->startOfMonth();

        if ($this->depreciation_convention === self::CONVENTION_NEXT_MONTH) {
            $start->addMonthNoOverflow();
        }

        return $start->format('Y-m');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'description' => $this->description,
            'branch_id' => $this->branch?->public_id,
            'branch' => $this->branch?->toApiArray(),
            'category' => $this->category?->toApiArray(),
            'location' => $this->location,
            'supplier' => $this->supplier?->toApiArray(),
            'cost_center' => $this->costCenter?->toApiArray(),
            'employee' => $this->employee?->toApiArray(),
            'purchase_date' => $this->purchase_date?->toDateString(),
            'capitalization_date' => $this->capitalization_date?->toDateString(),
            'in_service_date' => $this->in_service_date?->toDateString(),
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'fx_rate_date' => $this->fx_rate_date?->toDateString(),
            'fx_rate_source' => $this->fx_rate_source,
            'acquisition_value_minor' => $this->acquisition_value_minor,
            'acquisition_value_base_minor' => $this->acquisition_value_base_minor,
            'residual_value_minor' => $this->residual_value_minor,
            'useful_life_months' => $this->useful_life_months,
            'depreciation_method' => $this->depreciation_method,
            'depreciation_convention' => $this->depreciation_convention,
            'depreciation_start_on' => $this->depreciation_start_on?->toDateString(),
            'first_depreciation_period' => $this->firstDepreciationPeriod(),
            'monthly_depreciation_minor' => $this->monthlyDepreciationMinor(),
            'status' => $this->status,
            'accumulated_depreciation_minor' => $this->accumulated_depreciation_minor,
            'impairment_minor' => $this->impairment_minor,
            'total_accumulated_minor' => $this->totalAccumulatedMinor(),
            'depreciable_minor' => $this->depreciableMinor(),
            'remaining_depreciable_minor' => $this->remainingDepreciableMinor(),
            'carrying_value_minor' => $this->carryingValueMinor(),
            'last_depreciated_period' => $this->last_depreciated_period,
            'disposed_on' => $this->disposed_on?->toDateString(),
            'disposal_type' => $this->disposal_type,
            'disposal_proceeds_minor' => $this->disposal_proceeds_minor,
            'disposal_gain_loss_minor' => $this->disposal_gain_loss_minor,
            'capitalization_journal_entry_id' => $this->capitalizationJournalEntry?->public_id,
            'disposal_journal_entry_id' => $this->disposalJournalEntry?->public_id,
            'attachments' => $this->attachments ?? [],
            'impairment_document_ids' => $this->impairment_document_ids ?? [],
            'applied_impairment_document_ids' => $this->applied_impairment_document_ids ?? [],
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
