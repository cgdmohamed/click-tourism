<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;

/**
 * Non-sensitive installation flags every signed-in user may read, so the UI
 * can reflect server rules (e.g. tax entry) without `settings.view`.
 */
class FeatureFlagController extends ApiController
{
    public function show(SettingService $settings): JsonResponse
    {
        $policy = strtolower((string) $settings->get('accounting', 'credit_limit_policy', 'warn'));

        return $this->ok([
            'tax_enabled' => $settings->bool('tax', 'enabled', false),
            'credit_limit_policy' => in_array($policy, ['off', 'warn', 'block'], true) ? $policy : 'warn',
            'base_currency' => $settings->baseCurrency(),
            'refund_segregation' => $settings->bool('accounting', 'refund_segregation', false),
        ]);
    }
}
