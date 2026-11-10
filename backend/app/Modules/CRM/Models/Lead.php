<?php

namespace App\Modules\CRM\Models;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Parties\Models\Customer;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasPublicId;

    protected $fillable = [
        'source_id',
        'stage_id',
        'external_id',
        'service_category',
        'name',
        'phone',
        'email',
        'branch_id',
        'owner_user_id',
        'status',
        'consent',
        'notes',
        'first_response_at',
        'last_activity_at',
        'reassignment_attempts',
        'escalated_at',
        'converted_customer_id',
        'converted_booking_id',
    ];

    protected function casts(): array
    {
        return [
            'consent' => 'boolean',
            'first_response_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'reassignment_attempts' => 'integer',
            'escalated_at' => 'datetime',
        ];
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function stage()
    {
        return $this->belongsTo(LeadStage::class, 'stage_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source?->code ?? 'other',
            'service_category' => $this->service_category,
            'stage' => $this->stage?->code ?? 'new',
            'owner_user_id' => $this->owner?->public_id,
            'branch_id' => $this->branch?->public_id,
            'status' => $this->status,
            'consent' => (bool) $this->consent,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'reassignment_attempts' => (int) $this->reassignment_attempts,
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'converted_customer_id' => $this->convertedCustomer?->public_id,
            'converted_booking_id' => $this->convertedBooking?->public_id,
        ];
    }

    public function convertedCustomer()
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function convertedBooking()
    {
        return $this->belongsTo(Booking::class, 'converted_booking_id');
    }
}
