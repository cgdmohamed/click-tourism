<?php

namespace App\Modules\Tax\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class TaxProfile extends Model
{
    use HasPublicId;

    protected $fillable = [
        'name',
        'rate',
        'is_inclusive',
        'sales_account_id',
        'purchase_account_id',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_inclusive' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'rate' => (string) $this->rate,
            'is_inclusive' => (bool) $this->is_inclusive,
        ];
    }
}
