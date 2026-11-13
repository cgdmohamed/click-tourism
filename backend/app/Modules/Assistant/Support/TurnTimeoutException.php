<?php

namespace App\Modules\Assistant\Support;

use RuntimeException;

/** The whole assistant turn used up its time budget (config assistant.turn_timeout). */
final class TurnTimeoutException extends RuntimeException {}
