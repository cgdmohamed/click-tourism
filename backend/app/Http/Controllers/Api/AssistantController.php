<?php

namespace App\Http\Controllers\Api;

use App\Modules\Assistant\Services\ChatService;
use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Assistant\Support\TurnTimeoutException;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /assistant/chat (ADR-0017): the read-only in-product assistant. Stateless: the client sends
 * the conversation, nothing is stored. Permission assistant.use is enforced by the route.
 */
class AssistantController extends ApiController
{
    public function chat(Request $request, ProviderManager $providers, ChatService $chat, AuditService $audit, BranchScope $scope): JsonResponse
    {
        $user = $request->user();

        if (! $providers->enabled()) {
            return $this->denied($audit, $request, 'disabled', 'The assistant is switched off for this installation.', 'assistant_disabled', 403);
        }
        if (! $providers->provider()->isConfigured()) {
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

        // A turn may legitimately take longer than PHP's max_execution_time under FPM (default 30 s). Only raise a
        // limit that exists and is too short: 0 means unlimited (CLI, tests, workers) and must stay that way.
        $needed = max(1, (int) config('assistant.turn_timeout', 100)) + 20;
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < $needed) {
            @set_time_limit($needed);
        }

        try {
            $result = $chat->run($user, $messages, $locale, $branch);
        } catch (TurnTimeoutException) {
            return ApiResponse::error('The assistant took too long to answer. Ask a narrower question.', 'assistant_timeout', 504);
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
