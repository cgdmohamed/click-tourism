<?php

namespace App\Modules\Payments\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use HasPublicId;

    protected $fillable = [
        'supplier_id',
        'supplier_obligation_id',
        'branch_id',
        'amount_minor',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'cash_account_id',
        'status',
        'idempotency_key',
        'created_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'fx_rate' => 'decimal:10',
            'paid_at' => 'datetime',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(\App\Modules\Parties\Models\Supplier::class);
    }

    public function obligation()
    {
        return $this->belongsTo(SupplierObligation::class, 'supplier_obligation_id');
    }

    public function branch()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Branch::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(\App\Modules\Accounting\Models\Account::class, 'cash_account_id');
    }

    public function applications()
    {
        return $this->hasMany(SupplierPaymentApplication::class);
    }

    public function appliedMinor(): int
    {
        return (int) $this->applications()->sum('amount_minor');
    }

    /**
     * Amount paid but not yet matched to an obligation. Non-zero means the
     * document is a supplier advance (AC-PAY-009).
     */
    public function unallocatedMinor(): int
    {
        return max(0, (int) $this->amount_minor - $this->appliedMinor());
    }

    public function toApiArray(): array
    {
        $applied = $this->appliedMinor();

        return [
            'id' => $this->public_id,
            'supplier_id' => $this->supplier?->public_id,
            'supplier' => $this->supplier?->toApiArray(),
            'supplier_obligation_id' => $this->obligation?->public_id,
            'branch_id' => $this->branch?->public_id,
            'amount_minor' => (int) $this->amount_minor,
            'applied_minor' => $applied,
            'unallocated_minor' => max(0, (int) $this->amount_minor - $applied),
            'currency' => $this->currency_code,
            'cash_account_id' => $this->cashAccount?->public_id,
            'fx_rate' => (string) $this->fx_rate,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
