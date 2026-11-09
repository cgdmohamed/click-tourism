<?php

namespace App\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;

class BookingItem extends Model
{
    protected $fillable = [
        'booking_id',
        'service_type',
        'supplier_id',
        'description',
        'starts_on',
        'ends_on',
        'quantity',
        'unit_cost_minor',
        'unit_sell_minor',
        'cost_currency',
        'sell_currency',
        'tax_profile_id',
        'tax_rate_snapshot',
        'tax_minor',
        'total_sell_minor',
        'total_cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'quantity' => 'integer',
            'unit_cost_minor' => 'integer',
            'unit_sell_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_sell_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'tax_rate_snapshot' => 'decimal:4',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function supplier()
    {
        return $this->belongsTo(\App\Modules\Parties\Models\Supplier::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'service_type' => $this->service_type,
            'supplier_id' => $this->supplier?->public_id,
            'description' => $this->description,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'quantity' => (int) $this->quantity,
            'unit_cost_minor' => (int) $this->unit_cost_minor,
            'unit_sell_minor' => (int) $this->unit_sell_minor,
            'cost_currency' => $this->cost_currency,
            'sell_currency' => $this->sell_currency,
            'tax_minor' => (int) $this->tax_minor,
            'total_sell_minor' => (int) $this->total_sell_minor,
            'total_cost_minor' => (int) $this->total_cost_minor,
        ];
    }
}
