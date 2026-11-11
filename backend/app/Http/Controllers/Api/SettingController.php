<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\DecisionRegistry;
use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $this->validateAccountingPolicyValues($data['settings']);

        $before = $settings->all();

        DB::transaction(function () use ($data, $settings) {
            foreach ($data['settings'] as $setting) {
                $settings->set(
                    $setting['group'],
                    $setting['key'],
                    $setting['value'] ?? null,
                    (bool) ($setting['is_secret'] ?? false),
                );
            }
        });

        $audit->log('settings.updated', null, $before, $settings->all(), $this->actorId());

        return $this->ok($settings->all());
    }

    /**
     * Value rules for the rounding policy and the fiscal year start.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function validateAccountingPolicyValues(array $items): void
    {
        foreach ($items as $index => $item) {
            if (($item['group'] ?? null) !== 'accounting') {
                continue;
            }
            $value = (string) ($item['value'] ?? '');

            if ($item['key'] === 'rounding_policy' && ! in_array($value, ['half_up', 'bankers'], true)) {
                throw ValidationException::withMessages([
                    "settings.{$index}.value" => 'The rounding policy must be half_up or bankers.',
                ]);
            }

            if ($item['key'] === 'fiscal_year_start' && ! self::isValidMonthDay($value)) {
                throw ValidationException::withMessages([
                    "settings.{$index}.value" => 'The fiscal year start must be a real MM-DD date (02-29 is not allowed).',
                ]);
            }
        }
    }

    /** Leap-safe: validated against a non-leap year so 02-29 is rejected. */
    public static function isValidMonthDay(string $value): bool
    {
        return preg_match('/^(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[1], (int) $m[2], 2001);
    }
}
