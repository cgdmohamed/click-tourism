<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashAccountController extends ApiController
{
    /**
     * Registered cash and bank accounts. Until an installation registers any,
     * the previous behaviour (postable 11xx asset accounts) is kept so existing
     * payment screens keep working.
     */
    public function index(Request $request, TreasuryService $treasury): JsonResponse
    {
        $query = CashAccount::query()->with(['account', 'branch'])
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('id');

        $registered = $query->get();

        if ($registered->isEmpty() && ! CashAccount::query()->exists()) {
            return $this->ok($this->legacyAccounts());
        }

        $branch = $this->branch();

        return $this->ok($registered->map(
            fn (CashAccount $cashAccount) => $cashAccount->toApiArray($treasury->balance($cashAccount, $branch), $this->settings()->baseCurrency(), $treasury->nativeBalance($cashAccount, $branch)),
        )->values());
    }

    public function store(Request $request, TreasuryService $treasury): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['cash', 'bank'])],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'account_id' => ['nullable', 'string', Rule::exists('accounts', 'public_id'), 'required_without:account_code'],
            'account_code' => ['nullable', 'string', 'max:20', 'required_without:account_id'],
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'public_id')],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_last4' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ]);

        if (! empty($data['branch_id'])) {
            $branch = Branch::query()->where('public_id', $data['branch_id'])->firstOrFail();
            $this->authorizeBranchId($branch->id);
        }

        $cashAccount = $treasury->register($data, $this->actorId());

        return $this->created($cashAccount->toApiArray($treasury->balance($cashAccount, $this->branch()), $this->settings()->baseCurrency(), $treasury->nativeBalance($cashAccount, $this->branch())));
    }

    public function update(Request $request, CashAccount $cashAccount, TreasuryService $treasury, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_last4' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $cashAccount->only(array_keys($data));
        $cashAccount->update($data);
        $audit->log('cash_account.updated', $cashAccount, $before, $data, $this->actorId());

        return $this->ok($cashAccount->refresh()->load('account', 'branch')->toApiArray($treasury->balance($cashAccount, $this->branch()), $this->settings()->baseCurrency(), $treasury->nativeBalance($cashAccount, $this->branch())));
    }

    /** @return array<int, array<string, mixed>> */
    private function legacyAccounts(): array
    {
        $arCode = (string) $this->settings()->get('accounting', 'ar_account_code', '1100');

        return Account::query()
            ->where('type', 'asset')
            ->where('is_active', true)
            ->where('is_postable', true)
            ->where('code', 'like', '11%')
            ->where('code', '!=', $arCode)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->public_id,
                'code' => $account->code,
                'name_ar' => $account->name_ar,
                'name_en' => $account->name_en,
                'currency' => $account->currency_code ?? $this->settings()->baseCurrency(),
                'type' => str_contains(strtolower($account->name_en), 'bank') ? 'bank' : 'cash',
                'registered' => false,
            ])
            ->all();
    }
}
