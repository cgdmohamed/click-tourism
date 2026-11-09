<?php

namespace App\Modules\Payments\Models;

use App\Modules\Bookings\Models\Booking;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number',
        'payment_id',
        'booking_id',
        'branch_id',
        'amount_minor',
        'currency_code',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
