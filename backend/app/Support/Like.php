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
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
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

    /**
     * Adds `(col1 LIKE %term% OR col2 LIKE %term% ...)` to the query.
     *
     * @param  Builder<*>  $query
     * @param  string[]  $columns
     */
    public static function contains(Builder $query, array $columns, string $term): void
    {
        $pattern = '%'.self::escape($term).'%';

        $query->where(function (Builder $query) use ($columns, $pattern) {
            $grammar = $query->getQuery()->getGrammar();
            foreach ($columns as $column) {
                $query->orWhereRaw($grammar->wrap($column)." like ? escape '\\'", [$pattern]);
            }
        });
    }
}
