<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\DecisionRegistry;
use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SettingController extends ApiController
{
    public function index(SettingService $settings): JsonResponse
    {
        return $this->ok($settings->all());
    }

    public function decisions(DecisionRegistry $decisions): JsonResponse
    {
        return $this->ok($decisions->unresolved());
    }

    public function update(Request $request, SettingService $settings, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.group' => ['required', 'string', 'max:50'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable', 'string'],
            'settings.*.is_secret' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $errors = [];
        $policyChanges = [];

        foreach ($data['settings'] as $index => $setting) {
            $group = $setting['group'];
            $key = $setting['key'];
            $value = $setting['value'] ?? null;

            $error = $settings->accountMappingError($group, $key, $value);
            if ($error !== null) {
                $errors[$key] = [$error];
            }

            if ($group === 'accounting' && in_array($key, SettingService::POLICY_KEYS, true)) {
                if (! in_array($value, ['true', 'false'], true)) {
                    $errors[$key] = ["accounting.{$key} must be 'true' or 'false'."];

                    continue;
                }
                $old = $settings->bool('accounting', $key) ? 'true' : 'false';
                if ($old !== $value) {
                    if (! $user || ! $user->can('accounting.approve_policy')) {
                        throw new AccessDeniedHttpException('Changing the accounting policy requires the accounting.approve_policy permission.');
                    }
                    $policyChanges[$key] = ['old' => $old, 'new' => $value];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = $settings->all();

        DB::transaction(function () use ($data, $settings, $audit, $policyChanges, $user) {
            foreach ($data['settings'] as $setting) {
                $settings->set(
                    $setting['group'],
                    $setting['key'],
                    $setting['value'] ?? null,
                    (bool) ($setting['is_secret'] ?? false),
                );
            }

            foreach ($policyChanges as $key => $change) {
                if ($key === 'accounting_policy_approved') {
                    $approved = $change['new'] === 'true';
                    $settings->set('accounting', 'approved_by', $approved ? (string) ($user->name ?: $user->email) : '');
                    $settings->set('accounting', 'approved_at', $approved ? now()->toIso8601String() : '');
                }
                $audit->log(
                    'accounting.policy_changed',
                    null,
                    ['key' => $key, 'value' => $change['old']],
                    ['key' => $key, 'value' => $change['new'], 'actor_id' => $user->id],
                    $user->id,
                );
            }
        });

        $audit->log('settings.updated', null, $before, $settings->all(), $this->actorId());

        return $this->ok($settings->all());
    }
}
