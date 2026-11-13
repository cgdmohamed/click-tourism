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
