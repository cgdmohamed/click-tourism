<?php

namespace App\Modules\Tax\Services;

use App\Modules\Core\Services\SettingService;
use DomainException;

/**
 * Single place that decides whether tax may be applied (settings `tax.enabled`,
 * `tax.default_rate` and `tax.inclusive`: one tax per installation). When tax is disabled no document may carry tax so
 * VAT is never recorded by accident.
 */
class TaxPolicy
{
    public const DISABLED_MESSAGE = 'Tax is disabled in settings';

    public function __construct(private readonly SettingService $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('tax', 'enabled', false);
    }

    /** The single installation tax rate in percent as a decimal string ('0' when unset/invalid). */
    public function rate(): string
    {
        $rate = trim((string) $this->settings->get('tax', 'default_rate', '0'));

        return is_numeric($rate) && bccomp($rate, '0', 4) > 0 ? $rate : '0';
    }

    /** True when entered prices already contain the tax (setting `tax.inclusive`). */
    public function inclusive(): bool
    {
        return $this->settings->bool('tax', 'inclusive', false);
    }

    /**
     * Tax in minor units for an entered amount, from the settings only.
     * Exclusive: amount * rate / 100. Inclusive: the tax contained in the amount,
     * amount * rate / (100 + rate). Integer-safe BCMath, rounded half up.
     */
    public function taxOn(int $amountMinor): int
    {
        $rate = $this->rate();
        if (! $this->enabled() || $amountMinor <= 0 || bccomp($rate, '0', 4) <= 0) {
            return 0;
        }
        $divisor = $this->inclusive() ? bcadd('100', $rate, 6) : '100';
        $raw = bcdiv(bcmul((string) $amountMinor, $rate, 8), $divisor, 8);

        return (int) bcdiv(bcadd($raw, '0.5', 8), '1', 0);
    }

    /** Reject any non-zero tax amount while tax is disabled. */
    public function assertTaxAllowed(int $taxMinor, ?string $prefix = null): void
    {
        if ($taxMinor !== 0 && ! $this->enabled()) {
            throw new DomainException(($prefix ? $prefix.': ' : '').self::DISABLED_MESSAGE.'.');
        }
    }
}
