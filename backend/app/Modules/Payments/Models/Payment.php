<?php

namespace App\Modules\Payments\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Customer;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number',
        'customer_id',
        'booking_id',
        'branch_id',
        'method',
        'cash_account_id',
        'amount_minor',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'unallocated_minor',
        'status',
        'paid_at',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'unallocated_minor' => 'integer',
            'paid_at' => 'datetime',
            'fx_rate' => 'decimal:10',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'customer_id' => $this->customer?->public_id,
            'customer' => $this->customer?->toApiArray(),
            'booking_id' => $this->booking?->public_id,
            'branch_id' => $this->branch?->public_id,
            'method' => $this->method,
            'amount_minor' => (int) $this->amount_minor,
            'unallocated_minor' => (int) $this->unallocated_minor,
            'currency' => $this->currency_code,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
