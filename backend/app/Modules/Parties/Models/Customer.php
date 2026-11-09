<?php

namespace App\Modules\Parties\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasPublicId;

    protected $fillable = [
        'type',
        'name_ar',
        'name_en',
        'phone',
        'email',
        'tax_id',
        'branch_id',
        'credit_limit_minor',
        'currency_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function bookings()
    {
        return $this->hasMany(\App\Modules\Bookings\Models\Booking::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_id' => $this->tax_id,
            'branch_id' => $this->branch?->public_id,
            'credit_limit_minor' => (int) $this->credit_limit_minor,
            'currency' => $this->currency_code,
            'is_active' => (bool) $this->is_active,
        ];
    }

    public function branch()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Branch::class);
    }
}
