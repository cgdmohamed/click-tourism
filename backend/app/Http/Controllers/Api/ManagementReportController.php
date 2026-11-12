<?php

namespace App\Http\Controllers\Api;

use App\Modules\Reports\Services\ManagementReportRows;
use App\Modules\Reports\Services\ManagementReportService;
use App\Modules\Reports\Services\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Management report pack (GAP-RPT-01): profitability, sales, commissions, tax. */
class ManagementReportController extends ApiController
{
    private const TITLES = [
        'profitability' => 'Profitability / الربحية',
        'sales' => 'Sales by Branch and User / المبيعات',
        'commissions' => 'Commissions Summary / ملخص العمولات',
        'tax' => 'Tax Summary / ملخص الضرائب',
    ];

    public function profitability(Request $request, ManagementReportService $reports): JsonResponse
    {
        $d = $this->period($request);

        return $this->ok($reports->profitability($d['from'], $d['to'], $this->branch()));
    }

    public function sales(Request $request, ManagementReportService $reports): JsonResponse
    {
        $d = $this->period($request);

        return $this->ok($reports->sales($d['from'], $d['to'], $this->branch()));
    }

    public function commissions(Request $request, ManagementReportService $reports): JsonResponse
    {
        $d = $this->period($request);

        return $this->ok($reports->commissions($d['from'], $d['to'], $this->branch()));
    }

    public function tax(Request $request, ManagementReportService $reports): JsonResponse
    {
        $d = $this->period($request);

        return $this->ok($reports->tax($d['from'], $d['to'], $this->branch()));
    }

    public function exportProfitability(Request $request, ManagementReportService $reports, ManagementReportRows $rows, ReportExporter $exporter): Response
    {
        return $this->export($request, $exporter, 'profitability', fn ($d) => $reports->profitability($d['from'], $d['to'], $this->branch()), $rows->profitability(...));
    }

    public function exportSales(Request $request, ManagementReportService $reports, ManagementReportRows $rows, ReportExporter $exporter): Response
    {
        return $this->export($request, $exporter, 'sales', fn ($d) => $reports->sales($d['from'], $d['to'], $this->branch()), $rows->sales(...));
    }

    public function exportCommissions(Request $request, ManagementReportService $reports, ManagementReportRows $rows, ReportExporter $exporter): Response
    {
        return $this->export($request, $exporter, 'commissions', fn ($d) => $reports->commissions($d['from'], $d['to'], $this->branch()), $rows->commissions(...));
    }

    public function exportTax(Request $request, ManagementReportService $reports, ManagementReportRows $rows, ReportExporter $exporter): Response
    {
        return $this->export($request, $exporter, 'tax', fn ($d) => $reports->tax($d['from'], $d['to'], $this->branch()), $rows->tax(...));
    }

    private function export(Request $request, ReportExporter $exporter, string $name, callable $build, callable $toRows): Response
    {
        $options = $exporter->options($request);
        $d = $this->period($request);
        $report = $build($d);

        return $exporter->respond($options, $this->branch(), $this->actorId(), $name, $d,
            $toRows($report), "{$name}_{$d['from']}_{$d['to']}", self::TITLES[$name],
            ['period' => "{$d['from']} - {$d['to']}", 'currency' => $report['currency']],
        );
    }

    /** @return array{from: string, to: string} */
    private function period(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
    }
}
