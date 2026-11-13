<?php

namespace App\Modules\Assistant\Contracts;

use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Assistant\Support\ProviderReply;

/**
 * A chat model the assistant can talk to. Add a provider by implementing this interface and
 * registering its name in ProviderManager (ADR-0017). Implementations get already redacted
 * messages and must never log or return their credentials.
 */
interface AssistantProvider
{
    /** Name stored in the setting assistant.provider (none, openai, ...). */
    public function name(): string;

    /** Whether the provider can be called (a credential is present). 'none' is never configured. */
    public function isConfigured(): bool;

    /**
     * Models the account behind this provider can use, for the Settings model picker (ADR-0018).
     * Each row carries at least `id` and, where the provider reports it, `created` (Unix seconds).
     * Returns an empty list when the provider offers no catalogue (for example 'none').
     *
     * @return list<array{id: string, created?: int}>
     *
     * @throws ProviderException on any failure; the message is generic and safe to log
     */
    public function models(): array;

    /**
     * One model call.
     *
     * @param  list<array<string, mixed>>  $messages  OpenAI-style messages (system, user, assistant, tool)
     * @param  list<array<string, mixed>>  $tools  OpenAI-style function tool definitions
     * @param  array{model: string, max_output_tokens: int, allow_tools: bool}  $options
     *
     * @throws ProviderException on any failure; the message is generic and safe to log
     */
    public function chat(array $messages, array $tools, array $options): ProviderReply;
}
