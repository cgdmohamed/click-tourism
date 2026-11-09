<?php

namespace App\Modules\Money\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exponent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'exponent' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
