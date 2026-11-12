<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GAP-CORE-01: the signed-in user's in-app notifications and read state.
 * There is no cross-user access: every query is scoped to the token owner.
 */
class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $paginator = Notification::query()
            ->with('branch')
            ->where('user_id', $user->id)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        $paginator->getCollection()->transform(fn (Notification $notification) => $notification->toApiArray());

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'unread_count' => $this->unreadCount($user->id),
            ],
        ]);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'This notification belongs to another user.');
        }

        $notification->markRead();

        return $this->ok($notification->refresh()->load('branch')->toApiArray());
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->ok([
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    private function unreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
