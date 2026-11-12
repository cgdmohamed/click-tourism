<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function toApiArray(): array
    {
        return [
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
