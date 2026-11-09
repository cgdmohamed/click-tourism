<?php

namespace App\Modules\Money\Services;

use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Models\FxRate;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * FX rate resolution and conversion (ADR-0003).
 *
 * A stored rate means: 1 unit of `currency_code` = `rate` units of the
 * installation base currency. Documents snapshot the rate/date/source at
 * issue time; posted history never re-reads the current rate.
 */
class FxService
{
    public function __construct(private readonly SettingService $settings) {}

    public function baseCurrency(): string
    {
        return $this->settings->baseCurrency();
    }

    /**
     * Resolve the rate for a currency on a date (latest rate on/before it).
     */
    public function rateFor(string $currency, string $date, ?string $baseCurrency = null, string $source = 'manual'): string
    {
        $currency = strtoupper($currency);
        $baseCurrency = strtoupper($baseCurrency ?? $this->baseCurrency());

        if ($currency === $baseCurrency) {
            return '1';
        }

        $rate = FxRate::query()
            ->where('currency_code', $currency)
            ->whereDate('rate_date', '<=', $date)
            ->when($source !== 'any', fn ($query) => $query->where('source', $source))
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->value('rate');

        if ($rate === null) {
            throw new DomainException("No FX rate for {$currency} on or before {$date}. Enter a rate before posting.");
        }

        return (string) $rate;
    }

    /**
     * Snapshot used when confirming a document.
     *
     * @return array{rate: string, date: string, source: string}
     */
    public function snapshot(string $currency, ?string $date = null, ?string $baseCurrency = null, string $source = 'manual'): array
    {
        $date = $date ?? Carbon::now()->toDateString();
        $currency = strtoupper($currency);
        $baseCurrency = strtoupper($baseCurrency ?? $this->baseCurrency());

        if ($currency === $baseCurrency) {
            return ['rate' => '1', 'date' => $date, 'source' => 'base'];
        }

        $resolved = FxRate::query()
            ->where('currency_code', $currency)
            ->whereDate('rate_date', '<=', $date)
            ->when($source !== 'any', fn ($query) => $query->where('source', $source))
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();

        if (! $resolved) {
            throw new DomainException("No FX rate for {$currency} on or before {$date}. Enter a rate before posting.");
        }

        return [
            'rate' => (string) $resolved->rate,
            'date' => $resolved->rate_date->toDateString(),
            'source' => $resolved->source,
        ];
    }

    public function convert(Money $money, string $baseCurrency, string $rate): Money
    {
        return $money->convertTo($baseCurrency, $rate);
    }

    public function upsertRate(string $currency, string $rate, string $date, string $source = 'manual'): FxRate
    {
        $currency = strtoupper($currency);
        $baseCurrency = $this->baseCurrency();

        if ($currency === $baseCurrency) {
            throw new DomainException('The base currency always has a rate of 1 and cannot be stored as an FX rate.');
        }

        if (! is_numeric($rate) || bccomp($rate, '0', 10) <= 0) {
            throw new DomainException('FX rate must be a positive decimal.');
        }

        $date = Carbon::parse($date)->toDateString();

        $existing = FxRate::query()
            ->where('currency_code', $currency)
            ->whereDate('rate_date', $date)
            ->where('source', $source)
            ->first();

        if ($existing) {
            $existing->update(['rate' => $rate]);

            return $existing;
        }

        return FxRate::query()->create([
            'currency_code' => $currency,
            'rate_date' => $date,
            'source' => $source,
            'rate' => $rate,
        ]);
    }
}
