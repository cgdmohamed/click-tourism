<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixed-asset category (Prompt 25).
 *
 * A category carries the four GL mappings required by every asset in it:
 * cost, accumulated depreciation, depreciation expense and disposal
 * gain/loss. Accounts are installation data, never hardcoded in the service.
 */
class FixedAssetCategory extends Model
{
    use HasPublicId;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'asset_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
        'default_useful_life_months',
        'default_residual_value_minor',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'default_useful_life_months' => 'integer',
            'default_residual_value_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function disposalGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_gain_account_id');
    }

    public function disposalLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_loss_account_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'asset_account' => $this->assetAccount?->toApiArray(),
            'accumulated_depreciation_account' => $this->accumulatedDepreciationAccount?->toApiArray(),
            'depreciation_expense_account' => $this->depreciationExpenseAccount?->toApiArray(),
            'disposal_gain_account' => $this->disposalGainAccount?->toApiArray(),
            'disposal_loss_account' => $this->disposalLossAccount?->toApiArray(),
            'default_useful_life_months' => $this->default_useful_life_months,
            'default_residual_value_minor' => $this->default_residual_value_minor,
            'is_active' => (bool) $this->is_active,
            'assets_count' => $this->relationLoaded('assets') ? $this->assets->count() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
