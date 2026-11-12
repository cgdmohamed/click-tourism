<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\FixedAssetReportService;
use App\Modules\Core\Models\Branch;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fixed-asset reports: roll-forward, carrying values, movements, schedule and
 * control-account reconciliation (Prompt 25).
 *
 * Reports are branch-scoped by default. Users who may view all branches can
 * request installation-wide figures with `?global=1`, which is the only scope
 * in which register and GL control accounts are expected to agree after an
 * operational branch transfer.
 */
class FixedAssetReportController extends ApiController
{
    public function rollForward(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return $this->ok($reports->rollForward($this->scope($request), $data['from'], $data['to']));
    }

    public function carryingValues(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date'],
            'include_disposed' => ['nullable', 'boolean'],
        ]);

        return $this->ok($reports->carryingValues(
            $this->scope($request),
            $data['as_of'] ?? null,
            (bool) ($data['include_disposed'] ?? false),
        ));
    }

    public function movements(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return $this->ok($reports->movements($this->scope($request), $data['from'], $data['to']));
    }

    public function reconciliation(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        return $this->ok($reports->reconciliation($this->scope($request), $data['as_of'] ?? null));
    }

    public function schedule(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $asset = null;

        if ($request->query('asset')) {
            $asset = FixedAsset::query()->where('public_id', $request->query('asset'))->firstOrFail();
            $this->authorizeBranchId($asset->branch_id);
        }

        return $this->ok($reports->schedule($asset, $this->scope($request)));
    }

    /**
     * Server-side CSV/XLSX/PDF export of a fixed-asset report. Same branch
     * scope (and `global` rule) as the on-screen report, plus `reports.export`.
     */
    public function export(Request $request, string $report, FixedAssetReportService $reports, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $branch = $this->scope($request);
        $month = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];
        $currency = $this->settings()->baseCurrency();

        switch ($report) {
            case 'roll-forward':
                $data = $request->validate(['from' => $month, 'to' => $month]);
                $result = $reports->rollForward($branch, $data['from'], $data['to']);
                $rows = $csv->fixedAssetRollForwardRows($result);
                $period = $data['from'].' - '.$data['to'];
                $title = 'Fixed Assets Roll-Forward / حركة الأصول الثابتة';
                $base = "fixed-assets-roll-forward_{$data['from']}_{$data['to']}";
                break;
            case 'carrying-values':
                $data = $request->validate(['as_of' => ['nullable', 'date'], 'include_disposed' => ['nullable', 'boolean']]);
                $result = $reports->carryingValues($branch, $data['as_of'] ?? null, (bool) ($data['include_disposed'] ?? false));
                $rows = $csv->fixedAssetCarryingValuesRows($result);
                $period = 'As of '.$result['as_of'];
                $title = 'Fixed Assets Carrying Values / القيم الدفترية للأصول الثابتة';
                $base = "fixed-assets-carrying-values_{$result['as_of']}";
                break;
            case 'movements':
                $data = $request->validate(['from' => $month, 'to' => $month]);
                $result = $reports->movements($branch, $data['from'], $data['to']);
                $rows = $csv->fixedAssetMovementsRows($result);
                $period = $data['from'].' - '.$data['to'];
                $title = 'Fixed Assets Movements / حركات الأصول الثابتة';
                $base = "fixed-assets-movements_{$data['from']}_{$data['to']}";
                break;
            case 'reconciliation':
                $data = $request->validate(['as_of' => ['nullable', 'date']]);
                $result = $reports->reconciliation($branch, $data['as_of'] ?? null);
                $rows = $csv->fixedAssetReconciliationRows($result);
                $period = 'As of '.$result['as_of'];
                $title = 'Fixed Assets Reconciliation / مطابقة الأصول الثابتة';
                $base = "fixed-assets-reconciliation_{$result['as_of']}";
                break;
            default: // schedule
                $data = $request->validate(['asset' => ['nullable', 'string']]);
                $asset = null;
                if (! empty($data['asset'])) {
                    $asset = FixedAsset::query()->where('public_id', $data['asset'])->firstOrFail();
                    $this->authorizeBranchId($asset->branch_id);
                }
                $result = $reports->schedule($asset, $branch);
                $rows = $csv->fixedAssetScheduleRows($result, $currency);
                $period = $asset?->code;
                $title = 'Depreciation Schedule / جدول الإهلاك';
                $base = 'fixed-assets-schedule'.($asset ? '_'.$asset->code : '');
        }

        $filters = array_filter($data, static fn ($value): bool => $value !== null);

        return $exporter->respond($options, $branch, $this->actorId(), 'fixed-assets-'.$report, $filters, $rows, $base, $title, [
            'period' => $period,
            'currency' => $result['currency'] ?? $currency,
        ]);
    }

    private function scope(Request $request): ?Branch
    {
        if ($request->boolean('global') && $request->user()?->can('branches.view_all')) {
            return null;
        }

        return $this->branch();
    }
}
