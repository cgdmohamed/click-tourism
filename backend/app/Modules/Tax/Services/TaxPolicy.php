<?php

namespace App\Modules\Tax\Services;

use App\Modules\Core\Services\SettingService;
use DomainException;

/**
 * Single place that decides whether tax may be applied (settings `tax.enabled`
 * and `tax.default_rate`). When tax is disabled no document may carry tax so
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

    /** Default exclusive tax rate in percent as a decimal string ('0' when unset/invalid). */
    public function defaultRate(): string
    {
        $rate = trim((string) $this->settings->get('tax', 'default_rate', '0'));

        return is_numeric($rate) && bccomp($rate, '0', 4) > 0 ? $rate : '0';
    }

    /** Reject any non-zero tax amount while tax is disabled. */
    public function assertTaxAllowed(int $taxMinor, ?string $prefix = null): void
    {
        if ($taxMinor !== 0 && ! $this->enabled()) {
            throw new DomainException(($prefix ? $prefix.': ' : '').self::DISABLED_MESSAGE.'.');
        }
    }
}
