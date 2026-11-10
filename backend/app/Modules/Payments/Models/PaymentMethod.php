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
            'default_cash_account_id' => $this->default_cash_account_id,
            'default_cash_account' => $this->defaultCashAccount?->toApiArray(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
