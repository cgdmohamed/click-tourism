<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Notification;
use App\Modules\Core\Models\User;
use Illuminate\Support\Collection;

/**
 * Creates in-app notifications (GAP-CORE-01). Delivery is synchronous and
 * best-effort: a notification never blocks the business transaction, and it
 * carries only a reference to the entity, never a copy of financial data.
 */
class NotificationService
{
    public function send(
        ?int $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?int $branchId = null,
    ): ?Notification {
        if ($userId === null) {
            return null;
        }

        return Notification::query()->create([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    /**
     * Notify every active user assigned to the branch who holds the given
     * permission. Used for escalations that need a supervisor, not one person.
     */
    public function notifyBranchPermission(
        Branch $branch,
        string $permission,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
    ): int {
        $recipients = $this->branchUsers($branch, $permission);

        foreach ($recipients as $recipient) {
            $this->send($recipient->id, $type, $title, $body, $data, $branch->id);
        }

        return $recipients->count();
    }

    /** @return Collection<int, User> */
    public function branchUsers(Branch $branch, string $permission): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('branches', fn ($query) => $query->where('branches.id', $branch->id))
            ->permission($permission)
            ->orderBy('id')
            ->get();
    }
}
