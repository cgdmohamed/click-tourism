<?php

namespace App\Modules\Attachments\Services;

use RuntimeException;

/** The file could not be written to the attachments disk. Nothing was recorded. */
final class AttachmentStorageException extends RuntimeException {}
