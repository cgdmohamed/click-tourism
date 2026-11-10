<?php

namespace App\Modules\Invoices\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'account_id',
        'description',
        'quantity',
        'unit_price_minor',
        'debit_minor',
        'credit_minor',
        'tax_minor',
        'tax_account_id',
        'party_type',
        'party_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'tax_minor' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'tax_account_id');
    }

    public function toApiArray(): array
    {
        return [
            'account' => $this->account?->toApiArray(),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'debit_minor' => $this->debit_minor,
            'credit_minor' => $this->credit_minor,
            'tax_minor' => $this->tax_minor,
            'tax_account' => $this->taxAccount?->toApiArray(),
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
        ];
    }
}
