<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Read-only MCP tokens (ADR-0016). Tokens carry only the "mcp:read"
 * ability and are stored with the "mcp:" name prefix to tell them apart from
 * SPA sessions. The plaintext value is returned once, on creation.
 *
 * Users manage their own tokens. A user with "mcp.admin" can also list
 * (?all=1) and revoke every user's MCP tokens; revocations are audited.
 */
class McpTokenController extends ApiController
{
    private const PREFIX = 'mcp:';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $request->boolean('all');

        if ($all && ! $user->can('mcp.admin')) {
            throw new AccessDeniedHttpException('Listing every user\'s MCP tokens requires the mcp.admin permission.');
        }

        $query = $all
            ? PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->with('tokenable')->limit(500)
            : $user->tokens();

        $tokens = $query
            ->where('name', 'like', self::PREFIX.'%')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token) => $this->present($token, $all))
            ->all();

        // The limits let the UI offer only expiry choices the server accepts.
        return response()->json([
            'data' => $tokens,
            'meta' => [
                'default_minutes' => min((int) config('mcp.token_default_minutes'), (int) config('mcp.token_max_minutes')),
                'max_minutes' => (int) config('mcp.token_max_minutes'),
                'max_active_per_user' => (int) config('mcp.max_tokens_per_user'),
            ],
        ]);
    }

    public function store(Request $request, AuditService $audit, SettingService $settings): JsonResponse
    {
        if (! $settings->bool('mcp', 'enabled', false)) {
            return ApiResponse::error('MCP access is switched off for this installation.', 'mcp_disabled', 403);
        }

        $max = (int) config('mcp.token_max_minutes');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:'.$max],
        ]);

        $user = $request->user();
        $active = $user->tokens()
            ->where('name', 'like', self::PREFIX.'%')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        if ($active >= (int) config('mcp.max_tokens_per_user')) {
            throw ValidationException::withMessages(['name' => ['Too many active MCP tokens; revoke one first.']]);
        }

        $name = trim($data['name']);
        // The installation's maximum also caps the default lifetime (omitting expires_in_minutes must not bypass it).
        $minutes = min($max, (int) ($data['expires_in_minutes'] ?? config('mcp.token_default_minutes')));
        $created = $user->createToken(self::PREFIX.$name, ['mcp:read'], now()->addMinutes($minutes));

        $audit->log('mcp.token_created', null, null, [
            'token_id' => $created->accessToken->getKey(),
            'name' => $name,
            'expires_at' => $created->accessToken->expires_at?->toIso8601String(),
        ], $user->id);

        return $this->ok($this->present($created->accessToken) + ['token' => $created->plainTextToken], 201);
    }

    public function destroy(Request $request, string $id, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        $query = $user->can('mcp.admin')
            ? PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())
            : $user->tokens();
        $token = $query->where('name', 'like', self::PREFIX.'%')->whereKey((int) $id)->first();
        abort_if($token === null, 404, 'Token not found.');

        $ownerId = (int) $token->tokenable_id;
        $token->delete();

        $after = [
            'token_id' => (int) $id,
            'name' => substr($token->name, strlen(self::PREFIX)),
        ];
        if ($ownerId !== $user->id) {
            $after += ['owner_id' => $ownerId, 'revoked_by_admin' => true];
        }
        $audit->log('mcp.token_revoked', null, null, $after, $user->id);

        return $this->ok(['message' => 'Token revoked.']);
    }

    /**
     * What the gateway needs to double-check its pinned branch: the branches the token's user may
     * use (the same rule as BranchScope). No profile data, no permissions.
     */
    public function context(Request $request, BranchScope $scope): JsonResponse
    {
        $user = $request->user();
        $allowed = $scope->allowedBranchIds($user);

        return $this->ok([
            'user_id' => $user->public_id,
            'branches' => Branch::query()->whereIn('id', $allowed)->orderBy('id')->pluck('public_id')->all(),
        ]);
    }

    /** @return array<string, mixed> Never includes the secret. */
    private function present(PersonalAccessToken $token, bool $withOwner = false): array
    {
        $row = [
            'id' => $token->getKey(),
            'name' => substr($token->name, strlen(self::PREFIX)),
            'abilities' => $token->abilities,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];

        if ($withOwner) {
            $owner = $token->tokenable;
            $row['user'] = $owner instanceof User ? ['id' => $owner->public_id, 'name' => $owner->name, 'email' => $owner->email] : null;
        }

        return $row;
    }
}
