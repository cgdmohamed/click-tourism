<?php

namespace App\Http\Controllers\Api;

use App\Modules\Money\Models\FxRate;
use App\Modules\Money\Services\FxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FxRateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rates = FxRate::query()
            ->when($request->query('currency'), fn ($query, $currency) => $query->where('currency_code', strtoupper($currency)))
            ->orderByDesc('rate_date')
            ->limit(200)
            ->get()
            ->map(fn (FxRate $rate) => [
                'id' => (string) $rate->id,
                'currency' => $rate->currency_code,
                'rate' => (string) $rate->rate,
                'rate_date' => $rate->rate_date->toDateString(),
                'source' => $rate->source,
            ]);

        return $this->ok($rates);
    }

    public function store(Request $request, FxService $fx): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'string'],
            'rate_date' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $rate = $fx->upsertRate(
            $data['currency'],
            $data['rate'],
            $data['rate_date'],
            $data['source'] ?? 'manual',
        );

        return $this->created([
            'id' => (string) $rate->id,
            'currency' => $rate->currency_code,
            'rate' => (string) $rate->rate,
            'rate_date' => $rate->rate_date->toDateString(),
            'source' => $rate->source,
        ]);
    }
}
