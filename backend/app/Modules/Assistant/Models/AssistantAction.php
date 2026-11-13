<?php

namespace App\Modules\Assistant\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** An action the assistant prepared for one user; nothing happens until that user confirms it (ADR-0023). */
class AssistantAction extends Model
{
    use HasPublicId;

    public const TTL_MINUTES = 15;

    protected $fillable = ['user_id', 'branch_id', 'tool', 'payload', 'status', 'expires_at', 'entity', 'entity_id', 'error_code', 'error_message', 'decided_at'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
