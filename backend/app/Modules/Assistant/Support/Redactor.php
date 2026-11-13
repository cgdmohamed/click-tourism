<?php

namespace App\Modules\Assistant\Support;

/**
 * Replaces phone numbers, e-mail addresses and long number runs (tax ids) in free text with
 * placeholders before the text leaves the server (AT-12). The placeholder map lives only in this
 * object, i.e. in memory for one request: it lets the model's tool arguments and answer be
 * rendered back with what the user typed. It is never persisted or logged.
 *
 * Only text typed by the user goes through here. Tool results are masked structurally by
 * ResultSanitizer instead: running these patterns over JSON would also hit amounts and ids.
 */
final class Redactor
{
    private const DIGIT = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';

    /** @var array<string, string> original => placeholder */
    private array $forward = [];

    /** @var array<string, string> placeholder => original */
    private array $reverse = [];

    private int $counter = 0;

    public function __construct(private readonly bool $enabled = true) {}

    public function redact(string $text): string
    {
        if (! $this->enabled || $text === '') {
            return $text;
        }

        $text = (string) preg_replace_callback(
            '/[^\s@<>()"\',;\[\]]+@[^\s@<>()"\',;\[\]]+\.[^\s@<>()"\',;\[\]]+/u',
            fn (array $m) => $this->placeholder('EMAIL', $m[0]),
            $text,
        );

        // 9-15 digits, optionally with a leading + or 00 and spaces, hyphens or brackets between digits.
        $digit = self::DIGIT;

        return (string) preg_replace_callback(
            "/(?<![\\w])(?:\\+|00)?{$digit}(?:[\\s().-]?{$digit}){8,14}(?![\\w])/u",
            fn (array $m) => $this->placeholder('PHONE', $m[0]),
            $text,
        );
    }

    /** Puts the user's own values back (tool arguments, rendered answer). Unknown placeholders stay as they are. */
    public function restore(string $text): string
    {
        return $this->reverse === [] ? $text : strtr($text, $this->reverse);
    }

    public function count(): int
    {
        return count($this->reverse);
    }

    private function placeholder(string $kind, string $original): string
    {
        if (isset($this->forward[$original])) {
            return $this->forward[$original];
        }

        $placeholder = '['.$kind.'_'.(++$this->counter).']';
        $this->forward[$original] = $placeholder;
        $this->reverse[$placeholder] = $original;

        return $placeholder;
    }
}
