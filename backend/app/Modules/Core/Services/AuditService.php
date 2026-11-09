<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'actor_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
            'request_id' => request()->header('X-Request-Id'),
            'created_at' => now(),
        ]);
    }
}
