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

    public function decisions(Request $request, DecisionRegistry $decisions): JsonResponse
    {
        $list = $request->boolean('all') ? $decisions->all() : $decisions->unresolved();

        return $this->ok($decisions->withResolutions($list));
    }

    public function resolveDecision(Request $request, string $id, DecisionRegistry $decisions, SettingService $settings, AuditService $audit): JsonResponse
    {
        abort_unless($decisions->exists($id), 404, 'Unknown decision.');

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,deferred'],
            'option' => ['nullable', 'string', 'max:100'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $before = $decisions->resolutions()[$id] ?? null;
        $resolution = array_filter([
            'status' => $data['status'],
            'option' => $data['option'] ?? null,
            'note' => $data['note'],
            'decided_by' => $user->public_id,
            'decided_by_name' => $user->name,
            'decided_at' => now()->toIso8601String(),
        ], static fn ($value): bool => $value !== null);

        $settings->set(DecisionRegistry::SETTINGS_GROUP, $id, json_encode($resolution, JSON_UNESCAPED_UNICODE));
        $audit->log('decision.resolved', null, ['decision' => $id, 'resolution' => $before], ['decision' => $id, 'resolution' => $resolution], $this->actorId());

        return $this->ok(['id' => $id, 'resolution' => $resolution]);
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
