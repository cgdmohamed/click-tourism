<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
