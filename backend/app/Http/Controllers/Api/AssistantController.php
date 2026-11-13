<?php

namespace App\Http\Controllers\Api;

use App\Modules\Assistant\Services\ChatService;
use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Assistant\Support\ModelCatalogue;
use App\Modules\Assistant\Support\ProviderCredentials;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /assistant/chat (ADR-0017): the read-only in-product assistant. Stateless: the client sends
 * the conversation, nothing is stored. Permission assistant.use is enforced by the route.
 *
 * GET /assistant/models (ADR-0018): the models the configured provider offers, so an administrator
 * can pick one in Settings. Read-only: saving still needs settings.update.
 */
class AssistantController extends ApiController
{
    public function models(ProviderManager $providers): JsonResponse
    {
        // A fresh install keeps provider "none"; the key in the environment is enough to discover
        // models, so the administrator does not have to guess a model id before saving one.
        $discovered = $providers->discoveryProvider();
        if ($discovered === null) {
            return ApiResponse::error(
                'The assistant has no provider key configured. Save the provider key in Settings > Assistant.',
                'assistant_not_configured',
                503,
            );
        }

        try {
            $rows = $discovered['provider']->models();
        } catch (ProviderException) {
            // The provider body is never copied: it can name the key or the account.
            return ApiResponse::error('The assistant provider is unavailable. Try again later.', 'assistant_provider_error', 502);
        }

        $models = ModelCatalogue::chatModels($rows);
        $recommended = ModelCatalogue::recommend($models);

        return $this->ok([
            'key_configured' => true,
            'provider' => $discovered['name'],
            'recommended' => $recommended,
            'models' => array_map(
                static fn (array $model): array => ['id' => $model['id'], 'recommended' => $model['id'] === $recommended],
                $models,
            ),
        ]);
    }

    /** PUT /assistant/key: save the provider key (encrypted). The key is never returned. */
    public function storeKey(Request $request, ProviderCredentials $credentials, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
        ]);

        if (! $credentials->canEncrypt()) {
            // Nothing is written. The administrator fixes APP_KEY in the server environment.
            return ApiResponse::error(
                'The server encryption key (APP_KEY) is not valid, so the provider key cannot be stored. Set a valid APP_KEY in the server environment (php artisan key:generate --show) and restart.',
                'encryption_key_invalid',
                503,
            );
        }

        $before = $credentials->source();
        $credentials->store($data['api_key']);
        // The audit trail records that the key changed, never the key itself.
        $audit->log('assistant.key_saved', null, ['source' => $before], ['source' => 'settings'], $request->user()?->id);

        return $this->ok(['key_source' => $credentials->source()]);
    }

    /** DELETE /assistant/key: remove the saved key (an environment key, if any, then applies). */
    public function destroyKey(Request $request, ProviderCredentials $credentials, AuditService $audit): JsonResponse
    {
        $before = $credentials->source();
        $credentials->clear();
        $audit->log('assistant.key_removed', null, ['source' => $before], ['source' => $credentials->source()], $request->user()?->id);

        return $this->ok(['key_source' => $credentials->source()]);
    }

    public function chat(Request $request, ProviderManager $providers, ChatService $chat, AuditService $audit, BranchScope $scope): JsonResponse
    {
        $user = $request->user();

        if (! $providers->enabled()) {
            return $this->denied($audit, $request, 'disabled', 'The assistant is switched off for this installation.', 'assistant_disabled', 403);
        }
        if (! $providers->accepted()) {
            return $this->denied($audit, $request, 'not_accepted', 'Accept the data-processing notice in Settings, Assistant before using the assistant.', 'assistant_not_accepted', 403);
        }
        if (! $providers->keyConfigured()) {
            return $this->denied($audit, $request, 'not_configured', 'The assistant has no provider key configured. Ask an administrator.', 'assistant_not_configured', 503);
        }

        $max = (int) config('assistant.max_messages');
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.$max],
            'messages.*' => ['required', 'array:role,content'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:'.(int) config('assistant.max_message_chars')],
            'locale' => ['nullable', 'in:ar,en'],
        ]);
        $messages = array_values($data['messages']);
        if (end($messages)['role'] !== 'user') {
            return ApiResponse::error('The last message must come from the user.', 'validation_failed', 422, ['errors' => ['messages' => ['The last message must come from the user.']]]);
        }
        $locale = $data['locale'] ?? (in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'ar');

        if ($chat->tokensToday($user) >= $chat->tokenBudget()) {
            return $this->denied($audit, $request, 'budget_exceeded', 'The daily assistant budget of this user is used up. Try again tomorrow.', 'assistant_budget_exceeded', 429, [
                'Retry-After' => (string) max(1, (int) now()->diffInSeconds(now()->addDay()->startOfDay())),
            ]);
        }

        // An X-Branch-Id the user does not hold is a 403 here (BranchScope), before any provider call.
        $branch = $scope->resolve($user);

        try {
            $result = $chat->run($user, $messages, $locale, $branch);
        } catch (ProviderException) {
            return ApiResponse::error('The assistant provider is unavailable. Try again later.', 'assistant_provider_error', 502);
        }

        return $this->ok($result->payload());
    }

    private function denied(AuditService $audit, Request $request, string $reason, string $message, string $code, int $status, array $headers = []): JsonResponse
    {
        $audit->log('assistant.denied', null, null, ['reason' => $reason, 'status' => $status], $request->user()?->id);

        return ApiResponse::error($message, $code, $status)->withHeaders($headers);
    }
}
