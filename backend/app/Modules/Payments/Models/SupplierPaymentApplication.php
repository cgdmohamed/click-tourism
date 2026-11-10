<?php

namespace App\Modules\Payments\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Links a supplier payment (or its unapplied part) to one obligation.
 * One payment may settle several obligations; one obligation may be settled
 * by several payments (TE-031).
 */
class SupplierPaymentApplication extends Model
{
    use HasPublicId;

    protected $fillable = [
        'supplier_payment_id',
        'supplier_obligation_id',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function obligation()
    {
        return $this->belongsTo(SupplierObligation::class, 'supplier_obligation_id');
    }
}
