<?php

namespace App\Modules\Assistant\Providers;

use App\Modules\Assistant\Contracts\AssistantProvider;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Assistant\Support\ProviderReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions with function calling (ADR-0017).
 *
 * UNVERIFIED against the live API: written from the documented request/response shapes without
 * internet access. The credential comes from services.openai.key (env OPENAI_API_KEY) only; the
 * base URL (env OPENAI_BASE_URL) exists for proxies and tests. Failures raise ProviderException
 * with a generic message: the response body is never copied into messages or logs.
 */
final class OpenAiProvider implements AssistantProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.openai.key')) !== '';
    }

    public function chat(array $messages, array $tools, array $options): ProviderReply
    {
        if (! $this->isConfigured()) {
            throw new ProviderException('The OpenAI key is not configured.');
        }

        $payload = [
            'model' => $options['model'],
            'messages' => $messages,
            'max_completion_tokens' => $options['max_output_tokens'],
            // The provider is asked not to keep the conversation for its own features.
            'store' => false,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $options['allow_tools'] ? 'auto' : 'none';
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('assistant.provider_timeout', 60))
                ->post('/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new ProviderException('The provider could not be reached.');
        }

        if ($response->failed()) {
            throw new ProviderException('The provider answered with HTTP '.$response->status().'.');
        }

        $body = $response->json();
        $choice = is_array($body) ? ($body['choices'][0] ?? null) : null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;
        if (! is_array($message)) {
            throw new ProviderException('The provider answer has an unexpected shape.');
        }

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (! is_array($call) || ! is_string($call['id'] ?? null) || ! is_string($call['function']['name'] ?? null)) {
                continue;
            }
            $calls[] = [
                'id' => $call['id'],
                'name' => $call['function']['name'],
                'arguments' => is_string($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : '{}',
            ];
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new ProviderReply(
            is_string($message['content'] ?? null) ? $message['content'] : null,
            $calls,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
        );
    }

    /**
     * GET /models (ADR-0018): ids the key's account can use, with the provider's creation time.
     * The same timeout and bearer credential as chat apply; the response body never leaves here.
     */
    public function models(): array
    {
        if (! $this->isConfigured()) {
            throw new ProviderException('The OpenAI key is not configured.');
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
                ->acceptJson()
                ->timeout((int) config('assistant.provider_timeout', 60))
                ->get('/models');
        } catch (ConnectionException) {
            throw new ProviderException('The provider could not be reached.');
        }

        if ($response->failed()) {
            throw new ProviderException('The provider answered with HTTP '.$response->status().'.');
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['data'] ?? null) : null;
        if (! is_array($rows)) {
            throw new ProviderException('The provider answer has an unexpected shape.');
        }

        $models = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
                continue;
            }
            $models[] = [
                'id' => $row['id'],
                'created' => is_numeric($row['created'] ?? null) ? (int) $row['created'] : 0,
            ];
        }

        return $models;
    }
}
