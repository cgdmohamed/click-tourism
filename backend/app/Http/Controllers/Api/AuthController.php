<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    /** POST /auth/forgot-password: e-mails a reset link. The answer is the same whether or not the account exists. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (in_array((string) config('mail.default'), ['log', 'array', 'null', ''], true)) {
            return ApiResponse::error('Password reset by e-mail is not set up on this server. Ask an administrator to reset your password.', 'password_reset_unavailable', 503);
        }

        $user = User::query()->where('email', $data['email'])->first();
        if ($user !== null && $user->is_active) {
            try {
                Password::sendResetLink(['email' => $data['email']]);
            } catch (\Throwable $e) {
                // A mail failure must not change the answer: a 500 only for real accounts would reveal
                // which e-mails exist (ADR-0031). The log has the error class, never the address or the message.
                Log::warning('password_reset.mail_failed', ['exception' => $e::class]);
            }
        }

        return $this->ok(['message' => 'If the account exists, a reset link has been sent.']);
    }

    /** POST /auth/reset-password: sets a new password from the e-mailed link, once, and ends every session. */
    public function resetPassword(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
        ]);

        $reset = null;
        $apply = function (User $user, string $password) use (&$reset) {
            if (! $user->is_active) {
                return;
            }
            $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
            $user->save();
            $user->tokens()->delete();
            $reset = $user;
        };
        $credentials = $data + ['password_confirmation' => $request->input('password_confirmation')];

        // A reset link (60 minutes) or an invitation link (seven days, ADR-0032): each has its own token table.
        $via = 'password_reset_by_link';
        $status = Password::broker('users')->reset($credentials, $apply);
        if ($status !== Password::PASSWORD_RESET) {
            $status = Password::broker('invites')->reset($credentials, $apply);
            $via = 'invite_accepted';
        }

        if ($status !== Password::PASSWORD_RESET || $reset === null) {
            return ApiResponse::error('This link is invalid or has expired. Ask for a new one.', 'invalid_reset_link', 422);
        }

        $audit->log($via === 'invite_accepted' ? 'user.invite_accepted' : 'user.password_reset_by_link', $reset, null, ['reason' => $via], $reset->id);

        return $this->ok(['message' => 'Password changed. Sign in with the new password.']);
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
