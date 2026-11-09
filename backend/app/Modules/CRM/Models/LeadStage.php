<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class LeadStage extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'sort_order', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return [
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
        ];
    }
}
