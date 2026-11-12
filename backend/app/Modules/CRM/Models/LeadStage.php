<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class LeadStage extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'sort_order', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
        ];
    }

    public function toApiArray(): array
    {
        return [
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'sort_order' => (int) $this->sort_order,
            'is_won' => (bool) $this->is_won,
            'is_lost' => (bool) $this->is_lost,
        ];
    }
}
