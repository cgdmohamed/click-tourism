<?php

namespace App\Modules\Invoices\Models;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Part of a receipt or supplier advance applied to one invoice. */
class InvoiceApplication extends Model
{
    use HasPublicId;

    protected $fillable = [
        'invoice_id',
        'payment_id',
        'supplier_payment_id',
        'branch_id',
        'amount_minor',
        'created_by',
        'reversed_at',
        'reversal_reason',
        'reversed_by',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'reversed_at' => 'datetime'];
    }

    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function supplierPayment()
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'invoice_id' => $this->invoice?->public_id,
            'payment_id' => $this->payment?->public_id,
            'supplier_payment_id' => $this->supplierPayment?->public_id,
            'amount_minor' => (int) $this->amount_minor,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
