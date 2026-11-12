<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'branch_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action,
            'actor' => $this->actor ? [
                'id' => $this->actor->public_id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'branch' => $this->branch ? [
                'id' => $this->branch->public_id,
                'name' => $this->branch->name_en ?: $this->branch->name_ar,
                'name_ar' => $this->branch->name_ar,
                'name_en' => $this->branch->name_en,
            ] : null,
            'before' => $this->before,
            'after' => $this->after,
            'ip' => $this->ip,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
