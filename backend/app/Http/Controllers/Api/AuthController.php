<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function login(Request $request, AuditService $audit): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        if (! $user->is_active) {
            return ApiResponse::error('This account is disabled.', 'account_disabled', 403);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'spa')->plainTextToken;
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $user, null, null, $user->id);

        return $this->ok([
            'token' => $token,
            'user' => $user->load('branches', 'roles', 'permissions')->toApiArray(),
        ]);
    }

    public function logout(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
            $audit->log('auth.logout', $user, null, null, $user->id);
        }

        return $this->ok(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($request->user()->load('branches', 'roles', 'permissions')->toApiArray());
    }

    /** Own profile: locale and password. A password change revokes every other token. */
    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', 'in:ar,en'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'string', 'min:12'],
        ]);

        $user = $request->user();

        if (isset($data['password'])
            && (! isset($data['current_password']) || ! Hash::check($data['current_password'], $user->password))) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        DB::transaction(function () use ($user, $data, $audit): void {
            if (isset($data['locale']) && $data['locale'] !== $user->locale) {
                $user->forceFill(['locale' => $data['locale']])->save();
                $audit->log('user.profile_updated', $user, null, ['locale' => $data['locale']], $user->id);
            }

            if (isset($data['password'])) {
                $current = $user->currentAccessToken();
                $tokens = $user->tokens();
                if ($current !== null) {
                    $tokens->whereKeyNot($current->id);
                }
                $tokens->delete();

                $user->forceFill(['password' => $data['password']])->save();
                $audit->log('user.password_changed', $user, null, null, $user->id);
            }
        });

        return $this->ok($user->refresh()->load('branches', 'roles', 'permissions')->toApiArray());
    }
}
