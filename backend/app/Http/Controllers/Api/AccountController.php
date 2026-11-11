<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $controlParty = [
            (string) $this->settings()->get('accounting', 'ar_account_code', '1100') => 'customer',
            (string) $this->settings()->get('accounting', 'ap_account_code', '2100') => 'supplier',
        ];

        $accounts = Account::query()
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('search'), fn ($query, $search) => $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            }))
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => $account->toApiArray() + [
                'control_party_type' => $controlParty[$account->code] ?? null,
            ]);

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

    public function update(Request $request, Account $account, AuditService $audit): JsonResponse
    {
        // Block structural changes once the account has posted history.
        $hasHistory = DB::table('journal_lines')->where('account_id', $account->id)->exists()
            || DB::table('cash_accounts')->where('account_id', $account->id)->exists();

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account->id)],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'parent_id' => ['sometimes', 'nullable', 'string'],
            'is_postable' => $hasHistory ? ['sometimes', 'boolean', 'accepted'] : ['sometimes', 'boolean'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($hasHistory) {
            foreach (['code', 'type'] as $locked) {
                if (isset($data[$locked]) && $data[$locked] !== $account->{$locked}) {
                    return $this->errorResponse("The {$locked} cannot be changed once the account has postings.", 'account_has_history', 422);
                }
            }
        }

        if (isset($data['parent_id']) && ! empty($data['parent_id'])) {
            $parent = Account::query()->where('public_id', $data['parent_id'])->first();
            if (! $parent) {
                return $this->errorResponse('Parent account not found.', 'parent_not_found', 422);
            }
            if ($parent->id === $account->id) {
                return $this->errorResponse('An account cannot be its own parent.', 'self_parent', 422);
            }
            $accountId = $account->id;
            $ancestor = $parent;
            while ($ancestor = $ancestor->parent) {
                if ($ancestor->id === $accountId) {
                    return $this->errorResponse('Circular parent reference detected.', 'circular_parent', 422);
                }
            }
        }

        $before = $account->only(array_keys($data));
        $account->update(array_filter($data, fn ($v) => $v !== null, ARRAY_FILTER_USE_BOTH));
        $audit->log('account.updated', $account, $before, $account->toApiArray(), $this->actorId());

        return $this->ok($account->refresh()->toApiArray());
    }

    public function export(Request $request): Response
    {
        $accounts = Account::query()
            ->orderBy('code')
            ->get();

        $rows = [['Code', 'Name (AR)', 'Name (EN)', 'Type', 'Parent Code', 'Postable', 'Active', 'Currency']];
        foreach ($accounts as $account) {
            $rows[] = [
                $account->code,
                $account->name_ar,
                $account->name_en,
                $account->type,
                $account->parent?->code ?? '',
                $account->is_postable ? 'Yes' : 'No',
                $account->is_active ? 'Yes' : 'No',
                $account->currency_code ?? '',
            ];
        }

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="chart-of-accounts.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'preview_only' => ['nullable', 'boolean'],
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            throw new \DomainException('Unable to read the import file.');
        }

        $header = array_map(fn ($h) => trim((string) $h), $header);
        $required = ['code', 'name_ar', 'name_en', 'type'];
        foreach ($required as $field) {
            if (! in_array($field, $header, true)) {
                throw new \DomainException("Missing required column: {$field}");
            }
        }

        $preview = [];
        $errors = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $data = array_combine($header, array_map(fn ($v) => trim((string) $v), $row));
            $code = $data['code'] ?? '';
            $nameAr = $data['name_ar'] ?? '';
            $nameEn = $data['name_en'] ?? '';
            $type = $data['type'] ?? '';

            if ($code === '' || $nameAr === '' || $nameEn === '') {
                $errors[] = "Row {$rowNum}: code, name_ar, name_en are required.";

                continue;
            }
            if (! in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
                $errors[] = "Row {$rowNum}: invalid type '{$type}'.";

                continue;
            }

            $account = Account::query()->where('code', $code)->first();
            $preview[] = [
                'row' => $rowNum,
                'code' => $code,
                'name_ar' => $data['name_ar'] ?? '',
                'name_en' => $data['name_en'] ?? '',
                'type' => $type,
                'action' => $account ? 'update' : 'create',
                'existing' => $account ? $account->toApiArray() : null,
            ];
        }
        fclose($handle);

        if (! empty($errors)) {
            return $this->errorResponse('Validation failed.', 'validation_failed', 422);
        }

        if ($request->boolean('preview_only')) {
            return $this->ok(['preview' => $preview, 'total' => count($preview)]);
        }

        DB::transaction(function () use ($preview) {
            foreach ($preview as $item) {
                $values = [
                    'name_ar' => $item['name_ar'],
                    'name_en' => $item['name_en'],
                    'type' => $item['type'],
                    'is_active' => true,
                ];
                if ($item['action'] === 'create') {
                    $values['code'] = $item['code'];
                    $values['is_postable'] = true;
                    Account::query()->create($values);
                } else {
                    Account::query()->where('code', $item['code'])->update($values);
                }
            }
        });

        return $this->ok(['imported' => count($preview), 'preview' => $preview]);
    }

    private function errorResponse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
