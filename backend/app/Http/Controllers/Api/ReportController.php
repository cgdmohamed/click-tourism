<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Reports\Services\AgingReconciliationService;
use App\Modules\Reports\Services\PartyStatementService;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends ApiController
{
    /** Hard cap for one synchronous export; narrow the filters when exceeded. */
    private const EXPORT_MAX_ROWS = 50000;

    public function trialBalance(Request $request, ReportService $reports): JsonResponse
    {
        $data = $this->validateTrialBalance($request);

        return $this->ok($reports->trialBalance(
            $data['from'],
            $data['to'],
            $this->branch(),
            $data['currency'],
            $data['pre_closing'],
            $data['max_level'],
            $data['cost_center_id'],
            $data['adjustments'],
        ));
    }

    public function exportTrialBalance(Request $request, ReportService $reports, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $this->validateTrialBalance($request);
        $report = $reports->trialBalance(
            $data['from'],
            $data['to'],
            $this->branch(),
            $data['currency'],
            $data['pre_closing'],
            $data['max_level'],
            $data['cost_center_id'],
            $data['adjustments'],
        );

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'trial-balance', $data,
            $csv->trialBalanceRows($report),
            "trial-balance_{$data['from']}_{$data['to']}",
            'Trial Balance / ميزان المراجعة',
            ['period' => "{$data['from']} - {$data['to']}", 'currency' => $report['currency']],
        );
    }

    public function generalLedger(Request $request, ReportService $reports): JsonResponse
    {
        $data = $this->validateGeneralLedger($request);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 50);

        $result = $reports->generalLedger(
            $data['account'], $data['from'], $data['to'], $this->branch(), $page, $perPage, $data['currency'], $data['party'],
        );

        return response()->json([
            'data' => $result['summary'] + ['lines' => $result['lines']],
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
                'total' => $result['total'],
                'per_page' => $perPage,
            ],
        ]);
    }

    public function exportGeneralLedger(Request $request, ReportService $reports, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $this->validateGeneralLedger($request, paginated: false);

        $result = $reports->generalLedger(
            $data['account'], $data['from'], $data['to'], $this->branch(), 1, self::EXPORT_MAX_ROWS, $data['currency'], $data['party'],
        );

        if ($result['total'] > self::EXPORT_MAX_ROWS) {
            throw ValidationException::withMessages([
                'from' => ['Too many rows to export ('.$result['total'].'). Narrow the date range or filters.'],
            ]);
        }

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'general-ledger', [
            'account' => $data['account']->public_id,
            'from' => $data['from'],
            'to' => $data['to'],
            'currency' => $data['currency'],
        ],
            $csv->generalLedgerRows($result),
            "general-ledger_{$data['account']->code}_{$data['from']}_{$data['to']}",
            'General Ledger / دفتر الأستاذ',
            ['period' => "{$data['from']} - {$data['to']}", 'currency' => $result['summary']['currency']],
        );
    }

    public function incomeStatement(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'compare_from' => ['nullable', 'date', 'required_with:compare_to'],
            'compare_to' => ['nullable', 'date', 'after_or_equal:compare_from', 'required_with:compare_from'],
        ]);

        return $this->ok($reports->incomeStatement(
            $data['from'],
            $data['to'],
            $this->branch(),
            $data['compare_from'] ?? null,
            $data['compare_to'] ?? null,
        ));
    }

    public function exportIncomeStatement(Request $request, ReportService $reports, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $report = $reports->incomeStatement($data['from'], $data['to'], $this->branch());

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'income-statement', $data,
            $csv->incomeStatementRows($report), "income-statement_{$data['from']}_{$data['to']}", 'Income Statement / قائمة الدخل',
            ['period' => "{$data['from']} - {$data['to']}", 'currency' => $report['currency']],
        );
    }

    public function balanceSheet(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['required', 'date'],
            'compare_as_of' => ['nullable', 'date'],
        ]);

        return $this->ok($reports->balanceSheet($data['as_of'], $this->branch(), $data['compare_as_of'] ?? null));
    }

    public function exportBalanceSheet(Request $request, ReportService $reports, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $request->validate([
            'as_of' => ['required', 'date'],
        ]);

        $report = $reports->balanceSheet($data['as_of'], $this->branch());

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'balance-sheet', $data,
            $csv->balanceSheetRows($report), "balance-sheet_{$data['as_of']}", 'Balance Sheet / المركز المالي',
            ['period' => 'As of '.$data['as_of'], 'currency' => $report['currency']],
        );
    }

    public function aging(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:ar,ap'],
            'as_of' => ['required', 'date'],
        ]);

        return $this->ok($reports->aging($data['type'], $data['as_of'], $this->branch()));
    }

    public function exportAging(Request $request, ReportService $reports, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $request->validate([
            'type' => ['required', 'in:ar,ap'],
            'as_of' => ['required', 'date'],
        ]);

        $report = $reports->aging($data['type'], $data['as_of'], $this->branch());

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'aging', $data,
            $csv->agingRows($report), "aging-{$data['type']}_{$data['as_of']}", 'Aging / أعمار الديون',
            ['period' => 'As of '.$data['as_of'], 'currency' => $report['currency']],
        );
    }

    public function agingReconciliation(Request $request, AgingReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:ar,ap'],
            'as_of' => ['required', 'date'],
        ]);

        return $this->ok($reconciliation->reconcile($data['type'], $data['as_of'], $this->branch()));
    }

    public function exportAgingReconciliation(Request $request, AgingReconciliationService $reconciliation, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $request->validate([
            'type' => ['required', 'in:ar,ap'],
            'as_of' => ['required', 'date'],
        ]);

        $report = $reconciliation->reconcile($data['type'], $data['as_of'], $this->branch());

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'aging-reconciliation', $data,
            $csv->agingReconciliationRows($report), "aging-reconciliation-{$data['type']}_{$data['as_of']}", 'Aging Reconciliation / مطابقة الأعمار',
            ['period' => 'As of '.$data['as_of'], 'currency' => $report['currency']],
        );
    }

    public function partyStatement(Request $request, PartyStatementService $statements): JsonResponse
    {
        $data = $this->validatePartyStatement($request);

        $result = $statements->statement(
            $data['party_type'],
            $data['party_id'],
            $data['from'],
            $data['to'],
            $this->branch(),
            $data['page'],
            $data['per_page'],
            $data['currency'],
        );

        $perPage = $data['per_page'];

        return response()->json([
            'data' => $result,
            'meta' => [
                'current_page' => $data['page'],
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
                'total' => $result['total'],
                'per_page' => $perPage,
            ],
        ]);
    }

    public function exportPartyStatement(Request $request, PartyStatementService $statements, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $this->validatePartyStatement($request, paginated: false);

        $report = $statements->statement(
            $data['party_type'],
            $data['party_id'],
            $data['from'],
            $data['to'],
            $this->branch(),
            1,
            self::EXPORT_MAX_ROWS,
            $data['currency'],
        );

        if ($report['total'] > self::EXPORT_MAX_ROWS) {
            throw ValidationException::withMessages([
                'from' => ['Too many rows to export ('.$report['total'].'). Narrow the date range or filters.'],
            ]);
        }

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'party-statement', [
            'party_type' => $data['party_type'],
            'party_id' => $data['party_id'],
            'from' => $data['from'],
            'to' => $data['to'],
        ],
            $csv->partyStatementRows($report),
            "party-statement-{$data['party_type']}-{$data['party_id']}_{$data['from']}_{$data['to']}",
            'Party Statement / كشف حساب طرف',
            ['period' => "{$data['from']} - {$data['to']}", 'currency' => $report['currency']],
        );
    }

    public function dashboard(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'thisMonth', 'lastMonth', 'customRange'])],
            'from' => ['required_if:period,customRange', 'nullable', 'date'],
            'to' => ['required_if:period,customRange', 'nullable', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ]);

        // Dashboard reporting uses the installation base currency. Never
        // relabel base-ledger amounts as another currency without FX policy.
        $base = $this->settings()->baseCurrency();
        if (! empty($data['currency']) && strtoupper($data['currency']) !== $base) {
            throw ValidationException::withMessages([
                'currency' => ['Dashboard aggregation currently supports the base currency only.'],
            ]);
        }

        return $this->ok($reports->dashboard($this->branch(), $data));
    }

    /** @return array{from: string, to: string, currency: ?string, pre_closing: bool, max_level: ?int, cost_center_id: ?int, adjustments: string} */
    private function validateTrialBalance(Request $request): array
    {
        $this->normaliseCurrency($request);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'pre_closing' => ['nullable', 'boolean'],
            'max_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'cost_center_id' => ['nullable', 'string', Rule::exists('cost_centers', 'public_id')],
            'adjustments' => ['nullable', 'in:include,exclude,only'],
        ]);

        $costCenterId = null;
        if (! empty($data['cost_center_id'])) {
            $costCenterId = CostCenter::query()->where('public_id', $data['cost_center_id'])->value('id');
        }

        return [
            'pre_closing' => (bool) ($data['pre_closing'] ?? false),
            'max_level' => isset($data['max_level']) ? (int) $data['max_level'] : null,
            'cost_center_id' => $costCenterId !== null ? (int) $costCenterId : null,
            'adjustments' => $data['adjustments'] ?? 'include',
        ] + $data + ['currency' => null];
    }

    /**
     * @return array{account: Account, from: string, to: string, currency: ?string, party: ?array{type: string, id: int}, page?: int, per_page?: int}
     */
    private function validateGeneralLedger(Request $request, bool $paginated = true): array
    {
        $this->normaliseCurrency($request);

        $rules = [
            'account_id' => ['required', 'string', Rule::exists('accounts', 'public_id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'party_type' => ['nullable', 'in:customer,supplier,employee', 'required_with:party_id'],
            'party_id' => ['nullable', 'string', 'required_with:party_type'],
        ];

        if ($paginated) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:200'];
        }

        $data = $request->validate($rules);

        $party = null;
        if (! empty($data['party_type'])) {
            $model = match ($data['party_type']) {
                'customer' => Customer::class,
                'supplier' => Supplier::class,
                default => Employee::class,
            };
            $found = $model::query()->where('public_id', $data['party_id'])->first();

            if (! $found) {
                throw ValidationException::withMessages(['party_id' => ['The selected party does not exist.']]);
            }

            $party = ['type' => $data['party_type'], 'id' => $found->id];
        }

        return [
            'account' => Account::query()->where('public_id', $data['account_id'])->firstOrFail(),
            'from' => $data['from'],
            'to' => $data['to'],
            'currency' => $data['currency'] ?? null,
            'party' => $party,
            'page' => $data['page'] ?? 1,
            'per_page' => $data['per_page'] ?? 50,
        ];
    }

    /** @return array{party_type: string, party_id: int, from: string, to: string, currency: ?string, page: int, per_page: int} */
    private function validatePartyStatement(Request $request, bool $paginated = true): array
    {
        $this->normaliseCurrency($request);

        $rules = [
            'party_type' => ['required', 'in:customer,supplier,employee'],
            'party_id' => ['required', 'string'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ];

        if ($paginated) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:500'];
        }

        $data = $request->validate($rules);

        $model = match ($data['party_type']) {
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            default => Employee::class,
        };

        $party = $model::query()->where('public_id', $data['party_id'])->first();
        if (! $party) {
            throw ValidationException::withMessages(['party_id' => ['The selected party does not exist.']]);
        }

        return [
            'party_type' => $data['party_type'],
            'party_id' => (int) $party->id,
            'from' => $data['from'],
            'to' => $data['to'],
            'currency' => $data['currency'] ?? null,
            'page' => $data['page'] ?? 1,
            'per_page' => $data['per_page'] ?? 200,
        ];
    }

    private function normaliseCurrency(Request $request): void
    {
        if (is_string($request->query('currency')) && $request->query('currency') !== '') {
            $request->merge(['currency' => strtoupper($request->query('currency'))]);
        } else {
            $request->request->remove('currency');
            $request->query->remove('currency');
        }
    }
}
