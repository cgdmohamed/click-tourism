<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Own read-only MCP tokens (ADR-0016). Tokens carry only the "mcp:read"
 * ability and are stored with the "mcp:" name prefix to tell them apart from
 * SPA sessions. The plaintext value is returned once, on creation.
 */
class McpTokenController extends ApiController
{
    private const PREFIX = 'mcp:';

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->where('name', 'like', self::PREFIX.'%')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token) => $this->present($token))
            ->all();

        return $this->ok($tokens);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
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
        $token = $user->tokens()->where('name', 'like', self::PREFIX.'%')->whereKey((int) $id)->first();
        abort_if($token === null, 404, 'Token not found.');

        $token->delete();
        $audit->log('mcp.token_revoked', null, null, [
            'token_id' => (int) $id,
            'name' => substr($token->name, strlen(self::PREFIX)),
        ], $user->id);

        return $this->ok(['message' => 'Token revoked.']);
    }

    /** @return array<string, mixed> Never includes the secret. */
    private function present(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->getKey(),
            'name' => substr($token->name, strlen(self::PREFIX)),
            'abilities' => $token->abilities,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }
}
