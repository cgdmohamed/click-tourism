<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Employee;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operational transfer of an asset between branches, cost centers or
 * custodians. Transfers deliberately create no journal and rewrite no posted
 * GL: the global control accounts stay unchanged, while the register records
 * who moved the asset, when and why.
 */
class FixedAssetTransfer extends Model
{
    use HasPublicId;

    protected $fillable = [
        'fixed_asset_id',
        'from_branch_id',
        'to_branch_id',
        'from_cost_center_id',
        'to_cost_center_id',
        'from_employee_id',
        'to_employee_id',
        'transfer_date',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'from_cost_center_id');
    }

    public function toCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'to_cost_center_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'asset_id' => $this->asset?->public_id,
            'asset_code' => $this->asset?->code,
            'from_branch' => $this->fromBranch?->toApiArray(),
            'to_branch' => $this->toBranch?->toApiArray(),
            'from_cost_center' => $this->fromCostCenter?->toApiArray(),
            'to_cost_center' => $this->toCostCenter?->toApiArray(),
            'from_employee' => $this->fromEmployee ? [
                'id' => $this->fromEmployee->public_id,
                'name_ar' => $this->fromEmployee->name_ar,
                'name_en' => $this->fromEmployee->name_en,
            ] : null,
            'to_employee' => $this->toEmployee ? [
                'id' => $this->toEmployee->public_id,
                'name_ar' => $this->toEmployee->name_ar,
                'name_en' => $this->toEmployee->name_en,
            ] : null,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
