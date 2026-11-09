<?php

namespace App\Modules\Treasury\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    use HasPublicId;

    protected $fillable = [
        'account_id',
        'branch_id',
        'kind',
        'name_ar',
        'name_en',
        'currency_code',
        'bank_name',
        'account_last4',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function toApiArray(?int $balanceMinor = null): array
    {
        return [
            // `id` stays the GL account public id so existing payment screens keep working.
            'id' => $this->account->public_id,
            'cash_account_id' => $this->public_id,
            'code' => $this->account->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'kind' => $this->kind,
            'type' => $this->kind,
            'currency' => $this->currency_code,
            'bank_name' => $this->bank_name,
            'account_last4' => $this->account_last4,
            'branch_id' => $this->branch?->public_id,
            'is_active' => (bool) $this->is_active,
            'registered' => true,
            'balance_minor' => $balanceMinor,
        ];
    }
}
