<?php

namespace App\Http\Controllers\Api;

use App\Modules\Money\Models\Currency;
use Illuminate\Http\JsonResponse;

class CurrencyController extends ApiController
{
    public function index(): JsonResponse
    {
        $currencies = Currency::query()
            ->orderBy('code')
            ->get()
            ->map(fn (Currency $currency) => [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'exponent' => (int) $currency->exponent,
                'is_active' => (bool) $currency->is_active,
            ]);

        return $this->ok($currencies);
    }
}
