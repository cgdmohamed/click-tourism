<?php

namespace App\Modules\Core\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'accounting_mode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'accounting_mode' => $this->accounting_mode,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
