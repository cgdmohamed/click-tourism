<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Literal "contains" search for list endpoints used by pickers.
 *
 * `%` and `_` typed by a user must match themselves, so they are escaped and
 * the LIKE statement declares the escape character (SQLite needs this; it is
 * also valid on PostgreSQL).
 */
final class Like
{
    /** Escapes LIKE wildcards in a user supplied term. */
    public static function escape(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /** Normalised term, or null when the request carries no usable search text. */
    public static function term(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    /** Arabic letters that people type interchangeably (أ إ آ -> ا, ى -> ي, ة -> ه) are folded together. */
    public static function fold(string $text): string
    {
        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }

    /**
     * Adds `(col1 LIKE %word% OR col2 LIKE %word% ...)` for EVERY word of the term, so "Mohamed Darwish"
     * finds "Mohamed Mostafa Darwish", and Arabic spelling variants match each other.
     *
     * @param  Builder<*>  $query
     * @param  string[]  $columns
     */
    public static function contains(Builder $query, array $columns, string $term): void
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, 8);

        foreach ($words as $word) {
            $pattern = '%'.self::escape(self::fold($word)).'%';

            $query->where(function (Builder $query) use ($columns, $pattern) {
                $grammar = $query->getQuery()->getGrammar();
                foreach ($columns as $column) {
                    $folded = $grammar->wrap($column);
                    foreach (['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه'] as $from => $to) {
                        $folded = "replace({$folded}, '{$from}', '{$to}')";
                    }
                    $query->orWhereRaw("lower({$folded}) like lower(?) escape '!'", [$pattern]);
                }
            });
        }
    }
}
