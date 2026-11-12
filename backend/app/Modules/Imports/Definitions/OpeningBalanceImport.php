<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Accounting\Services\OpeningBalanceService;
use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportRun;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Opening-balance lines through the job UI. The result is always a DRAFT
 * batch (as_of_date + memo come from the job options); posting stays a
 * separate, separately permissioned action. A dry run builds the batch inside
 * a transaction and rolls it back, so the preview applies the exact same
 * validation (including per-currency balance) as the real import.
 */
class OpeningBalanceImport implements ImportDefinition
{
    use CastsRows;

    public function __construct(private readonly OpeningBalanceService $service) {}

    public function entity(): string
    {
        return 'opening-balance-lines';
    }

    public function version(): int
    {
        return 1;
    }

    public function labels(): array
    {
        return ['en' => 'Opening balance lines', 'ar' => 'بنود الأرصدة الافتتاحية'];
    }

    public function columns(): array
    {
        return [
            new ColumnDef('account_code', 'Account code', 'كود الحساب', 'string', true, ['max' => 50], '1010', 'Active, postable account code.', 'كود حساب نشط وقابل للترحيل.', ['account', 'account code']),
            new ColumnDef('debit', 'Debit', 'مدين', 'money', false, ['currency_key' => 'currency'], '1500.00', 'Exactly one of debit / credit per line.', 'مدين أو دائن فقط لكل سطر.'),
            new ColumnDef('credit', 'Credit', 'دائن', 'money', false, ['currency_key' => 'currency'], '', ''),
            new ColumnDef('currency', 'Currency', 'العملة', 'currency', false, [], 'EGP', 'Defaults to the base currency.', 'الافتراضي هو العملة الأساسية.'),
            new ColumnDef('fx_rate', 'FX rate', 'سعر الصرف', 'decimal', false, [], '', 'Only for non-base currencies; the stored rate is used when empty.', 'للعملات غير الأساسية فقط، ويُستخدم السعر المسجل عند تركه فارغاً.'),
            new ColumnDef('party_type', 'Party type', 'نوع الطرف', 'enum', false, ['values' => ['customer', 'supplier'], 'map' => ['عميل' => 'customer', 'مورد' => 'supplier']], '', 'Required on receivable/payable control accounts.', 'مطلوب على حسابات العملاء والموردين الرئيسية.'),
            new ColumnDef('party_id', 'Party ID', 'معرف الطرف', 'string', false, ['max' => 50], '', 'The customer / supplier ID.', 'معرف العميل / المورد.'),
            new ColumnDef('description', 'Description', 'البيان', 'string', false, ['max' => 255], ''),
        ];
    }

    public function options(): array
    {
        return [
            'as_of_date' => ['rules' => ['required', 'date_format:Y-m-d'], 'en' => 'As-of date', 'ar' => 'تاريخ الرصيد', 'required' => true],
            'memo' => ['rules' => ['nullable', 'string', 'max:255'], 'en' => 'Memo', 'ar' => 'ملاحظة', 'required' => false],
        ];
    }

    public function permissions(string $policy): array
    {
        return ['opening_balances.manage'];
    }

    public function supportsDuplicatePolicy(): bool
    {
        return false;
    }

    public function defaultPolicy(): string
    {
        return 'skip';
    }

    public function isAtomic(): bool
    {
        return true;
    }

    public function process(ImportRun $run, iterable $rows): void
    {
        $ctx = $run->ctx;
        $lines = [];
        $rowNumbers = [];
        $summaries = [];

        foreach ($rows as ['row' => $n, 'data' => $data]) {
            [$values, $castErrors] = $this->castRow($data, $ctx);

            foreach ($castErrors as $e) {
                $run->error($n, $e['code'], $this->messageFor($e['field'], $e['code'], $e['params']), $e['field'], $e['params'], $data);
            }
            if ($castErrors !== []) {
                continue;
            }

            $debit = (int) ($values['debit'] ?? 0);
            $credit = (int) ($values['credit'] ?? 0);
            if (($debit > 0) === ($credit > 0)) {
                $run->error($n, 'debit_or_credit', 'Enter exactly one of debit or credit, greater than zero.', 'debit', [], $data);

                continue;
            }

            $lines[] = [
                'account_id' => $values['account_code'],
                'currency' => $values['currency'] ?? $ctx->baseCurrency,
                'fx_rate' => $values['fx_rate'],
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'party_type' => $values['party_type'],
                'party_id' => $values['party_id'],
                'description' => $values['description'],
            ];
            $rowNumbers[] = $n;
            $summaries[] = [
                'account_code' => $values['account_code'],
                'currency' => $values['currency'] ?? $ctx->baseCurrency,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
            ];
        }

        if ($run->errors !== []) {
            return;
        }

        if (count($lines) < 2) {
            $run->error(0, 'too_few_lines', 'A batch needs at least two lines.');

            return;
        }

        $data = ['as_of_date' => $ctx->options['as_of_date'] ?? null, 'memo' => $ctx->options['memo'] ?? null, 'lines' => $lines];

        // Always built inside a transaction: committed only for a clean real run.
        DB::beginTransaction();
        $commit = false;
        try {
            $batch = $this->service->create($data, $ctx->branch, $ctx->userId);
            $commit = $ctx->apply;
            if ($ctx->apply) {
                $run->result = ['opening_balance_batch_id' => $batch->public_id];
            }
            foreach ($summaries as $i => $summary) {
                $run->created($rowNumbers[$i], $summary);
            }
        } catch (DomainException $e) {
            $row = 0;
            if (preg_match('/^Line (\d+):/', $e->getMessage(), $m)) {
                $row = $rowNumbers[(int) $m[1] - 1] ?? 0;
            }
            $run->error($row, 'apply_failed', (string) preg_replace('/^Line \d+: /', '', $e->getMessage()));
        } finally {
            $commit ? DB::commit() : DB::rollBack();
        }
    }
}
