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
    ];

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
        ];
    }
}
