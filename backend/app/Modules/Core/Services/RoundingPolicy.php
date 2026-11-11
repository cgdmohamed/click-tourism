<?php

namespace App\Modules\Core\Services;

use App\Support\Money;

/**
 * Resolves `accounting.rounding_policy` once per request so FX conversions can
 * pass the rounding mode to Money, which itself stays database-free.
 */
class RoundingPolicy
{
    private ?string $mode = null;

    public function __construct(private readonly SettingService $settings) {}

    public function mode(): string
    {
        return $this->mode ??= $this->resolve();
    }

    public function flush(): void
    {
        $this->mode = null;
    }

    private function resolve(): string
    {
        $value = strtolower((string) $this->settings->get('accounting', 'rounding_policy', Money::ROUND_HALF_UP));

        return $value === Money::ROUND_BANKERS ? Money::ROUND_BANKERS : Money::ROUND_HALF_UP;
    }
}
