<?php

namespace App\Http\Controllers\Api;

use App\Modules\Assistant\Models\AssistantUpload;
use App\Modules\Assistant\Services\ActionException;
use App\Modules\Assistant\Services\ActionService;
use App\Modules\Assistant\Services\AssistantUploadService;
use App\Modules\Assistant\Services\ChatService;
use App\Modules\Assistant\Services\ConversationService;
use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Assistant\Support\ModelCatalogue;
use App\Modules\Assistant\Support\ProviderCredentials;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /assistant/chat (ADR-0017): the read-only in-product assistant. The client sends the
 * conversation; the answer turn is saved for the user's chat history unless saving is off (ADR-0022). Permission assistant.use is enforced by the route.
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

    public function chat(Request $request, ProviderManager $providers, ChatService $chat, ConversationService $history, AuditService $audit, BranchScope $scope): JsonResponse
    {
        $prepared = $this->prepare($request, $providers, $chat, $audit, $scope);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$messages, $locale, $branch, $conversationId, $uploads] = $prepared;

        try {
            $result = $chat->run($request->user(), $messages, $locale, $branch, null, $this->files($uploads));
        } catch (ProviderException) {
            return ApiResponse::error('The assistant provider is unavailable. Try again later.', 'assistant_provider_error', 502);
        }

        return $this->ok($this->payload($history, $request, $branch, $conversationId, $messages, $result, $uploads));
    }

    /**
     * POST /assistant/chat/stream (ADR-0022): the same turn as /chat, sent as newline-delimited JSON
     * so the screen can show what the assistant is doing. Events: thinking, tool, answer, error.
     * The checks that can refuse the request run first and answer with the normal JSON error.
     */
    public function stream(Request $request, ProviderManager $providers, ChatService $chat, ConversationService $history, AuditService $audit, BranchScope $scope): JsonResponse|StreamedResponse
    {
        $prepared = $this->prepare($request, $providers, $chat, $audit, $scope);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$messages, $locale, $branch, $conversationId, $uploads] = $prepared;
        $user = $request->user();

        return new StreamedResponse(function () use ($chat, $history, $request, $user, $messages, $locale, $branch, $conversationId, $uploads): void {
            $send = static function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $result = $chat->run($user, $messages, $locale, $branch, $send, $this->files($uploads));
                $send(['type' => 'answer', 'data' => $this->payload($history, $request, $branch, $conversationId, $messages, $result, $uploads)]);
            } catch (ProviderException) {
                $send(['type' => 'error', 'code' => 'assistant_provider_error', 'message' => 'The assistant provider is unavailable. Try again later.']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function conversations(Request $request, ConversationService $history, BranchScope $scope): JsonResponse
    {
        $user = $request->user();
        $rows = $history->list($user, $scope->resolve($user))->map(fn ($c) => [
            'id' => $c->public_id,
            'title' => $c->title,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ])->values();

        return $this->ok($rows);
    }

    public function conversation(Request $request, string $id, ConversationService $history, ActionService $actions): JsonResponse
    {
        $conversation = $history->find($request->user(), $id);
        if ($conversation === null) {
            return ApiResponse::error('Conversation not found.', 'not_found', 404);
        }

        return $this->ok([
            'id' => $conversation->public_id,
            'title' => $conversation->title,
            'messages' => $conversation->messages->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'citations' => $m->citations ?? [],
                'truncated' => $m->truncated,
                'actions' => $actions->cards($request->user(), $m->actions ?? [], $this->locale()),
            ])->values(),
        ]);
    }

    /** POST /assistant/actions/{id}/confirm (ADR-0023): the user approves a prepared action; it then runs as the user. */
    public function confirmAction(Request $request, string $id, ActionService $actions, BranchScope $scope, ProviderManager $providers, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        // The master switch is the kill switch (ADR-0023): a card prepared before it was turned off must not run.
        if (! $providers->enabled()) {
            return $this->denied($audit, $request, 'disabled', 'The assistant is switched off for this installation.', 'assistant_disabled', 403);
        }
        try {
            return $this->ok($actions->confirm($user, $scope->resolve($user), $id, $this->locale()));
        } catch (ActionException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->status);
        }
    }

    public function rejectAction(Request $request, string $id, ActionService $actions): JsonResponse
    {
        try {
            return $this->ok($actions->reject($request->user(), $id, $this->locale()));
        } catch (ActionException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->status);
        }
    }

    private function locale(): string
    {
        return in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'ar';
    }

    public function deleteConversation(Request $request, string $id, ConversationService $history): JsonResponse
    {
        $conversation = $history->find($request->user(), $id);
        if ($conversation === null) {
            return ApiResponse::error('Conversation not found.', 'not_found', 404);
        }
        $history->delete($conversation);

        return $this->ok(['deleted' => 1]);
    }

    public function deleteConversations(Request $request, ConversationService $history, BranchScope $scope): JsonResponse
    {
        $user = $request->user();

        return $this->ok(['deleted' => $history->deleteAll($user, $scope->resolve($user))]);
    }

    /**
     * POST /assistant/uploads (ADR-0028): a file to read with the next message (a transfer receipt,
     * a payment slip, a supplier invoice). Same switches as the chat; the file expires after a few hours.
     */
    public function upload(Request $request, ProviderManager $providers, AssistantUploadService $uploads, AuditService $audit): JsonResponse
    {
        if (! $providers->enabled()) {
            return $this->denied($audit, $request, 'disabled', 'The assistant is switched off for this installation.', 'assistant_disabled', 403);
        }
        if (! $providers->accepted()) {
            return $this->denied($audit, $request, 'not_accepted', 'Accept the data-processing notice in Settings, Assistant before using the assistant.', 'assistant_not_accepted', 403);
        }
        $request->validate(['file' => ['required', 'file', 'max:'.(int) config('attachments.max_size_kb')]]);

        try {
            $upload = $uploads->store($request->user(), $request->file('file'));
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), 'validation_failed', 422, ['errors' => ['file' => [$e->getMessage()]]]);
        }
        $audit->log('assistant.file_uploaded', null, null, ['upload' => $upload->public_id, 'mime' => $upload->mime, 'size_bytes' => $upload->size_bytes], $request->user()->id);

        return $this->created(['id' => $upload->public_id, 'name' => $upload->name, 'mime' => $upload->mime, 'size_bytes' => $upload->size_bytes, 'expires_at' => $upload->expires_at->toIso8601String()]);
    }

    /** @param  list<AssistantUpload>  $uploads */
    private function files(array $uploads): array
    {
        return $uploads === [] ? [] : app(AssistantUploadService::class)->contents($uploads);
    }

    /**
     * Shared checks of /chat and /chat/stream.
     *
     * @return JsonResponse|array{0: list<array{role: string, content: string}>, 1: string, 2: ?Branch, 3: ?string, 4: list<AssistantUpload>}
     */
    private function prepare(Request $request, ProviderManager $providers, ChatService $chat, AuditService $audit, BranchScope $scope): JsonResponse|array
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
            'conversation_id' => ['nullable', 'string', 'max:26'],
            // Files uploaded with POST /assistant/uploads, read together with the last message (ADR-0028).
            'attachments' => ['nullable', 'array', 'max:'.AssistantUploadService::MAX_PER_MESSAGE],
            'attachments.*' => ['string', 'max:26'],
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

        $uploads = [];
        if (! empty($data['attachments'])) {
            $uploads = app(AssistantUploadService::class)->resolve($user, $data['attachments']);
            if ($uploads === null) {
                return ApiResponse::error('An attached file was not found or has expired. Attach it again.', 'validation_failed', 422, ['errors' => ['attachments' => ['An attached file was not found or has expired. Attach it again.']]]);
            }
        }

        return [$messages, $locale, $branch, $data['conversation_id'] ?? null, $uploads];
    }

    /** @param  list<array{role: string, content: string}>  $messages */
    private function payload(ConversationService $history, Request $request, $branch, ?string $conversationId, array $messages, $result, array $uploads = []): array
    {
        $payload = $result->payload();
        // Only the file names are kept with the saved chat; the files themselves expire (ADR-0028).
        $names = array_map(fn ($u) => $u->name, $uploads);
        $userText = (string) end($messages)['content'].($names === [] ? '' : "\n[".implode(', ', $names).']');
        $saved = $history->saveTurn($request->user(), $branch, $conversationId, $userText, $payload['answer'], $payload['citations'], (bool) $payload['truncated'], array_column($payload['actions'], 'id'));
        $payload['conversation_id'] = $saved?->public_id;

        return $payload;
    }

    private function denied(AuditService $audit, Request $request, string $reason, string $message, string $code, int $status, array $headers = []): JsonResponse
    {
        $audit->log('assistant.denied', null, null, ['reason' => $reason, 'status' => $status], $request->user()?->id);

        return ApiResponse::error($message, $code, $status)->withHeaders($headers);
    }
}
