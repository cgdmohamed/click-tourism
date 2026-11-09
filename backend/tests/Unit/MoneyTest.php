<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_round_trips_decimal_amounts_without_floats(): void
    {
        $money = Money::fromDecimal('1,234.56', 'EGP');

        $this->assertSame(123456, $money->amountMinor);
        $this->assertSame('1234.56', $money->toDecimal());
    }

    public function test_it_honours_three_decimal_currencies(): void
    {
        $money = Money::fromDecimal('10.125', 'KWD');

        $this->assertSame(10125, $money->amountMinor);
        $this->assertSame('10.125', $money->toDecimal());
    }

    public function test_it_converts_using_a_decimal_rate_with_half_up_rounding(): void
    {
        // 100.00 USD at 48.5 = 4850.00 EGP
        $converted = (new Money(10000, 'USD'))->convertTo('EGP', '48.5');
        $this->assertSame(485000, $converted->amountMinor);

        // 1.00 USD at 48.505 = 48.505 EGP -> 48.51 (half up at 2 dp)
        $rounded = (new Money(100, 'USD'))->convertTo('EGP', '48.505');
        $this->assertSame(4851, $rounded->amountMinor);
    }

    public function test_it_rounds_negative_amounts_symmetrically(): void
    {
        $converted = (new Money(-100, 'USD'))->convertTo('EGP', '48.505');

        $this->assertSame(-4851, $converted->amountMinor);
    }

    public function test_it_rejects_mismatched_currencies_when_adding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(100, 'EGP'))->add(new Money(100, 'USD'));
    }

    public function test_it_rejects_invalid_decimal_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('12.3.4', 'EGP');
    }
}
