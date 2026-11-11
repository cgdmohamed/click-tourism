<?php

namespace App\Modules\Accounting\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasPublicId;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'type',
        'parent_id',
        'is_postable',
        'currency_code',
        'is_active',
        'classification',
    ];

    /**
     * Balance-sheet classification allowed per account type (null = unclassified).
     * Same model as Odoo / Zoho Books: an explicit attribute, not the code prefix.
     *
     * @var array<string, list<string>>
     */
    public const CLASSIFICATIONS = [
        'asset' => ['current_asset', 'non_current_asset', 'fixed_asset', 'prepayment'],
        'liability' => ['current_liability', 'non_current_liability'],
        'equity' => ['equity'],
    ];

    /** @return list<string> classifications valid for the account type */
    public static function classificationsFor(?string $type): array
    {
        return self::CLASSIFICATIONS[$type] ?? [];
    }

    /**
     * Default classification for a system-created account, following the
     * shipped chart (used by the seeder and by code that auto-creates accounts).
     */
    public static function defaultClassification(string $type, string $code): ?string
    {
        if ($type === 'equity') {
            return 'equity';
        }
        if ($type === 'liability') {
            return preg_match('/^2[1-4]/', $code) === 1 ? 'current_liability' : 'non_current_liability';
        }
        if ($type !== 'asset') {
            return null;
        }
        if (in_array($code, ['1150', '1151', '1152', '1153', '1154'], true)) {
            return 'prepayment';
        }
        if (str_starts_with($code, '11')) {
            return 'current_asset';
        }
        if (str_starts_with($code, '12') || str_starts_with($code, '13')) {
            return 'fixed_asset';
        }

        return 'non_current_asset';
    }

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopePostable($query)
    {
        return $query->where('is_postable', true)->where('is_active', true);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'type' => $this->type,
            'parent_id' => $this->parent?->public_id,
            'is_postable' => (bool) $this->is_postable,
            'is_active' => (bool) $this->is_active,
            'classification' => $this->classification,
        ];
    }
}
