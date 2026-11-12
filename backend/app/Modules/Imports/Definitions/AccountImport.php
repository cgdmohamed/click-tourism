<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountImportService;
use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportRun;

/**
 * Chart of accounts through the job UI. Rules are the ones of the legacy
 * `POST /accounts/import` endpoint (shared service); the file is atomic so a
 * parent/child hierarchy is never half imported.
 */
class AccountImport implements ImportDefinition
{
    public function __construct(private readonly AccountImportService $accounts) {}

    public function entity(): string
    {
        return 'accounts';
    }

    public function version(): int
    {
        return 1;
    }

    public function labels(): array
    {
        return ['en' => 'Chart of accounts', 'ar' => 'دليل الحسابات'];
    }

    public function columns(): array
    {
        return [
            new ColumnDef('code', 'Code', 'الكود', 'string', true, [], '1010', 'Required, max 20 characters.', 'مطلوب بحد أقصى 20 حرفاً.'),
            new ColumnDef('name_ar', 'Name (Arabic)', 'الاسم (عربي)', 'string', true, [], 'الصندوق', 'Required.', 'مطلوب.', ['name (ar)']),
            new ColumnDef('name_en', 'Name (English)', 'الاسم (إنجليزي)', 'string', true, [], 'Cash on hand', 'Required.', 'مطلوب.', ['name (en)']),
            new ColumnDef('type', 'Type', 'النوع', 'string', true, [], 'asset', 'asset, liability, equity, revenue or expense.', 'أصول، التزامات، حقوق ملكية، إيرادات أو مصروفات (بالإنجليزية).'),
            new ColumnDef('parent_code', 'Parent code', 'كود الحساب الأب', 'string', false, [], '1000', 'Leave the column out to keep the current parent; leave a cell empty to clear it.', 'احذف العمود للإبقاء على الأب الحالي، واترك الخلية فارغة لإزالته.', ['parent code']),
            new ColumnDef('is_postable', 'Postable', 'قابل للترحيل', 'string', false, [], 'yes', 'yes/no.', 'نعم/لا.', ['postable']),
            new ColumnDef('is_active', 'Active', 'نشط', 'string', false, [], 'yes', 'yes/no.', 'نعم/لا.', ['active']),
            new ColumnDef('currency_code', 'Currency', 'العملة', 'string', false, [], 'EGP', 'Restrict the account to one currency.', 'تقييد الحساب بعملة واحدة.', ['currency']),
            new ColumnDef('classification', 'Classification', 'التصنيف', 'string', false, [], 'cash', 'Must be valid for the account type.', 'يجب أن يناسب نوع الحساب.'),
        ];
    }

    public function options(): array
    {
        return [];
    }

    public function permissions(string $policy): array
    {
        return ['accounts.update'];
    }

    public function supportsDuplicatePolicy(): bool
    {
        return true;
    }

    public function defaultPolicy(): string
    {
        // Matches the legacy endpoint, which always upserts by code.
        return 'update';
    }

    public function isAtomic(): bool
    {
        return true;
    }

    public function process(ImportRun $run, iterable $rows): void
    {
        $list = [];
        $raw = [];
        foreach ($rows as $row) {
            $list[] = $row;
            $raw[$row['row']] = $row['data'];
        }

        $policy = $run->ctx->policy;
        if ($policy !== 'update') {
            $existing = array_flip(Account::query()->pluck('code')->all());
            $remaining = [];
            foreach ($list as $row) {
                $code = $row['data']['code'] ?? '';
                if ($code !== '' && isset($existing[$code])) {
                    if ($policy === 'skip') {
                        $run->skipped($row['row'], ['code' => $code, 'name_en' => $row['data']['name_en'] ?? '']);
                    } else {
                        $run->error($row['row'], 'duplicate_exists', "Account '{$code}' already exists.", 'code', ['code' => $code], $row['data']);
                    }

                    continue;
                }
                $remaining[] = $row;
            }
            $list = $remaining;
        }

        [$preview, $errors] = $this->accounts->validate($list);

        foreach ($errors as $error) {
            $run->error(
                $error['row'],
                $error['code'],
                (string) preg_replace('/^Row \d+: /', '', $error['message']),
                $error['params']['field'] ?? null,
                $error['params'] ?? [],
                $raw[$error['row']] ?? [],
            );
        }

        if ($errors !== []) {
            return;
        }

        if ($run->ctx->apply) {
            $this->accounts->apply($preview);
        }

        foreach ($this->accounts->publicPreview($preview) as $item) {
            $summary = ['code' => $item['code'], 'name_en' => $item['name_en'], 'name_ar' => $item['name_ar'], 'type' => $item['type']];
            $item['action'] === 'update' ? $run->updated($item['row'], $summary) : $run->created($item['row'], $summary);
        }
    }
}
