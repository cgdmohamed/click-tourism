<?php

namespace App\Modules\Integrations\Exceptions;

use DomainException;

/** An outbound message was refused before queueing; `reason` is a stable API code. */
class OutboundRefusedException extends DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
