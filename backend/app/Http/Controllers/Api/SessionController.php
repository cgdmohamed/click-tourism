<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Self-service sessions (GAP-CORE-04): the signed-in user lists the devices that hold one of their
 * login tokens and revokes them. Only the caller's own login tokens (ability "*") are visible:
 * MCP tokens are managed on their own screen, and another user's tokens never appear. The token
 * value and hash are never returned. Revocations are audited.
 */
class SessionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        $rows = $this->own($request)->orderByDesc('last_used_at')->orderByDesc('id')->get()->map(fn (PersonalAccessToken $token) => [
            'id' => $token->getKey(),
            'device' => $token->name,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'current' => $current instanceof PersonalAccessToken && $current->getKey() === $token->getKey(),
        ])->values();

        return $this->ok($rows);
    }

    public function destroy(Request $request, int $session, AuditService $audit): JsonResponse
    {
        $token = $this->own($request)->whereKey($session)->first();
        if ($token === null) {
            return ApiResponse::error('Session not found.', 'not_found', 404);
        }

        $current = $request->user()->currentAccessToken();
        $wasCurrent = $current instanceof PersonalAccessToken && $current->getKey() === $token->getKey();
        $audit->log('auth.session_revoked', null, ['device' => $token->name], ['current' => $wasCurrent], $request->user()->id);
        $token->delete();

        return $this->ok(['revoked' => 1, 'current' => $wasCurrent]);
    }

    /** Signs every other device out; this session stays. */
    public function destroyOthers(Request $request, AuditService $audit): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $query = $this->own($request);
        if ($current instanceof PersonalAccessToken) {
            $query->whereKeyNot($current->getKey());
        }
        $count = $query->delete();
        $audit->log('auth.sessions_revoked', null, null, ['count' => $count], $request->user()->id);

        return $this->ok(['revoked' => $count]);
    }

    /** The caller's own login tokens only (ability "*"); MCP tokens carry "mcp:read". */
    private function own(Request $request)
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->where('tokenable_id', $request->user()->getKey())
            ->where('abilities', 'like', '%"*"%');
    }
}
