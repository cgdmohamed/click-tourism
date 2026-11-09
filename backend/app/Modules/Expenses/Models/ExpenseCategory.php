<?php

namespace App\Modules\Expenses\Models;

use App\Modules\Accounting\Models\Account;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use HasPublicId;

    protected $fillable = ['code', 'name_ar', 'name_en', 'account_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'account_id' => $this->account->public_id,
            'account_code' => $this->account->code,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
