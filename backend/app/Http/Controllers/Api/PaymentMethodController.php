<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $methods = PaymentMethod::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->map(fn (PaymentMethod $method) => $method->toApiArray());

        return $this->ok($methods);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:payment_methods,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'wallet', 'other'])],
            'default_cash_account_id' => ['nullable', 'string', Rule::exists('accounts', 'public_id')],
            'provider_name' => ['nullable', 'string', 'max:120'],
            'account_name' => ['nullable', 'string', 'max:160'],
            'account_reference' => ['nullable', 'string', 'max:80'],
            'iban' => ['nullable', 'string', 'max:34'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $method = PaymentMethod::query()->create([
            'code' => $data['code'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'type' => $data['type'],
            'default_cash_account_id' => ! empty($data['default_cash_account_id'])
                ? Account::query()->where('public_id', $data['default_cash_account_id'])->firstOrFail()->id
                : null,
            'provider_name' => $data['provider_name'] ?? null,
            'account_name' => $data['account_name'] ?? null,
            'account_reference' => $data['account_reference'] ?? null,
            'iban' => $data['iban'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $audit->log('payment_method.created', $method, null, $method->toApiArray(), $this->actorId());

        return $this->created($method->toApiArray());
    }

    public function update(Request $request, PaymentMethod $method, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('payment_methods')->ignore($method->id)],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['cash', 'bank_transfer', 'card', 'wallet', 'other'])],
            'default_cash_account_id' => ['nullable', 'string', Rule::exists('accounts', 'public_id')],
            'provider_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'account_reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:34'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $method->only(array_keys($data));
        if (array_key_exists('default_cash_account_id', $data)) {
            $data['default_cash_account_id'] = $data['default_cash_account_id']
                ? Account::query()->where('public_id', $data['default_cash_account_id'])->firstOrFail()->id
                : null;
        }
        $method->update($data);
        $audit->log('payment_method.updated', $method, $before, $method->toApiArray(), $this->actorId());

        return $this->ok($method->refresh()->toApiArray());
    }
}
