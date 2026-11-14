<?php

namespace App\Modules\Assistant\Services;

use RuntimeException;

/** A refused confirm/reject (not found, already decided, expired, wrong branch). */
class ActionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }
}
