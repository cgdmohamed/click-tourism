<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $accounts = Account::query()
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('search'), fn ($query, $search) => $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            }))
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => $account->toApiArray());

        return $this->ok($accounts);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'parent_id' => ['nullable', 'string'],
            'is_postable' => ['nullable', 'boolean'],
            'currency_code' => ['nullable', 'string', 'size:3'],
        ]);

        $parent = ! empty($data['parent_id'])
            ? Account::query()->where('public_id', $data['parent_id'])->first()
            : null;

        if (! empty($data['parent_id']) && ! $parent) {
            return $this->errorResponse('Parent account not found.', 'parent_not_found', 422);
        }

        $account = Account::query()->create([
            'code' => $data['code'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'type' => $data['type'],
            'parent_id' => $parent?->id,
            'is_postable' => (bool) ($data['is_postable'] ?? true),
            'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null,
            'is_active' => true,
        ]);

        $audit->log('account.created', $account, null, $account->toApiArray(), $this->actorId());

        return $this->created($account->toApiArray());
    }

    private function errorResponse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
