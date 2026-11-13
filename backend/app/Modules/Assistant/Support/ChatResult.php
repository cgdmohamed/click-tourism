<?php

namespace App\Modules\Assistant\Support;

/** Outcome of one chat turn. `toolNames` and the counters feed the audit row; they hold no content. */
final class ChatResult
{
    /**
     * @param  list<array{tool: string, entity: string, id: ?string, route: string, as_of: string}>  $citations
     * @param  list<string>  $toolNames  names of the allowlisted tools that were executed
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $citations,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly bool $truncated,
        public readonly string $status,
        public readonly array $toolNames,
        public readonly int $toolErrors,
        public readonly int $rejectedTools,
        public readonly int $rounds,
    ) {}

    public function payload(): array
    {
        return [
            'answer' => $this->answer,
            'citations' => $this->citations,
            'usage' => [
                'prompt' => $this->promptTokens,
                'completion' => $this->completionTokens,
                'total' => $this->promptTokens + $this->completionTokens,
            ],
            'truncated' => $this->truncated,
        ];
    }
}
