<?php

namespace App\Modules\Parties\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasPublicId;

    protected $fillable = [
        'category',
        'name_ar',
        'name_en',
        'phone',
        'email',
        'settlement_currency',
        'branch_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Branch::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'category' => $this->category,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'phone' => $this->phone,
            'email' => $this->email,
            'settlement_currency' => $this->settlement_currency,
            'branch_id' => $this->branch?->public_id,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
