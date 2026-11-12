<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
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
        ?int $branchId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'actor_id' => $actorId ?? Auth::id(),
            // Prefer the audited record's branch; fall back to the active
            // branch so global actions are still attributable.
            'branch_id' => $branchId ?? $entity?->getAttribute('branch_id') ?? $this->activeBranchId(),
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

    private function activeBranchId(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        try {
            return app(BranchScope::class)->resolve($user)?->id;
        } catch (AuthorizationException) {
            // A request carrying an unauthorised X-Branch-Id must not turn an
            // audit write into a failure; the action is still recorded.
            return null;
        }
    }
}
