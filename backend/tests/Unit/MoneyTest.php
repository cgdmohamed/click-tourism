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

    public function test_half_up_is_the_default_and_rounds_ties_away_from_zero(): void
    {
        // 1 USD cent at 0.5/1.5/2.5 -> 0.5/1.5/2.5 EGP minor units (tie cases)
        $this->assertSame(1, (new Money(1, 'USD'))->convertTo('EGP', '0.5')->amountMinor);
        $this->assertSame(2, (new Money(1, 'USD'))->convertTo('EGP', '1.5')->amountMinor);
        $this->assertSame(3, (new Money(1, 'USD'))->convertTo('EGP', '2.5')->amountMinor);
        $this->assertSame(-3, (new Money(-1, 'USD'))->convertTo('EGP', '2.5')->amountMinor);
    }

    public function test_bankers_rounding_rounds_ties_to_even(): void
    {
        $convert = fn (int $minor, string $rate) => (new Money($minor, 'USD'))->convertTo('EGP', $rate, Money::ROUND_BANKERS)->amountMinor;

        $this->assertSame(0, $convert(1, '0.5'));
        $this->assertSame(2, $convert(1, '1.5'));
        $this->assertSame(2, $convert(1, '2.5'));
        $this->assertSame(4, $convert(1, '3.5'));
        $this->assertSame(0, $convert(-1, '0.5'));
        $this->assertSame(-2, $convert(-1, '1.5'));
        $this->assertSame(-2, $convert(-1, '2.5'));
    }

    public function test_bankers_rounding_matches_half_up_away_from_ties(): void
    {
        $convert = fn (int $minor, string $rate) => (new Money($minor, 'USD'))->convertTo('EGP', $rate, Money::ROUND_BANKERS)->amountMinor;

        // 100 cents at 48.505 = 4850.5 (tie -> even 4850); at 48.515 = 4851.5 (tie -> even 4852)
        $this->assertSame(4850, $convert(100, '48.505'));
        $this->assertSame(4852, $convert(100, '48.515'));
        $this->assertSame(3, $convert(1, '2.51'));
        $this->assertSame(2, $convert(1, '2.49'));
        $this->assertSame(-3, $convert(-1, '2.51'));
        $this->assertSame(-2, $convert(-1, '2.49'));
    }

    public function test_an_unknown_rounding_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(1, 'USD'))->convertTo('EGP', '1', 'floor');
    }
}
