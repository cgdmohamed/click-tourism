<?php

namespace App\Modules\Imports\Support;

use RuntimeException;

/**
 * A problem with the file as a whole (encoding, size, headers, row limit),
 * as opposed to a row-level validation error.
 */
class ImportFileException extends RuntimeException
{
    /** @param  array<string, mixed>  $params */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $params = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
