<?php

namespace App\Modules\Imports\Support;

/**
 * Maps the uploaded header row onto template column keys. Accepts the machine
 * key, the English or Arabic label, or a legacy alias.
 */
final class HeaderMap
{
    /**
     * @param  list<ColumnDef>  $columns
     * @param  list<string>  $headerCells
     * @return array{map: array<int, string>, unknown: list<string>}
     */
    public static function build(array $columns, array $headerCells): array
    {
        $lookup = [];
        foreach ($columns as $column) {
            $names = [$column->key, str_replace('_', ' ', $column->key), $column->en, $column->ar, ...$column->aliases];
            foreach ($names as $name) {
                $lookup[self::normalise($name)] ??= $column->key;
            }
        }

        $map = [];
        $unknown = [];
        $seen = [];
        foreach ($headerCells as $index => $cell) {
            $normalised = self::normalise($cell);
            if ($normalised === '') {
                continue;
            }
            $key = $lookup[$normalised] ?? null;
            if ($key === null) {
                $unknown[] = mb_substr(trim($cell), 0, 60);

                continue;
            }
            if (isset($seen[$key])) {
                throw new ImportFileException('duplicate_column', "Column '{$key}' appears more than once in the header.", ['column' => $key]);
            }
            $seen[$key] = true;
            $map[$index] = $key;
        }

        $missing = [];
        foreach ($columns as $column) {
            if ($column->required && ! isset($seen[$column->key])) {
                $missing[] = $column->key;
            }
        }
        if ($missing !== []) {
            throw new ImportFileException(
                'missing_columns',
                'Missing required column(s): '.implode(', ', $missing).'.',
                ['columns' => $missing],
            );
        }

        return ['map' => $map, 'unknown' => $unknown];
    }

    public static function normalise(string $label): string
    {
        $label = str_replace(["\u{FEFF}", '*', "\u{0640}"], '', $label);
        $label = (string) preg_replace('/\s+/u', ' ', $label);

        return mb_strtolower(trim($label));
    }
}
