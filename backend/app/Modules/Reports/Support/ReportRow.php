<?php

namespace App\Modules\Reports\Support;

/**
 * One report row. `kind` lets PDF/XLSX style header and total rows; CSV ignores it.
 */
final class ReportRow
{
    public const DATA = 'data';

    public const HEADER = 'header';

    public const TOTAL = 'total';

    /** @param array<int, string|int|null|MoneyCell|DateCell> $cells */
    public function __construct(public readonly array $cells, public readonly string $kind = self::DATA) {}
}
