<?php

namespace App\Http\Controllers\Api;

use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Attachments\Services\ExtractionService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Tax\Services\TaxPolicy;
use Illuminate\Http\JsonResponse;

/**
 * Non-sensitive installation flags every signed-in user may read, so the UI
 * can reflect server rules (e.g. tax entry) without `settings.view`.
 */
class FeatureFlagController extends ApiController
{
    public function show(SettingService $settings, TaxPolicy $tax, ProviderManager $assistant): JsonResponse
    {
        $policy = strtolower((string) $settings->get('accounting', 'credit_limit_policy', 'warn'));

        return $this->ok([
            'tax_enabled' => $tax->enabled(),
            'tax_default_rate' => $tax->rate(),
            'tax_inclusive' => $tax->inclusive(),
            'credit_limit_policy' => in_array($policy, ['off', 'warn', 'block'], true) ? $policy : 'warn',
            'base_currency' => $settings->baseCurrency(),
            'refund_segregation' => $settings->bool('accounting', 'refund_segregation', false),
            'mcp_enabled' => $settings->bool('mcp', 'enabled', false),
            // The assistant panel is offered only when it can answer (switched on, the data-processing
            // notice accepted, a provider chosen and its key set). ADR-0018.
            'assistant_enabled' => $assistant->usable(),
            'assistant_provider' => $assistant->usable() ? $assistant->providerName() : null,
            'assistant_key_configured' => $assistant->keyConfigured(),
            'attachments_extraction_enabled' => app(ExtractionService::class)->available(),
            'assistant_key_source' => $assistant->keySource(),
            'assistant_accepted' => $assistant->accepted(),
            'assistant_model' => $assistant->model(),
            'assistant_redact_pii' => $settings->bool('assistant', 'redact_pii', true),
            'assistant_privacy_url' => (string) $settings->get('assistant', 'privacy_url', ''),
        ]);
    }
}
