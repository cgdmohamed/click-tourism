<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingDocumentLine extends Model
{
    protected $fillable = [
        'accounting_document_id',
        'account_id',
        'description',
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
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'tax_minor' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AccountingDocument::class);
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
            'debit_minor' => $this->debit_minor,
            'credit_minor' => $this->credit_minor,
            'tax_minor' => $this->tax_minor,
            'tax_account' => $this->taxAccount?->toApiArray(),
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
        ];
    }
}
