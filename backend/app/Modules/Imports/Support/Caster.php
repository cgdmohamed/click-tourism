<?php

namespace App\Modules\Imports\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe type casting for imported cells. Everything arrives as a string and is
 * converted without floats: money is parsed digit by digit into minor units.
 */
class Caster
{
    /** @var array<string, int>|null currency code => exponent */
    private ?array $currencies = null;

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => ',',
    ];

    /** @return array<string, int> */
    public function currencies(): array
    {
        return $this->currencies ??= DB::table('currencies')->pluck('exponent', 'code')
            ->mapWithKeys(fn ($exponent, $code) => [strtoupper((string) $code) => (int) $exponent])->all();
    }

    public function exponent(string $currency): int
    {
        return $this->currencies()[strtoupper($currency)] ?? 2;
    }

    public static function asciiDigits(string $value): string
    {
        return strtr($value, self::DIGITS);
    }

    /**
     * @return array{0: mixed, 1: array{code: string, params: array<string, mixed>}|null}
     */
    public function cast(ColumnDef $column, string $raw, int $exponent = 2): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $column->required
                ? [null, ['code' => 'required', 'params' => []]]
                : [null, null];
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw)) {
            return [null, ['code' => 'invalid_characters', 'params' => []]];
        }

        return match ($column->type) {
            'email' => $this->email($column, $raw),
            'phone' => $this->phone($raw),
            'enum' => $this->enum($column, $raw),
            'bool' => $this->bool($raw),
            'currency' => $this->currency($raw),
            'money' => $this->money($raw, $exponent),
            'int' => $this->int($column, $raw),
            'decimal' => $this->decimal($raw),
            'date' => $this->date($raw),
            default => $this->string($column, $raw),
        };
    }

    private function string(ColumnDef $column, string $raw): array
    {
        $max = (int) ($column->options['max'] ?? 255);
        if (mb_strlen($raw) > $max) {
            return [null, ['code' => 'too_long', 'params' => ['max' => $max]]];
        }

        return [$raw, null];
    }

    private function email(ColumnDef $column, string $raw): array
    {
        if (mb_strlen($raw) > 255 || ! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [null, ['code' => 'invalid_email', 'params' => []]];
        }

        return [mb_strtolower($raw), null];
    }

    private function phone(string $raw): array
    {
        $phone = self::asciiDigits($raw);
        if (! preg_match('/^\+?[0-9][0-9 ().\-]{3,28}$/', $phone)) {
            return [null, ['code' => 'invalid_phone', 'params' => []]];
        }

        return [$phone, null];
    }

    private function enum(ColumnDef $column, string $raw): array
    {
        $value = mb_strtolower($raw);
        $value = $column->options['map'][$value] ?? $value;
        $allowed = $column->options['values'] ?? [];
        if (! in_array($value, $allowed, true)) {
            return [null, ['code' => 'invalid_choice', 'params' => ['allowed' => implode(', ', $allowed)]]];
        }

        return [$value, null];
    }

    private function bool(string $raw): array
    {
        return match (mb_strtolower($raw)) {
            'true', '1', 'yes', 'y', 'نعم' => [true, null],
            'false', '0', 'no', 'n', 'لا' => [false, null],
            default => [null, ['code' => 'invalid_boolean', 'params' => []]],
        };
    }

    private function currency(string $raw): array
    {
        $code = strtoupper($raw);
        if (! preg_match('/^[A-Z]{3}$/', $code) || ! isset($this->currencies()[$code])) {
            return [null, ['code' => 'invalid_currency', 'params' => ['value' => mb_substr($raw, 0, 10)]]];
        }

        return [$code, null];
    }

    /** Major-unit decimal string -> integer minor units, exact. */
    private function money(string $raw, int $exponent): array
    {
        $text = str_replace([',', ' ', "\u{00A0}"], '', self::asciiDigits($raw));
        if (! preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $text, $m)) {
            return [null, ['code' => 'invalid_amount', 'params' => []]];
        }

        $fraction = $m[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > $exponent) {
            return [null, ['code' => 'too_many_decimals', 'params' => ['max' => $exponent]]];
        }

        $fraction = str_pad(substr($fraction, 0, $exponent), $exponent, '0');

        return [(int) ($m[1].$fraction), null];
    }

    private function int(ColumnDef $column, string $raw): array
    {
        $text = self::asciiDigits($raw);
        if (! preg_match('/^\d{1,9}$/', $text)) {
            return [null, ['code' => 'invalid_integer', 'params' => []]];
        }
        $value = (int) $text;
        $min = $column->options['min'] ?? 0;
        if ($value < $min) {
            return [null, ['code' => 'too_small', 'params' => ['min' => $min]]];
        }

        return [$value, null];
    }

    /** Positive decimal kept as a string (fx rates). */
    private function decimal(string $raw): array
    {
        $text = self::asciiDigits($raw);
        if (! preg_match('/^\d{1,10}(\.\d{1,8})?$/', $text) || trim($text, '0.') === '') {
            return [null, ['code' => 'invalid_decimal', 'params' => []]];
        }

        return [$text, null];
    }

    private function date(string $raw): array
    {
        $text = self::asciiDigits($raw);
        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y'] as $format) {
            $parsed = Carbon::createFromFormat('!'.$format, $text);
            if ($parsed !== false && $parsed->format($format) === $text) {
                return [$parsed->toDateString(), null];
            }
        }
        // XLSX date cells arrive as "Y-m-d H:i:s".
        $parsed = Carbon::createFromFormat('!Y-m-d H:i:s', $text);
        if ($parsed !== false && $parsed->format('Y-m-d H:i:s') === $text) {
            return [$parsed->toDateString(), null];
        }

        return [null, ['code' => 'invalid_date', 'params' => []]];
    }
}
