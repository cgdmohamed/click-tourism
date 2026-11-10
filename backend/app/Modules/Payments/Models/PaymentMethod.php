<?php

namespace App\Modules\Payments\Models;

use App\Modules\Accounting\Models\Account;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasPublicId;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'type',
        'default_cash_account_id',
        'provider_name',
        'account_name',
        'account_reference',
        'iban',
        'instructions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function defaultCashAccount()
    {
        return $this->belongsTo(Account::class, 'default_cash_account_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'type' => $this->type,
            'default_cash_account_id' => $this->defaultCashAccount?->public_id,
            'provider_name' => $this->provider_name,
            'account_name' => $this->account_name,
            'account_reference' => $this->account_reference,
            'iban' => $this->iban,
            'instructions' => $this->instructions,
            'default_cash_account' => $this->defaultCashAccount?->toApiArray(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
