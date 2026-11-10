<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentRule extends Model
{
    protected $fillable = [
        'priority',
        'branch_id',
        'source_id',
        'service_category',
        'strategy',
        'target_user_id',
        'last_assigned_user_id',
        'max_leads_per_user',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'max_leads_per_user' => 'integer',
        ];
    }
}
