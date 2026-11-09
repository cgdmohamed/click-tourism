<?php

namespace App\Modules\Bookings\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Parties\Models\Customer;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasPublicId;

    public const STATUSES = [
        'draft', 'quoted', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled', 'no_show',
    ];

    protected $fillable = [
        'number',
        'customer_id',
        'branch_id',
        'owner_user_id',
        'status',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'start_date',
        'end_date',
        'pax_adults',
        'pax_children',
        'pax_infants',
        'total_sell_minor',
        'total_cost_minor',
        'tax_minor',
        'notes',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_fee_minor',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_sell_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'tax_minor' => 'integer',
            'cancellation_fee_minor' => 'integer',
            'pax_adults' => 'integer',
            'pax_children' => 'integer',
            'pax_infants' => 'integer',
            'fx_rate' => 'decimal:10',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function items()
    {
        return $this->hasMany(BookingItem::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function paidMinor(): int
    {
        return (int) $this->allocations()->sum('amount_minor');
    }

    public function balanceMinor(): int
    {
        return $this->total_sell_minor - $this->paidMinor();
    }

    public function marginMinor(): int
    {
        return $this->total_sell_minor - $this->total_cost_minor;
    }

    public function toApiArray(bool $withItems = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'number' => $this->number,
            'customer_id' => $this->customer?->public_id,
            'customer' => $this->customer?->toApiArray(),
            'branch_id' => $this->branch?->public_id,
            'owner_user_id' => $this->owner?->public_id,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'pax_adults' => (int) $this->pax_adults,
            'pax_children' => (int) $this->pax_children,
            'pax_infants' => (int) $this->pax_infants,
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'total_sell_minor' => (int) $this->total_sell_minor,
            'total_cost_minor' => (int) $this->total_cost_minor,
            'margin_minor' => $this->marginMinor(),
            'paid_minor' => $this->paidMinor(),
            'balance_minor' => $this->balanceMinor(),
            'notes' => $this->notes,
        ];

        if ($withItems) {
            $payload['items'] = $this->items->map(fn (BookingItem $item) => $item->toApiArray())->values()->all();
        }

        return $payload;
    }
}
