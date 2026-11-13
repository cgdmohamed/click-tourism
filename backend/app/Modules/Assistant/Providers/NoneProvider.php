<?php

namespace App\Modules\Assistant\Providers;

use App\Modules\Assistant\Contracts\AssistantProvider;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Assistant\Support\ProviderReply;

/** The assistant is disabled: no model is configured and nothing leaves the server. */
final class NoneProvider implements AssistantProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function chat(array $messages, array $tools, array $options): ProviderReply
    {
        throw new ProviderException('No assistant provider is configured.');
    }
}
