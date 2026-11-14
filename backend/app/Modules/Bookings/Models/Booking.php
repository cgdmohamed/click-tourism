<?php

namespace App\Modules\Bookings\Models;

use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Support\CarriesWarnings;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use CarriesWarnings;
    use HasPublicId;

    public const STATUSES = [
        'draft', 'quoted', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled', 'no_show',
    ];

    protected $fillable = [
        'number',
        'customer_id',
        'branch_id',
        'tour_departure_id',
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
        'margin_approval_status',
        'margin_approval_reason',
        'margin_requested_by',
        'margin_requested_at',
        'margin_approved_by',
        'margin_approved_at',
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
            'margin_requested_at' => 'datetime',
            'margin_approved_at' => 'datetime',
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

    public function tourDeparture()
    {
        return $this->belongsTo(TourDeparture::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function items()
    {
        return $this->hasMany(BookingItem::class);
    }

    /** The booking's live customer invoice (not voided); the same rule that stops a second one being created. */
    public function customerInvoice()
    {
        return $this->hasOne(Invoice::class)
            ->where('type', Invoice::TYPE_CUSTOMER_INVOICE)
            ->where('status', '!=', Invoice::STATUS_VOIDED)
            ->latestOfMany();
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
            'created_via' => $this->created_via,
            'number' => $this->number,
            'customer_id' => $this->customer?->public_id,
            'customer' => $this->customer?->toApiArray(),
            'branch_id' => $this->branch?->public_id,
            'tour_departure_id' => $this->tourDeparture?->public_id,
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
            'margin_approval_status' => $this->margin_approval_status,
            'margin_approval_reason' => $this->margin_approval_reason,
            'margin_requested_at' => $this->margin_requested_at?->toIso8601String(),
            'margin_approved_at' => $this->margin_approved_at?->toIso8601String(),
            'paid_minor' => $this->paidMinor(),
            'balance_minor' => $this->balanceMinor(),
            'notes' => $this->notes,
            'tax_minor' => (int) $this->tax_minor,
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_fee_minor' => (int) $this->cancellation_fee_minor,
            'margin_requested_by' => $this->margin_requested_by
                ? User::query()->whereKey($this->margin_requested_by)->value('public_id')
                : null,
            'allowed_transitions' => BookingService::allowedTransitionsFor($this->status),
        ];

        // Read-only invoice status for everyone who can see the booking (W2): sales and reservations cannot list invoices.
        if ($this->relationLoaded('customerInvoice')) {
            $invoice = $this->customerInvoice;
            $payload['invoice'] = $invoice === null ? null : [
                'number' => $invoice->number,
                'status' => $invoice->status,
                'currency' => $invoice->currency_code,
                'total_minor' => (int) $invoice->total_minor,
                'outstanding_minor' => (int) $invoice->outstanding_minor,
            ];
        }

        if ($this->relationLoaded('statusHistory')) {
            $payload['status_history'] = $this->statusHistory
                ->sortBy('id')
                ->map(fn (BookingStatusHistory $row) => [
                    'from' => $row->from_status,
                    'to' => $row->to_status,
                    'reason' => $row->reason,
                    'at' => $row->created_at?->toIso8601String(),
                ])->values()->all();
        }

        if ($withItems) {
            $payload['items'] = $this->items->map(fn (BookingItem $item) => $item->toApiArray())->values()->all();
        }

        if ($this->warnings() !== []) {
            $payload['warnings'] = $this->warnings();
        }

        return $payload;
    }
}
