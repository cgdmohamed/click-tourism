<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\DecisionRegistry;
use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $before = $settings->all();

        foreach ($data['settings'] as $setting) {
            $settings->set(
                $setting['group'],
                $setting['key'],
                $setting['value'] ?? null,
                (bool) ($setting['is_secret'] ?? false),
            );
        }

        $audit->log('settings.updated', null, $before, $settings->all(), $this->actorId());

        return $this->ok($settings->all());
    }
}
