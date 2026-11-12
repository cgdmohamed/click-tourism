<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Chart-of-accounts import rules, shared by the legacy
 * `POST /accounts/import` endpoint and the generic import-job framework.
 */
class AccountImportService
{
    /**
     * @param  list<array{row: int, data: array<string, string>}>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public function validate(array $rows): array
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

    /** Strip internal bookkeeping before returning preview rows. */
    public function publicPreview(array $preview): array
    {
        return array_map(function (array $item) {
            unset($item['provided']);

            return $item;
        }, $preview);
    }

    /**
     * @param  list<array<string, mixed>>  $preview  Output of validate().
     */
    public function apply(array $preview): void
    {
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
}
