<?php

namespace App\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'booking_status_history';

    protected $fillable = [
        'booking_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
