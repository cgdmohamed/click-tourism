<?php

namespace App\Modules\Assistant\Support;

use RuntimeException;

/**
 * A provider call failed. The message never carries the provider's response body, request
 * payload or credential, so it is safe to log.
 */
final class ProviderException extends RuntimeException {}
