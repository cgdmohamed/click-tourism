<?php

namespace App\Modules\Commissions\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Core\Services\RoundingPolicy;
use App\Modules\Core\Services\SettingService;
use App\Support\Money;

/**
 * Exact integer commission maths. Revenue and cost are converted to the base
 * currency with the booking's FX rate, then the rate (basis points) is applied
 * with integer division rounded by the installation's rounding policy. No
 * floating point is used anywhere (ADR-0003).
 */
class CommissionCalculator
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly RoundingPolicy $rounding,
    ) {}

    /**
     * @return array{basis_minor: int, amount_minor: int}
     */
    public function forBooking(CommissionRule $rule, Booking $booking): array
    {
        $base = $this->settings->baseCurrency();
        $currency = strtoupper($booking->currency_code);
        $rate = $currency === $base ? '1' : (string) $booking->fx_rate;
        $mode = $this->rounding->mode();

        // Tax collected for the state is not revenue: the basis is net of tax.
        $revenue = (new Money($booking->total_sell_minor - $booking->tax_minor, $currency))->convertTo($base, $rate, $mode)->amountMinor;
        $cost = (new Money($booking->total_cost_minor, $currency))->convertTo($base, $rate, $mode)->amountMinor;

        return $this->calculate($rule->basis, $rule->rate_bp, $rule->fixed_minor, $revenue, $cost, $mode);
    }

    /**
     * @return array{basis_minor: int, amount_minor: int}
     */
    public function calculate(string $basis, ?int $rateBp, ?int $fixedMinor, int $revenueMinor, int $costMinor, string $mode = Money::ROUND_HALF_UP): array
    {
        if ($basis === 'fixed') {
            return ['basis_minor' => 0, 'amount_minor' => max(0, (int) $fixedMinor)];
        }

        // A booking sold at or below cost earns no margin commission (DEC-025: no negative commission).
        $basisMinor = $basis === 'percent_margin' ? max(0, $revenueMinor - $costMinor) : max(0, $revenueMinor);

        return [
            'basis_minor' => $basisMinor,
            'amount_minor' => Money::divideRounded($basisMinor * (int) $rateBp, 10000, $mode),
        ];
    }
}
