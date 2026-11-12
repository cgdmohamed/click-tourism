<?php

namespace App\Modules\Reports\Support;

use Stringable;

/** An ISO calendar date (Y-m-d); XLSX writes it as a real date cell. */
final class DateCell implements Stringable
{
    public function __construct(public readonly string $date) {}

    public function __toString(): string
    {
        return $this->date;
    }
}
