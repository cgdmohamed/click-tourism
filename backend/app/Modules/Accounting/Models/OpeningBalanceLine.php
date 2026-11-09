<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class OpeningBalanceLine extends Model
{
    protected $fillable = [
        'opening_balance_batch_id',
        'account_id',
        'currency_code',
        'fx_rate',
        'debit_minor',
        'credit_minor',
        'party_type',
        'party_id',
        'description',
    ];

    protected function casts(): array
    {
        return ['debit_minor' => 'integer', 'credit_minor' => 'integer', 'fx_rate' => 'decimal:10'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function toApiArray(): array
    {
        return [
            'account' => $this->account?->toApiArray(),
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'debit_minor' => $this->debit_minor,
            'credit_minor' => $this->credit_minor,
            'party_type' => $this->party_type,
            'party_id' => match ($this->party_type) {
                'customer' => Customer::query()->find($this->party_id)?->public_id,
                'supplier' => Supplier::query()->find($this->party_id)?->public_id,
                default => null,
            },
            'description' => $this->description,
        ];
    }
}
