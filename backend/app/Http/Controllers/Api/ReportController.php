<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends ApiController
{
    /** Hard cap for one CSV export; narrow the filters when exceeded. */
    private const EXPORT_MAX_ROWS = 50000;

    public function trialBalance(Request $request, ReportService $reports): JsonResponse
    {
        $data = $this->validateTrialBalance($request);

        return $this->ok($reports->trialBalance($data['from'], $data['to'], $this->branch(), $data['currency'], $data['pre_closing']));
    }

    public function exportTrialBalance(Request $request, ReportService $reports, ReportCsv $csv, AuditService $audit): Response
    {
        $data = $this->validateTrialBalance($request);
        $report = $reports->trialBalance($data['from'], $data['to'], $this->branch(), $data['currency'], $data['pre_closing']);

        $audit->log('report.exported', null, null, ['report' => 'trial-balance'] + $data, $this->actorId());

        return $this->csvResponse(
            $csv->trialBalance($report),
            "trial-balance_{$data['from']}_{$data['to']}.csv",
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

    public function exportGeneralLedger(Request $request, ReportService $reports, ReportCsv $csv, AuditService $audit): Response
    {
        $data = $this->validateGeneralLedger($request, paginated: false);

        $result = $reports->generalLedger(
            $data['account'], $data['from'], $data['to'], $this->branch(), 1, self::EXPORT_MAX_ROWS, $data['currency'], $data['party'],
        );

        if ($result['total'] > self::EXPORT_MAX_ROWS) {
            throw ValidationException::withMessages([
                'from' => ['Too many rows to export ('.$result['total'].'). Narrow the date range or filters.'],
            ]);
        }

        $audit->log('report.exported', null, null, [
            'report' => 'general-ledger',
            'account' => $data['account']->public_id,
            'from' => $data['from'],
            'to' => $data['to'],
            'currency' => $data['currency'],
            'rows' => $result['total'],
        ], $this->actorId());

        return $this->csvResponse(
            $csv->generalLedger($result),
            "general-ledger_{$data['account']->code}_{$data['from']}_{$data['to']}.csv",
        );
    }

    public function incomeStatement(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return $this->ok($reports->incomeStatement($data['from'], $data['to'], $this->branch()));
    }

    public function balanceSheet(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['required', 'date'],
        ]);

        return $this->ok($reports->balanceSheet($data['as_of'], $this->branch()));
    }

    public function aging(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:ar,ap'],
            'as_of' => ['required', 'date'],
        ]);

        return $this->ok($reports->aging($data['type'], $data['as_of'], $this->branch()));
    }

    public function dashboard(ReportService $reports): JsonResponse
    {
        return $this->ok($reports->dashboard($this->branch()));
    }

    /** @return array{from: string, to: string, currency: ?string} */
    private function validateTrialBalance(Request $request): array
    {
        $this->normaliseCurrency($request);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'pre_closing' => ['nullable', 'boolean'],
        ]);

        return ['pre_closing' => (bool) ($data['pre_closing'] ?? false)] + $data + ['currency' => null];
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
            'party_type' => ['nullable', 'in:customer,supplier', 'required_with:party_id'],
            'party_id' => ['nullable', 'string', 'required_with:party_type'],
        ];

        if ($paginated) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:200'];
        }

        $data = $request->validate($rules);

        $party = null;
        if (! empty($data['party_type'])) {
            $model = $data['party_type'] === 'customer' ? Customer::class : Supplier::class;
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

    private function normaliseCurrency(Request $request): void
    {
        if (is_string($request->query('currency')) && $request->query('currency') !== '') {
            $request->merge(['currency' => strtoupper($request->query('currency'))]);
        } else {
            $request->request->remove('currency');
            $request->query->remove('currency');
        }
    }

    private function csvResponse(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $filename).'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
