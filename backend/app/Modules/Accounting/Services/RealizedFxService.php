<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Core\Services\SettingService;
use App\Support\Money;

/**
 * Balance a two-sided foreign-currency settlement using the historical
 * carrying rate of the receivable/payable and the actual payment rate.
 * A base-currency realized gain/loss line replaces any artificial FX rounding.
 */
class RealizedFxService
{
    public function __construct(private readonly PostingService $posting, private readonly SettingService $settings) {}

    /**
     * @param array<int, PostingLine> $lines Each monetary line has its own currency/rate.
     * @return array<int, PostingLine>
     */
    public function balance(array $lines, string $transactionCurrency): array
    {
        $base = $this->settings->baseCurrency();
        if (strtoupper($transactionCurrency) === $base) {
            return $lines;
        }

        $debitBase = 0;
        $creditBase = 0;
        foreach ($lines as $line) {
            $currency = $line->currency ?? $transactionCurrency;
            $rate = $line->fxRate;
            if ($rate === null) {
                throw new \DomainException('Historical and settlement FX rates are required for realized FX calculation.');
            }
            $debitBase += (new Money($line->debitMinor, $currency))->convertTo($base, $rate)->amountMinor;
            $creditBase += (new Money($line->creditMinor, $currency))->convertTo($base, $rate)->amountMinor;
        }

        $difference = $debitBase - $creditBase;
        if ($difference > 0) {
            $gain = $this->posting->account((string) $this->settings->get('accounting', 'fx_gain_account_code', '7900'));
            $lines[] = PostingLine::credit($gain, $difference, null, null, 'Realized FX gain')->in($base, '1');
        } elseif ($difference < 0) {
            $loss = $this->posting->account((string) $this->settings->get('accounting', 'fx_loss_account_code', '6900'));
            $lines[] = PostingLine::debit($loss, abs($difference), null, null, 'Realized FX loss')->in($base, '1');
        }

        return $lines;
    }
}
