<?php

namespace App\Modules\Integrations\Services;

/**
 * Phone helpers. Numbers are compared on a match key (last 9 digits) so
 * "+20 100 000 0000", "0100 000 0000" and "201000000000" are the same
 * contact. Short numbers fall back to their full digit string.
 */
final class PhoneNumber
{
    private const MATCH_DIGITS = 9;

    /** Digits only, "00" international prefix removed. */
    public static function digits(?string $raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }

    public static function matchKey(?string $raw): string
    {
        $digits = self::digits($raw);

        return strlen($digits) > self::MATCH_DIGITS ? substr($digits, -self::MATCH_DIGITS) : $digits;
    }

    /** "2010****0000" for logs and error messages. */
    public static function mask(?string $raw): string
    {
        $digits = self::digits($raw);
        if (strlen($digits) <= 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 7).substr($digits, -4);
    }

    /**
     * SQL fragment normalising a stored phone column to digits (portable
     * across SQLite and PostgreSQL; no LIKE escapes involved).
     */
    public static function sqlDigits(string $column): string
    {
        $expr = $column;
        foreach ([' ', '-', '+', '(', ')', '.', '/'] as $char) {
            $expr = "REPLACE({$expr}, '{$char}', '')";
        }

        return $expr;
    }
}
