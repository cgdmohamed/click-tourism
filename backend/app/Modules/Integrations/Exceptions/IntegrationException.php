<?php

namespace App\Modules\Integrations\Exceptions;

use RuntimeException;

/**
 * Failure talking to an external provider. `transient` failures (timeouts,
 * rate limits, 5xx) are retried with backoff; permanent ones are not.
 * The message must never contain tokens, phone numbers or message bodies.
 */
class IntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient = false,
        public readonly ?string $providerCode = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $what): self
    {
        return new self("Integration is not configured: {$what}.", false, 'not_configured');
    }
}
