<?php

namespace App\Modules\Bookings\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class TourDeparture extends Model
{
    use HasPublicId;

    protected $fillable = ['tour_package_id', 'branch_id', 'starts_on', 'ends_on', 'capacity', 'status'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'capacity' => 'integer'];
    }

    public function package()
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function occupiedSeats(): int
    {
        return (int) $this->bookings()
            ->whereIn('status', ['confirmed', 'in_progress', 'completed', 'closed'])
            ->selectRaw('COALESCE(SUM(pax_adults + pax_children + pax_infants), 0) AS occupied')
            ->value('occupied');
    }

    public function toApiArray(): array
    {
        $occupied = $this->occupiedSeats();

        return [
            'id' => $this->public_id,
            'package_id' => $this->package?->public_id,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'capacity' => $this->capacity,
            'status' => $this->status,
            'occupied_seats' => $occupied,
            'available_seats' => $this->capacity === null ? null : max(0, $this->capacity - $occupied),
        ];
    }
}
