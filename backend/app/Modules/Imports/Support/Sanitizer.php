<?php

namespace App\Modules\Imports\Support;

/**
 * Spreadsheet formula-injection ("CSV injection") defences.
 *
 * Inbound: cells that would execute as a formula when the stored value is
 * later exported and opened in Excel/LibreOffice are rejected row by row.
 * Outbound: every file the framework generates (templates, error reports)
 * prefixes risky cells with an apostrophe so they open as inert text.
 */
final class Sanitizer
{
    /** A leading + or - is fine when the rest is plain number/phone punctuation. */
    private const SAFE_SIGNED = '/^[+-]?[\d\s().,\-+]+$/u';

    /** Does this raw cell start like a spreadsheet formula / DDE payload? */
    public static function isFormula(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // Tab, CR and LF are used to smuggle a formula past a naive check.
        if (in_array($value[0], ["\t", "\r", "\n"], true)) {
            return true;
        }

        $trimmed = ltrim($value, " \u{00A0}");
        if ($trimmed === '') {
            return false;
        }

        if ($trimmed[0] === '=' || $trimmed[0] === '@') {
            return true;
        }

        if ($trimmed[0] === '+' || $trimmed[0] === '-') {
            return ! preg_match(self::SAFE_SIGNED, $trimmed);
        }

        return false;
    }

    /** Neutralise a value written into a generated CSV/XLSX file. */
    public static function forExport(mixed $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        if (in_array($value[0], ["\t", "\r", "\n", '=', '@'], true)) {
            return "'".$value;
        }

        if (($value[0] === '+' || $value[0] === '-') && ! preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
