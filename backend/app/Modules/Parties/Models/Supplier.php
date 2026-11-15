<?php

namespace App\Modules\Parties\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPartyCode;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasPartyCode;
    use HasPublicId;

    public function codePrefix(): string
    {
        return 'S';
    }

    protected $fillable = [
        'category',
        'name_ar',
        'name_en',
        'phone',
        'email',
        'settlement_currency',
        'branch_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'created_via' => $this->created_via,
            'category' => $this->category,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'phone' => $this->phone,
            'email' => $this->email,
            'settlement_currency' => $this->settlement_currency,
            'branch_id' => $this->branch?->public_id,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
