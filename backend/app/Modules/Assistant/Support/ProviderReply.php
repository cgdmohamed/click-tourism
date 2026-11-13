<?php

namespace App\Modules\Assistant\Support;

/** What one model call returned. Tool call arguments are the raw JSON string the model produced. */
final class ProviderReply
{
    /**
     * @param  list<array{id: string, name: string, arguments: string}>  $toolCalls
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly ?string $finishReason = null,
    ) {}
}
