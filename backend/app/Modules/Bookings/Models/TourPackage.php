<?php

namespace App\Modules\Bookings\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class TourPackage extends Model
{
    use HasPublicId;

    protected $fillable = [
        'branch_id', 'code', 'name', 'category', 'execution_type', 'description',
        'destination', 'currency_code', 'base_price_minor', 'estimated_cost_minor',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['base_price_minor' => 'integer', 'estimated_cost_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function departures()
    {
        return $this->hasMany(TourDeparture::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'execution_type' => $this->execution_type,
            'description' => $this->description,
            'destination' => $this->destination,
            'currency' => $this->currency_code,
            'base_price_minor' => (int) $this->base_price_minor,
            'estimated_cost_minor' => (int) $this->estimated_cost_minor,
            'is_active' => (bool) $this->is_active,
            'departures' => $this->relationLoaded('departures')
                ? $this->departures->map(fn (TourDeparture $departure) => $departure->toApiArray())->values()->all()
                : null,
        ];
    }
}
