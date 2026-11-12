<?php

namespace App\Modules\CRM\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class AssignmentRule extends Model
{
    use HasPublicId;

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

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function lastAssignedUser()
    {
        return $this->belongsTo(User::class, 'last_assigned_user_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'priority' => $this->priority,
            'branch_id' => $this->branch?->public_id,
            'source_id' => $this->source?->code,
            'service_category' => $this->service_category,
            'strategy' => $this->strategy,
            'target_user_id' => $this->target_user_id,
            // Public reference for pickers: `target_user_id` above is the internal id.
            'target_user' => $this->targetUser ? ['id' => $this->targetUser->public_id, 'name' => $this->targetUser->name] : null,
            'max_leads_per_user' => $this->max_leads_per_user,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
