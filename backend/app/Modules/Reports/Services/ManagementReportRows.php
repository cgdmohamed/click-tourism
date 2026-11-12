<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportRow;

/** Typed export rows (CSV/XLSX/PDF) for the management report pack. */
class ManagementReportRows
{
    public function __construct(private readonly ReportCsv $csv) {}

    /** @param array<string, mixed> $r */
    public function profitability(array $r): array
    {
        $exp = $this->csv->exponent($r['currency']);
        $m = fn (int $v) => $this->csv->money($v, $exp);
        $rows = [$this->csv->header(['Booking', 'Customer', 'Status', 'Revenue', 'Direct cost', 'Margin', 'Currency'])];
        foreach ($r['by_booking'] as $row) {
            $rows[] = [
                $row['booking']['number'] ?? 'Not linked to a booking',
                $row['booking']['customer_name_en'] ?? '',
                $row['booking']['status'] ?? '',
                $m($row['revenue_minor']), $m($row['direct_cost_minor']), $m($row['margin_minor']), $r['currency'],
            ];
        }
        $rows[] = $this->csv->total(['Total', '', '', $m($r['total_revenue_minor']), $m($r['total_direct_cost_minor']), $m($r['total_margin_minor']), $r['currency']]);
        $rows[] = new ReportRow([]);
        $rows[] = $this->csv->header(['Service type', '', '', 'Revenue', 'Direct cost', 'Margin', 'Currency']);
        foreach ($r['by_service_type'] as $row) {
            $rows[] = [$row['service_type'] ?? 'Unallocated', '', '', $m($row['revenue_minor']), $m($row['direct_cost_minor']), $m($row['margin_minor']), $r['currency']];
        }

        return $rows;
    }

    /** @param array<string, mixed> $r */
    public function sales(array $r): array
    {
        $exp = $this->csv->exponent($r['currency']);
        $m = fn (int $v) => $this->csv->money($v, $exp);
        $rows = [$this->csv->header(['Branch', 'Revenue', 'Currency'])];
        foreach ($r['by_branch'] as $row) {
            $rows[] = [$row['branch']['name_en'], $m($row['revenue_minor']), $r['currency']];
        }
        $rows[] = $this->csv->total(['Total', $m($r['total_revenue_minor']), $r['currency']]);
        $rows[] = new ReportRow([]);
        $rows[] = $this->csv->header(['User', 'Bookings', 'Revenue', 'Currency']);
        foreach ($r['by_user'] as $row) {
            $rows[] = [$row['user']['name'] ?? 'Not linked to a booking', $row['bookings_count'], $m($row['revenue_minor']), $r['currency']];
        }

        return $rows;
    }

    /** @param array<string, mixed> $r */
    public function commissions(array $r): array
    {
        $exp = $this->csv->exponent($r['currency']);
        $m = fn (int $v) => $this->csv->money($v, $exp);
        $rows = [$this->csv->header(['Beneficiary', 'Type', 'Pending approval/posting', 'Expense (ledger)', 'Paid', 'Currency'])];
        foreach ($r['rows'] as $row) {
            $rows[] = [$row['beneficiary']['name_en'] ?? '', $row['beneficiary_type'], $m($row['pending_minor']), $m($row['expense_minor']), $m($row['paid_minor']), $r['currency']];
        }
        $rows[] = $this->csv->total(['Total', '', $m($r['total_pending_minor']), $m($r['total_expense_minor']), $m($r['total_paid_minor']), $r['currency']]);

        return $rows;
    }

    /** @param array<string, mixed> $r */
    public function tax(array $r): array
    {
        $exp = $this->csv->exponent($r['currency']);
        $m = fn (int $v) => $this->csv->money($v, $exp);
        $rows = [$this->csv->header(['Tax profile', 'Rate %', 'Output tax', 'Input tax', 'Net payable', 'Currency'])];
        foreach ($r['rows'] as $row) {
            $rows[] = [$row['tax_profile']['name'] ?? 'Default tax accounts', $row['tax_profile']['rate'] ?? '', $m($row['output_minor']), $m($row['input_minor']), $m($row['net_payable_minor']), $r['currency']];
        }
        $rows[] = $this->csv->total(['Total', '', $m($r['total_output_minor']), $m($r['total_input_minor']), $m($r['total_net_payable_minor']), $r['currency']]);

        return $rows;
    }
}
