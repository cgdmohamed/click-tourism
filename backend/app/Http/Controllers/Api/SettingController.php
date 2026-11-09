<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends ApiController
{
    public function index(SettingService $settings): JsonResponse
    {
        return $this->ok($settings->all());
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
