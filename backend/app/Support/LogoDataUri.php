<?php

namespace App\Support;

/**
 * Reads the installation letterhead logo as an inline image data URI.
 *
 * Shared by the report and invoice PDF renderers so both enforce the same
 * rules: PNG/JPEG only, no remote fetch, no path traversal outside
 * storage/app and a hard size cap (ADR-0014).
 */
final class LogoDataUri
{
    public const MAX_BYTES = 1048576;

    /**
     * @param  string  $value  a path relative to storage/app, or an image data URI
     */
    public static function fromValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'data:')) {
            if (preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $value, $m) !== 1) {
                return null;
            }
            $binary = base64_decode($m[2], true);
        } else {
            $base = realpath(storage_path('app'));
            $full = $base !== false ? realpath($base.DIRECTORY_SEPARATOR.ltrim($value, '/\\')) : false;
            if ($base === false || $full === false || ! str_starts_with($full, $base.DIRECTORY_SEPARATOR) || ! is_file($full)) {
                return null;
            }
            $binary = filesize($full) <= self::MAX_BYTES ? file_get_contents($full) : false;
        }

        if ($binary === false || $binary === '' || strlen($binary) > self::MAX_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        return 'data:'.$info['mime'].';base64,'.base64_encode($binary);
    }
}
