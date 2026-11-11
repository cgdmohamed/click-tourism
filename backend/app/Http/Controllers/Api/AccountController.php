<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends ApiController
{
    /** Export/import column order. */
    private const IMPORT_COLUMNS = ['code', 'name_ar', 'name_en', 'type', 'parent_code', 'is_postable', 'is_active', 'currency_code', 'classification'];

    /** Legacy human-readable headers accepted on import (lower-cased). */
    private const HEADER_ALIASES = [
        'name (ar)' => 'name_ar',
        'name (en)' => 'name_en',
        'parent code' => 'parent_code',
        'postable' => 'is_postable',
        'active' => 'is_active',
        'currency' => 'currency_code',
    ];

    /** @return list<string> every classification value (type compatibility is checked separately) */
    private function allClassifications(): array
    {
        return array_merge(...array_values(Account::CLASSIFICATIONS));
    }

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
            'classification' => ['nullable', 'string', Rule::in($this->allClassifications())],
        ]);

        if (! $this->classificationFitsType($data['classification'] ?? null, $data['type'])) {
            return $this->errorResponse("Classification '{$data['classification']}' is not valid for type {$data['type']}.", 'invalid_classification', 422);
        }

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
            'classification' => $data['classification'] ?? null,
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
            'classification' => ['sometimes', 'nullable', 'string', Rule::in($this->allClassifications())],
        ]);

        // Classification is a reporting attribute: editable even with postings.
        $classificationProvided = array_key_exists('classification', $data);
        $classification = $data['classification'] ?? null;
        unset($data['classification']);
        $effectiveType = $data['type'] ?? $account->type;

        if ($classificationProvided && ! $this->classificationFitsType($classification, $effectiveType)) {
            return $this->errorResponse("Classification '{$classification}' is not valid for type {$effectiveType}.", 'invalid_classification', 422);
        }

        if ($hasHistory) {
            foreach (['code', 'type'] as $locked) {
                if (isset($data[$locked]) && $data[$locked] !== $account->{$locked}) {
                    return $this->errorResponse("The {$locked} cannot be changed once the account has postings.", 'account_has_history', 422);
                }
            }
        }

        // Settings can point at this account (AR, tax, FX, ...). A stored
        // mapping must never be silently invalidated by editing the account it
        // references, otherwise posting fails later with a confusing error.
        $mappings = app(SettingService::class)->mappedSettingKeysForAccountCode((string) $account->code);
        if ($mappings !== []) {
            $used = implode(', ', $mappings);
            $blocked = null;
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $blocked = 'deactivated';
            } elseif (array_key_exists('is_postable', $data) && ! $data['is_postable']) {
                $blocked = 'made non-postable';
            } elseif (array_key_exists('type', $data) && $data['type'] !== $account->type) {
                $blocked = 'given another type';
            }

            if ($blocked !== null) {
                return $this->errorResponse(
                    "Account {$account->code} cannot be {$blocked} while settings use it ({$used}). Point those settings to another account first.",
                    'account_used_by_settings',
                    422,
                );
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

        $before = $account->only(array_keys($data) + ['classification']);
        $update = array_filter($data, fn ($v) => $v !== null, ARRAY_FILTER_USE_BOTH);
        if ($classificationProvided) {
            $update['classification'] = $classification;
        } elseif (! $this->classificationFitsType($account->classification, $effectiveType)) {
            // The type changed (only possible without postings): drop the stale classification.
            $update['classification'] = null;
        }
        $account->update($update);
        $audit->log('account.updated', $account, $before, $account->toApiArray(), $this->actorId());

        return $this->ok($account->refresh()->toApiArray());
    }

    public function export(Request $request): Response
    {
        $accounts = Account::query()
            ->orderBy('code')
            ->get();

        // Headers match the import format so an export can be re-imported.
        $rows = [self::IMPORT_COLUMNS];
        foreach ($accounts as $account) {
            $rows[] = [
                $account->code,
                $account->name_ar,
                $account->name_en,
                $account->type,
                $account->parent?->code ?? '',
                $account->is_postable ? 'true' : 'false',
                $account->is_active ? 'true' : 'false',
                $account->currency_code ?? '',
                $account->classification ?? '',
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

        $rows = $this->readImportFile($request->file('file')->getRealPath());
        // Preview and real import share this single validation pass.
        [$preview, $rowErrors] = $this->validateImportRows($rows);

        if ($rowErrors !== []) {
            return response()->json([
                'message' => 'Validation failed.',
                'code' => 'validation_failed',
                'row_errors' => $rowErrors,
            ], 422);
        }

        if ($request->boolean('preview_only')) {
            return $this->ok(['preview' => $this->publicPreview($preview), 'total' => count($preview)]);
        }

        DB::transaction(function () use ($preview) {
            $byCode = [];
            foreach ($preview as $item) {
                $values = [
                    'name_ar' => $item['name_ar'],
                    'name_en' => $item['name_en'],
                    'type' => $item['type'],
                ];
                foreach (['is_postable', 'is_active', 'currency_code', 'classification'] as $optional) {
                    if ($item['provided'][$optional]) {
                        $values[$optional] = $item[$optional];
                    }
                }
                // A new account, or one whose type changed, takes the previewed (validated) value.
                $values['classification'] = $item['classification'];

                if ($item['action'] === 'create') {
                    $values += ['code' => $item['code'], 'is_postable' => true, 'is_active' => true];
                    $byCode[$item['code']] = Account::query()->create($values);
                } else {
                    $account = Account::query()->where('code', $item['code'])->firstOrFail();
                    $account->update($values);
                    $byCode[$item['code']] = $account;
                }
            }

            // Parents are linked after all rows exist so file order does not matter.
            foreach ($preview as $item) {
                if (! $item['provided']['parent_code']) {
                    continue;
                }
                $parentId = $item['parent_code'] !== ''
                    ? ($byCode[$item['parent_code']]->id ?? Account::query()->where('code', $item['parent_code'])->value('id'))
                    : null;
                $byCode[$item['code']]->update(['parent_id' => $parentId]);
            }
        });

        return $this->ok(['imported' => count($preview), 'preview' => $this->publicPreview($preview)]);
    }

    /** Strip internal bookkeeping before returning preview rows. */
    private function publicPreview(array $preview): array
    {
        return array_map(function (array $item) {
            unset($item['provided']);

            return $item;
        }, $preview);
    }

    /**
     * @return list<array{row: int, data: array<string, string>}>
     */
    private function readImportFile(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            throw new \DomainException('Unable to read the import file.');
        }

        $columns = array_map(function ($h) {
            $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));

            return self::HEADER_ALIASES[$h] ?? $h;
        }, $header);

        foreach (['code', 'name_ar', 'name_en', 'type'] as $field) {
            if (! in_array($field, $columns, true)) {
                throw new \DomainException("Missing required column: {$field}");
            }
        }

        $rows = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $row = array_pad(array_slice($row, 0, count($columns)), count($columns), '');
            $rows[] = ['row' => $rowNum, 'data' => array_combine($columns, array_map(fn ($v) => trim((string) $v), $row))];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array{row: int, data: array<string, string>}>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function validateImportRows(array $rows): array
    {
        $preview = [];
        $errors = [];
        $error = function (int $row, string $code, string $message, array $params = []) use (&$errors) {
            $errors[] = ['row' => $row, 'code' => $code, 'message' => "Row {$row}: {$message}", 'params' => $params];
        };

        $existing = Account::query()->get()->keyBy('code');
        $codeById = $existing->mapWithKeys(fn ($a) => [$a->id => $a->code])->all();
        $withHistory = array_flip(array_merge(
            DB::table('journal_lines')->distinct()->pluck('account_id')->all(),
            DB::table('cash_accounts')->distinct()->pluck('account_id')->all(),
        ));
        $currencies = DB::table('currencies')->pluck('code')->map(fn ($c) => strtoupper($c))->all();

        // Final parent map (code => parent code) used for existence and cycle checks.
        $parentOf = [];
        foreach ($existing as $code => $account) {
            $parentOf[$code] = $account->parent_id ? ($codeById[$account->parent_id] ?? null) : null;
        }
        $fileCodes = [];
        foreach ($rows as $r) {
            $fileCodes[$r['data']['code'] ?? ''] = true;
            if (array_key_exists('parent_code', $r['data'])) {
                $parentOf[$r['data']['code']] = $r['data']['parent_code'] !== '' ? $r['data']['parent_code'] : null;
            }
        }

        $seen = [];
        foreach ($rows as $r) {
            $n = $r['row'];
            $d = $r['data'];
            $before = count($errors);
            $code = $d['code'] ?? '';
            $type = strtolower($d['type'] ?? '');

            if ($code === '' || ($d['name_ar'] ?? '') === '' || ($d['name_en'] ?? '') === '') {
                $error($n, 'required', 'code, name_ar, name_en are required.');

                continue;
            }
            if (strlen($code) > 20 || mb_strlen($d['name_ar']) > 255 || mb_strlen($d['name_en']) > 255) {
                $error($n, 'too_long', 'code (max 20) or a name (max 255) is too long.');
            }
            if (isset($seen[$code])) {
                $error($n, 'duplicate_code', "code '{$code}' is repeated (first on row {$seen[$code]}).", ['code' => $code, 'first' => (string) $seen[$code]]);
            }
            $seen[$code] ??= $n;
            if (! in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
                $error($n, 'invalid_type', "invalid type '{$d['type']}'.", ['type' => $d['type']]);
            }

            $provided = [];
            $values = [];
            foreach (['is_postable', 'is_active'] as $flag) {
                $provided[$flag] = ($d[$flag] ?? '') !== '';
                $values[$flag] = null;
                if ($provided[$flag]) {
                    $bool = $this->parseBool($d[$flag]);
                    if ($bool === null) {
                        $error($n, 'invalid_boolean', "{$flag} must be true or false.", ['field' => $flag, 'value' => $d[$flag]]);
                    }
                    $values[$flag] = $bool;
                }
            }
            $provided['currency_code'] = array_key_exists('currency_code', $d);
            $currency = $provided['currency_code'] && $d['currency_code'] !== '' ? strtoupper($d['currency_code']) : null;
            if ($currency !== null && ! in_array($currency, $currencies, true)) {
                $error($n, 'invalid_currency', "unknown currency '{$currency}'.", ['currency' => $currency]);
            }
            $provided['classification'] = array_key_exists('classification', $d);
            $classification = $provided['classification'] && $d['classification'] !== '' ? strtolower($d['classification']) : null;
            if ($classification !== null && ! in_array($classification, Account::classificationsFor($type), true)) {
                $error($n, 'invalid_classification', "classification '{$classification}' is not valid for type '{$type}'.", ['classification' => $classification, 'type' => $type]);
            }
            $provided['parent_code'] = array_key_exists('parent_code', $d);
            $parentCode = $d['parent_code'] ?? '';
            if ($parentCode !== '') {
                if ($parentCode === $code) {
                    $error($n, 'self_parent', 'an account cannot be its own parent.');
                } elseif (! isset($existing[$parentCode]) && ! isset($fileCodes[$parentCode])) {
                    $error($n, 'parent_not_found', "parent '{$parentCode}' not found.", ['parent' => $parentCode]);
                } else {
                    $guard = 0;
                    for ($c = $parentCode; $c !== null && $guard++ < 10000; $c = $parentOf[$c] ?? null) {
                        if ($c === $code) {
                            $error($n, 'circular_parent', 'circular parent reference detected.');
                            break;
                        }
                    }
                }
            }

            $account = $existing[$code] ?? null;
            if ($account && isset($withHistory[$account->id])) {
                $suffix = 'because it has postings or a cash/bank registration.';
                if ($type !== '' && $type !== $account->type) {
                    $error($n, 'account_has_history', "the type of account '{$code}' cannot be changed {$suffix}", ['code' => $code, 'field' => 'type']);
                }
                if ($provided['parent_code']) {
                    $currentParent = $account->parent_id ? ($codeById[$account->parent_id] ?? null) : null;
                    if (($parentCode !== '' ? $parentCode : null) !== $currentParent) {
                        $error($n, 'account_has_history', "the parent of account '{$code}' cannot be changed {$suffix}", ['code' => $code, 'field' => 'parent_code']);
                    }
                }
                if ($provided['is_postable'] && $values['is_postable'] === false && $account->is_postable) {
                    $error($n, 'account_has_history', "account '{$code}' cannot become non-postable {$suffix}", ['code' => $code, 'field' => 'is_postable']);
                }
                if ($provided['currency_code'] && $currency !== ($account->currency_code ? strtoupper($account->currency_code) : null)) {
                    $error($n, 'account_has_history', "the currency of account '{$code}' cannot be changed {$suffix}", ['code' => $code, 'field' => 'currency_code']);
                }
            }

            if (count($errors) > $before) {
                continue;
            }

            $preview[] = [
                'row' => $n,
                'code' => $code,
                'name_ar' => $d['name_ar'],
                'name_en' => $d['name_en'],
                'type' => $type,
                'parent_code' => $parentCode,
                'is_postable' => $values['is_postable'] ?? ($account ? (bool) $account->is_postable : true),
                'is_active' => $values['is_active'] ?? ($account ? (bool) $account->is_active : true),
                'currency_code' => $provided['currency_code'] ? $currency : $account?->currency_code,
                'classification' => $provided['classification'] ? $classification : ($this->classificationFitsType($account?->classification, $type) ? $account?->classification : null),
                'action' => $account ? 'update' : 'create',
                'existing' => $account ? $account->toApiArray() : null,
                'provided' => $provided,
            ];
        }

        return [$preview, $errors];
    }

    private function classificationFitsType(?string $classification, string $type): bool
    {
        return $classification === null || in_array($classification, Account::classificationsFor($type), true);
    }

    private function parseBool(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'true', '1', 'yes', 'y', 'نعم' => true,
            'false', '0', 'no', 'n', 'لا' => false,
            default => null,
        };
    }

    private function errorResponse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
