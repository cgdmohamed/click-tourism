<?php

namespace App\Modules\Assistant\Support;

/** Result of one tool call. `text` is what the model sees; citations are built by the server only. */
final class ToolOutcome
{
    /**
     * @param  array{tool: string, entity: string, id: ?string, route: string, as_of: string}|null  $citation  the call itself
     * @param  list<array{tool: string, entity: string, id: string, route: string, as_of: string}>  $records  rows that can be cited by id
     */
    public function __construct(
        public readonly string $tool,
        public readonly bool $ok,
        public readonly ?string $code,
        public readonly string $text,
        public readonly bool $truncated = false,
        public readonly ?array $citation = null,
        public readonly array $records = [],
        public readonly bool $rejected = false,
    ) {}
}
