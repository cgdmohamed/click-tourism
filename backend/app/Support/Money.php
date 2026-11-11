<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Immutable money value object. Amounts are integer minor units; currency
 * determines the exponent. Floating point is never used (ADR-0003).
 */
final class Money
{
    /** @var array<string, int> */
    private const EXPONENTS = [
        'EGP' => 2, 'SAR' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'AED' => 2,
        'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JOD' => 3,
    ];

    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
        if (strlen($this->currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter code.');
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public static function fromDecimal(string $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $clean = str_replace([',', ' '], '', trim($amount));
        if (! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            throw new InvalidArgumentException("Invalid money amount: {$amount}");
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $scale = self::exponent($currency);
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        $minor = (int) $whole * (10 ** $scale) + (int) ($fraction === '' ? '0' : $fraction);

        return new self($negative ? -$minor : $minor, $currency);
    }

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public function toDecimal(): string
    {
        $scale = self::exponent($this->currency);
        $negative = $this->amountMinor < 0;
        $absolute = abs($this->amountMinor);
        $factor = 10 ** $scale;
        $whole = intdiv($absolute, $factor);
        $fraction = $absolute % $factor;

        if ($scale === 0) {
            return ($negative ? '-' : '').$whole;
        }

        return sprintf('%s%d.%s', $negative ? '-' : '', $whole, str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public const ROUND_HALF_UP = 'half_up';

    public const ROUND_BANKERS = 'bankers';

    /**
     * Convert to base-currency minor units using a decimal FX rate string.
     * Rounds at the base currency exponent: half-up (default) or bankers
     * (round half to even). The mode is passed in so Money never reads settings.
     */
    public function convertTo(string $baseCurrency, string $rate, string $rounding = self::ROUND_HALF_UP): self
    {
        if (! in_array($rounding, [self::ROUND_HALF_UP, self::ROUND_BANKERS], true)) {
            throw new InvalidArgumentException("Unknown rounding mode: {$rounding}");
        }

        $baseCurrency = strtoupper($baseCurrency);
        $sourceScale = self::exponent($this->currency);
        $baseScale = self::exponent($baseCurrency);
        $shift = $baseScale - $sourceScale;

        $raw = bcmul((string) $this->amountMinor, $rate, 12);
        $scaled = $shift >= 0
            ? bcmul($raw, (string) (10 ** $shift), 12)
            : bcdiv($raw, (string) (10 ** -$shift), 12);

        $rounded = $rounding === self::ROUND_BANKERS
            ? $this->roundBankers($scaled)
            : $this->roundHalfUp($scaled, 0);

        return new self((int) $rounded, $baseCurrency);
    }

    /** @return array{amount: string, amountMinor: int, currency: string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->toDecimal(),
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currency mismatch.');
        }
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        $factor = bcpow('10', (string) $scale, 0);
        $scaled = bcmul($value, $factor, 12);

        return bcdiv(bcadd($scaled, $scaled >= 0 ? '0.5' : '-0.5', 12), '1', 0);
    }

    /** Round half to even; ties (exactly .5) go to the nearest even integer. */
    private function roundBankers(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');
        $whole = bcdiv($absolute, '1', 0);
        $fraction = bcsub($absolute, $whole, 12);
        $comparison = bccomp($fraction, '0.5', 12);

        if ($comparison > 0 || ($comparison === 0 && bcmod($whole, '2') !== '0')) {
            $whole = bcadd($whole, '1', 0);
        }

        return $negative && $whole !== '0' ? '-'.$whole : $whole;
    }
}
