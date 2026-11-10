<?php

namespace App\Modules\Accounting\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset's share of a depreciation run. Unique per asset and period so a
 * duplicate or concurrent run can never post the same month twice.
 */
class FixedAssetDepreciationItem extends Model
{
    use HasPublicId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'fixed_asset_depreciation_run_id',
        'fixed_asset_id',
        'period',
        'amount_minor',
        'accumulated_before_minor',
        'accumulated_after_minor',
        'carrying_before_minor',
        'carrying_after_minor',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'accumulated_before_minor' => 'integer',
            'accumulated_after_minor' => 'integer',
            'carrying_before_minor' => 'integer',
            'carrying_after_minor' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FixedAssetDepreciationRun::class, 'fixed_asset_depreciation_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'run_id' => $this->run?->public_id,
            'asset_id' => $this->asset?->public_id,
            'asset_code' => $this->asset?->code,
            'asset_description' => $this->asset?->description,
            'branch_id' => $this->asset?->branch?->public_id,
            'category' => $this->asset?->category?->code,
            'period' => $this->period,
            'amount_minor' => $this->amount_minor,
            'accumulated_before_minor' => $this->accumulated_before_minor,
            'accumulated_after_minor' => $this->accumulated_after_minor,
            'carrying_before_minor' => $this->carrying_before_minor,
            'carrying_after_minor' => $this->carrying_after_minor,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
        ];
    }
}
