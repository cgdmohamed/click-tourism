<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportRun;

/**
 * One importable entity: a versioned template plus the logic that validates
 * and applies its rows. Bump version() whenever columns change incompatibly.
 */
interface ImportDefinition
{
    /** URL-safe key, e.g. "customers". */
    public function entity(): string;

    public function version(): int;

    /** @return array{en: string, ar: string} */
    public function labels(): array;

    /** @return list<ColumnDef> */
    public function columns(): array;

    /**
     * Template-level options supplied next to the file (e.g. as_of_date).
     *
     * @return array<string, array{rules: list<mixed>, en: string, ar: string, required: bool}>
     */
    public function options(): array;

    /**
     * Permissions the importing user needs for the chosen duplicate policy.
     *
     * @return list<string>
     */
    public function permissions(string $policy): array;

    public function supportsDuplicatePolicy(): bool;

    public function defaultPolicy(): string;

    /** All-or-nothing: any row error rolls the whole file back. */
    public function isAtomic(): bool;

    /**
     * @param  iterable<array{row: int, data: array<string, string>}>  $rows
     */
    public function process(ImportRun $run, iterable $rows): void;
}
