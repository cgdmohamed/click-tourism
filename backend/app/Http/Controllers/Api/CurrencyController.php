<?php

namespace App\Http\Controllers\Api;

use App\Modules\Money\Models\Currency;
use App\Modules\Money\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function show(string $code, CurrencyService $currencies): JsonResponse
    {
        return $this->ok($this->detail($this->find($code), $currencies));
    }

    public function store(Request $request, CurrencyService $currencies): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z]{3}$/', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'exponent' => ['required', 'integer', 'between:0,3'],
        ], [
            'code.regex' => 'The currency code must be exactly 3 letters (A-Z), for example USD.',
            'code.unique' => 'This currency code already exists.',
        ]);

        $currency = $currencies->create($data, $this->actorId());

        return $this->created($this->detail($currency, $currencies));
    }

    public function update(Request $request, string $code, CurrencyService $currencies): JsonResponse
    {
        $currency = $this->find($code);

        if ($request->has('code') && strtoupper(trim((string) $request->input('code'))) !== $currency->code) {
            throw ValidationException::withMessages([
                'code' => 'The currency code cannot be changed after creation.',
            ]);
        }

        $data = $request->validate([
            'code' => ['sometimes', 'string'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'symbol' => ['sometimes', 'nullable', 'string', 'max:10'],
            'exponent' => ['sometimes', 'required', 'integer', 'between:0,3'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        unset($data['code']);

        $currencies->update($currency, $data, $this->actorId());

        return $this->ok($this->detail($currency->refresh(), $currencies));
    }

    public function destroy(string $code, CurrencyService $currencies): JsonResponse
    {
        $currencies->delete($this->find($code), $this->actorId());

        return $this->ok(['deleted' => true]);
    }

    private function find(string $code): Currency
    {
        return Currency::query()->whereKey(strtoupper($code))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function detail(Currency $currency, CurrencyService $currencies): array
    {
        return $currencies->snapshot($currency) + [
            'is_base' => $currencies->isBase($currency->code),
            'in_use' => $currencies->isUsed($currency->code),
        ];
    }
}
