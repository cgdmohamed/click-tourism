<?php

namespace App\Modules\Money\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    protected $fillable = [
        'currency_code',
        'rate',
        'rate_date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
        ];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
