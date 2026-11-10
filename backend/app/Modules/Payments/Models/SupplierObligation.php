<?php

namespace App\Modules\Payments\Models;

use App\Modules\Bookings\Models\BookingItem;
use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Supplier;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class SupplierObligation extends Model
{
    use HasPublicId;

    protected $fillable = [
        'supplier_id',
        'booking_item_id',
        'branch_id',
        'amount_minor',
        'currency_code',
        'invoice_reference',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bookingItem()
    {
        return $this->belongsTo(BookingItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function applications()
    {
        return $this->hasMany(SupplierPaymentApplication::class);
    }

    public function paidMinor(): int
    {
        return (int) $this->applications()->sum('amount_minor');
    }

    public function balanceMinor(): int
    {
        return max(0, (int) $this->amount_minor - $this->paidMinor());
    }

    /**
     * Recompute the subledger status from its applications. Called after every
     * application inside the same transaction; never set from user input.
     */
    public function refreshStatus(): void
    {
        $paid = $this->paidMinor();

        $status = match (true) {
            $paid <= 0 => 'open',
            $paid < (int) $this->amount_minor => 'partially_paid',
            default => 'paid',
        };

        if ($this->status !== $status) {
            $this->update(['status' => $status]);
        }
    }

    public function toApiArray(): array
    {
        $paid = $this->paidMinor();

        return [
            'id' => $this->public_id,
            'supplier_id' => $this->supplier?->public_id,
            'supplier' => $this->supplier?->toApiArray(),
            'booking_item_id' => $this->booking_item_id !== null ? (string) $this->booking_item_id : null,
            'branch_id' => $this->branch?->public_id,
            'amount_minor' => (int) $this->amount_minor,
            'paid_minor' => $paid,
            'balance_minor' => max(0, (int) $this->amount_minor - $paid),
            'currency' => $this->currency_code,
            'invoice_reference' => $this->invoice_reference,
            'status' => $this->status,
            // Explicitly distinguish operational obligations from recognized GL payables.
            'accounting_status' => 'unposted',
        ];
    }
}
