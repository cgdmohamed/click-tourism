<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentRule extends Model
{
    protected $fillable = [
        'priority',
        'branch_id',
        'source_id',
        'strategy',
        'max_leads_per_user',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
