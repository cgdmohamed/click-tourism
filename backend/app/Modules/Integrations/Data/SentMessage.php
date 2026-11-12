<?php

namespace App\Modules\Integrations\Data;

final class SentMessage
{
    public function __construct(public readonly string $messageId) {}
}
