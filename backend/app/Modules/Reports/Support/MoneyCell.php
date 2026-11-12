<?php

namespace App\Modules\Reports\Support;

use Stringable;

/**
 * A money amount carried as an exact decimal string derived from integer minor
 * units. CSV/PDF print the string; XLSX writes it as a numeric cell with a
 * number format matching the currency exponent.
 */
final class MoneyCell implements Stringable
{
    public function __construct(public readonly string $decimal, public readonly int $exponent) {}

    public function __toString(): string
    {
        return $this->decimal;
    }
}
