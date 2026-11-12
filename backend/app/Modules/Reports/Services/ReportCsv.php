<?php

namespace App\Modules\Reports\Services;

use App\Modules\Money\Models\Currency;
use App\Modules\Reports\Support\DateCell;
use App\Modules\Reports\Support\MoneyCell;
use App\Modules\Reports\Support\ReportRow;

/**
 * Builds report rows and spreadsheet-safe CSV for report exports.
 *
 * Each report has a `xxxRows()` method returning typed rows (strings, money
 * and date cells, header/total markers) that feed CSV, XLSX and PDF alike, and
 * a `xxx()` convenience that renders those rows as CSV.
 *
 * Amounts are exact decimal strings derived from integer minor units (no
 * floats). Text cells that a spreadsheet could interpret as a formula are
 * prefixed with an apostrophe (OWASP "CSV injection").
 */
class ReportCsv
{
    /** Leading characters a spreadsheet may treat as the start of a formula or DDE command. */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /** @param  array<int, ReportRow|array<int, mixed>>  $rows */
    public function build(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            $cells = $row instanceof ReportRow ? $row->cells : $row;
            fputcsv($handle, array_map(fn ($cell) => $this->cell($cell), $cells));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // UTF-8 BOM so Excel opens Arabic text correctly.
        return "\xEF\xBB\xBF".$csv;
    }

    public function cell(string|int|MoneyCell|DateCell|null $value): string
    {
        if ($value === null) {
            return '';
        }

        // Generated numbers and dates are never user text.
        if ($value instanceof MoneyCell || $value instanceof DateCell) {
            return (string) $value;
        }

        return $this->text((string) $value);
    }

    /** Neutralises spreadsheet formula injection in a text value. */
    public function text(string $text): string
    {
        if ($text !== '' && ! is_numeric($text) && in_array($text[0], self::FORMULA_LEADERS, true)) {
            return "'".$text;
        }

        return $text;
    }

    public function amount(int $minor, int $exponent): string
    {
        $negative = $minor < 0;
        $digits = (string) abs($minor);

        if ($exponent === 0) {
            return ($negative ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    public function money(int $minor, int $exponent): MoneyCell
    {
        return new MoneyCell($this->amount($minor, $exponent), $exponent);
    }

    public function date(?string $date): string|DateCell
    {
        if ($date === null || $date === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 ? new DateCell(substr($date, 0, 10)) : $date;
    }

    /** @param  array<int, mixed>  $cells */
    public function header(array $cells): ReportRow
    {
        return new ReportRow($cells, ReportRow::HEADER);
    }

    /** @param  array<int, mixed>  $cells */
    public function total(array $cells): ReportRow
    {
        return new ReportRow($cells, ReportRow::TOTAL);
    }

    public function exponent(string $currency): int
    {
        return (int) (Currency::query()->where('code', $currency)->value('exponent') ?? 2);
    }

    /** @param  array<string, mixed>  $report  Result of ReportService::trialBalance() */
    public function trialBalanceRows(array $report): array
    {
        $exp = $this->exponent($report['currency']);
        $rows = [$this->header([
            'Code', 'Name (EN)', 'Name (AR)',
            'Opening debit', 'Opening credit', 'Debit', 'Credit',
            'Closing debit', 'Closing credit', 'Abnormal', 'Currency',
        ])];

        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row->code, $row->name_en, $row->name_ar,
                $this->money($row->opening_debit_minor, $exp),
                $this->money($row->opening_credit_minor, $exp),
                $this->money($row->debit_minor, $exp),
                $this->money($row->credit_minor, $exp),
                $this->money($row->closing_debit_minor, $exp),
                $this->money($row->closing_credit_minor, $exp),
                ($row->abnormal ?? false) ? 'Yes' : '',
                $report['currency'],
            ];
        }

        $rows[] = $this->total([
            '', 'Total', '',
            $this->money($report['total_opening_debit_minor'], $exp),
            $this->money($report['total_opening_credit_minor'], $exp),
            $this->money($report['total_debit_minor'], $exp),
            $this->money($report['total_credit_minor'], $exp),
            $this->money($report['total_closing_debit_minor'], $exp),
            $this->money($report['total_closing_credit_minor'], $exp),
            '',
            $report['currency'],
        ]);

        return $rows;
    }

    /**
     * @param  array{summary: array<string, mixed>, lines: array<int, array<string, mixed>>}  $ledger
     */
    public function generalLedgerRows(array $ledger): array
    {
        $summary = $ledger['summary'];
        $currency = $summary['currency'];
        $exp = $this->exponent($currency);

        $rows = [
            ['Account', $summary['account']['code'], $summary['account']['name_en'], $summary['account']['name_ar']],
            ['Period', $summary['from'], $summary['to'], "Basis: {$summary['basis']}"],
            ['Opening balance', '', '', '', '', $this->money($summary['opening_minor'], $exp), $currency],
            $this->header(['Date', 'Entry no.', 'Account', 'Description', 'Debit', 'Credit', 'Balance', 'Currency', 'Source']),
        ];

        foreach ($ledger['lines'] as $line) {
            $debit = $summary['basis'] === 'original' ? $line['debit_minor'] : $line['base_debit_minor'];
            $credit = $summary['basis'] === 'original' ? $line['credit_minor'] : $line['base_credit_minor'];

            $rows[] = [
                $this->date($line['entry_date']),
                $line['number'],
                $line['account_code'],
                $line['description'] ?: $line['memo'],
                $this->money($debit, $exp),
                $this->money($credit, $exp),
                $this->money($line['balance_minor'], $exp),
                $currency,
                $line['source_type'],
            ];
        }

        $rows[] = $this->total([
            'Totals', '', '', '',
            $this->money($summary['total_debit_minor'], $exp),
            $this->money($summary['total_credit_minor'], $exp),
            $this->money($summary['closing_minor'], $exp),
            $currency,
        ]);

        return $rows;
    }

    /** @param array<string, mixed> $statement Result of PartyStatementService::statement() */
    public function partyStatementRows(array $statement): array
    {
        $currency = $statement['currency'];
        $exp = $this->exponent($currency);
        $reconciliation = $statement['reconciliation'];

        $rows = [
            ['Party', $statement['party']['name_en'], $statement['party']['name_ar'], $statement['party_type']],
            ['Period', $statement['from'], $statement['to'], "Basis: {$statement['basis']}"],
            ['Control account', $statement['control_account']['code'], $statement['control_account']['name_en'], $statement['control_account']['name_ar']],
            ['Opening balance', '', '', '', '', $this->money($statement['opening_minor'], $exp), $currency],
            $this->header(['Date', 'Entry no.', 'Account', 'Description', 'Source', 'Debit', 'Credit', 'Balance', 'Currency']),
        ];

        foreach ($statement['lines'] as $line) {
            $debit = $statement['basis'] === 'original' ? $line['debit_minor'] : $line['base_debit_minor'];
            $credit = $statement['basis'] === 'original' ? $line['credit_minor'] : $line['base_credit_minor'];

            $rows[] = [
                $this->date($line['entry_date']),
                $line['number'],
                $line['account_code'],
                $line['description'] ?: $line['memo'],
                $line['source_reference'] ?: $line['source_type'],
                $this->money($debit, $exp),
                $this->money($credit, $exp),
                $this->money($line['balance_minor'], $exp),
                $currency,
            ];
        }

        $rows[] = $this->total([
            'Totals', '', '', '', '',
            $this->money($statement['total_debit_minor'], $exp),
            $this->money($statement['total_credit_minor'], $exp),
            $this->money($statement['closing_minor'], $exp),
            $currency,
        ]);

        $rows[] = [];
        $rows[] = $this->header(['Reconciliation', 'Ledger', 'Operational', 'Difference', 'Currency']);
        $rows[] = [
            'Open invoices / advances',
            $this->money($reconciliation['normalized_ledger_minor'], $exp),
            $this->money($reconciliation['operational_open_invoices_minor'], $exp),
            $this->money($reconciliation['difference_minor'], $exp),
            $currency,
        ];
        $rows[] = ['Unapplied credits', $this->money($reconciliation['unapplied_credits_minor'], $exp), '', '', $currency];

        foreach ($reconciliation['differences'] as $difference) {
            $rows[] = ['Difference: '.$difference['code'], $this->money($difference['amount_minor'], $exp), '', '', $currency];
        }

        return $rows;
    }

    /** @param array<string, mixed> $report Result of AgingReconciliationService::reconcile() */
    public function agingReconciliationRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);

        $rows = [
            ['Type', $report['type'], 'As of', $report['as_of']],
            ['Control account', $report['ledger']['control_account']['code'], $report['ledger']['control_account']['name_en']],
            ['Ledger balance', $this->money($report['ledger']['balance_minor'], $exp), $currency, ''],
            ['Open invoices', $this->money($report['operational']['open_invoices_minor'], $exp), $currency, ''],
            ['Unapplied advances', $this->money($report['operational']['unapplied_advances_minor'], $exp), $currency, ''],
            ['Difference', $this->money($report['difference_minor'], $exp), $currency, $report['reconciled'] ? 'Yes' : 'No'],
            [],
            $this->header(['Bucket', 'Amount', 'Currency', '']),
        ];

        foreach ($report['operational']['buckets'] as $bucket) {
            $rows[] = [$bucket['key'], $this->money($bucket['amount_minor'], $exp), $currency, ''];
        }

        $rows[] = $this->total(['Total open invoices', $this->money($report['operational']['open_invoices_minor'], $exp), $currency, '']);
        $rows[] = [];
        $rows[] = $this->header(['Party', 'Ledger', 'Open invoices', 'Difference', 'Currency']);

        foreach ($report['parties'] as $party) {
            $rows[] = [
                $party['name_en'] ?? $party['name_ar'] ?? $party['party_id'],
                $this->money($party['ledger_minor'], $exp),
                $this->money($party['open_invoices_minor'], $exp),
                $this->money($party['difference_minor'], $exp),
                $currency,
            ];
        }

        foreach ($report['differences'] as $difference) {
            $rows[] = ['Difference: '.$difference['code'], $this->money($difference['amount_minor'], $exp), '', '', $currency];
        }

        return $rows;
    }

    /** @param array<string, mixed> $report Result of ReportService::incomeStatement() */
    public function incomeStatementRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Income statement', $report['from'], $report['to']],
            $this->header(['Section', 'Code', 'Name (EN)', 'Name (AR)', 'Amount', 'Currency']),
        ];

        foreach ($report['revenue'] as $line) {
            $rows[] = ['Revenue', $line['code'], $line['name_en'], $line['name_ar'], $this->money($line['amount_minor'], $exp), $currency];
        }
        $rows[] = $this->total(['Total revenue', '', '', '', $this->money($report['total_revenue_minor'], $exp), $currency]);

        // Older payloads without the split fall back to a single expense list.
        $cost = $report['cost_of_services'] ?? [];
        $opex = $report['operating_expenses'] ?? $report['expenses'];

        foreach ($cost as $line) {
            $rows[] = ['Cost of services', $line['code'], $line['name_en'], $line['name_ar'], $this->money($line['amount_minor'], $exp), $currency];
        }
        if (isset($report['total_cost_of_services_minor'])) {
            $rows[] = $this->total(['Total cost of services', '', '', '', $this->money($report['total_cost_of_services_minor'], $exp), $currency]);
            $rows[] = $this->total(['Gross profit', '', '', '', $this->money($report['gross_profit_minor'], $exp), $currency]);
        }

        foreach ($opex as $line) {
            $rows[] = ['Operating expense', $line['code'], $line['name_en'], $line['name_ar'], $this->money($line['amount_minor'], $exp), $currency];
        }
        if (isset($report['total_operating_expenses_minor'])) {
            $rows[] = $this->total(['Total operating expenses', '', '', '', $this->money($report['total_operating_expenses_minor'], $exp), $currency]);
            $rows[] = $this->total(['Operating profit', '', '', '', $this->money($report['operating_profit_minor'], $exp), $currency]);
        }
        $rows[] = $this->total(['Total expenses', '', '', '', $this->money($report['total_expense_minor'], $exp), $currency]);
        $rows[] = $this->total(['Net profit', '', '', '', $this->money($report['net_profit_minor'], $exp), $currency]);

        return $rows;
    }

    /** @param array<string, mixed> $report Result of ReportService::balanceSheet() */
    public function balanceSheetRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Balance sheet', $report['as_of']],
            $this->header(['Section', 'Code', 'Name (EN)', 'Name (AR)', 'Amount', 'Currency']),
        ];

        $sections = isset($report['current_assets'])
            ? [
                'current_assets' => 'Current asset', 'non_current_assets' => 'Non-current asset',
                'current_liabilities' => 'Current liability', 'non_current_liabilities' => 'Non-current liability',
                'equity' => 'Equity',
            ]
            : ['assets' => 'Asset', 'liabilities' => 'Liability', 'equity' => 'Equity'];

        foreach ($sections as $key => $label) {
            foreach ($report[$key] as $line) {
                $rows[] = [$label, $line['code'], $line['name_en'], $line['name_ar'], $this->money($line['amount_minor'], $exp), $currency];
            }
        }

        if (isset($report['current_assets_minor'])) {
            foreach ([
                'current_assets_minor' => 'Total current assets',
                'non_current_assets_minor' => 'Total non-current assets',
                'current_liabilities_minor' => 'Total current liabilities',
                'non_current_liabilities_minor' => 'Total non-current liabilities',
            ] as $key => $label) {
                $rows[] = [$label, '', '', '', $this->money($report[$key], $exp), $currency];
            }
            $rows[] = ['Classification', $report['classification'] ?? ''];
            if (! empty($report['unclassified_accounts'])) {
                $rows[] = ['Unclassified accounts', implode(' ', $report['unclassified_accounts'])];
            }
        }

        $rows[] = ['Current earnings', '', '', '', $this->money($report['current_earnings_minor'], $exp), $currency];
        $rows[] = $this->total(['Total assets', '', '', '', $this->money($report['total_assets_minor'], $exp), $currency]);
        $rows[] = $this->total(['Total liabilities and equity', '', '', '', $this->money($report['total_liabilities_and_equity_minor'], $exp), $currency]);
        $rows[] = ['Balanced', $report['balanced'] ? 'Yes' : 'No'];

        return $rows;
    }

    /** @param array<string, mixed> $report Result of ReportService::aging() */
    public function agingRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Aging', $report['type'], $report['as_of']],
            $this->header(['Bucket', 'Amount', 'Currency']),
        ];

        foreach ($report['buckets'] as $bucket) {
            $rows[] = [$bucket['label'], $this->money($bucket['amount_minor'], $exp), $currency];
        }

        $rows[] = $this->total(['Total', $this->money($report['total_minor'], $exp), $currency]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report  Result of CostCenterService::profitAndLoss()
     * @param  string  $currency  Base currency code (the report has no currency of its own)
     */
    public function costCenterProfitAndLossRows(array $report, string $currency): array
    {
        $exp = $this->exponent($currency);
        $rows = [
            ['Cost center profit and loss', $report['from'], $report['to']],
            $this->header(['Code', 'Name (EN)', 'Name (AR)', 'Allocated revenue', 'Allocated expense', 'Allocated net', 'Currency']),
        ];

        foreach ($report['centers'] as $line) {
            $center = $line['center'];
            $rows[] = [
                $center['code'],
                $center['name_en'],
                $center['name_ar'],
                $this->money($line['allocated_revenue_minor'], $exp),
                $this->money($line['allocated_expense_minor'], $exp),
                $this->money($line['allocated_net_minor'], $exp),
                $currency,
            ];
        }

        $rows[] = $this->total([
            'Total', '', '',
            $this->money($report['grand_allocated_revenue_minor'], $exp),
            $this->money($report['grand_allocated_expense_minor'], $exp),
            $this->money($report['grand_allocated_net_minor'], $exp),
            $currency,
        ]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report  Result of CostCenterService::unallocated()
     * @param  string  $currency  Base currency code (the report has no currency of its own)
     */
    public function costCenterUnallocatedRows(array $report, string $currency): array
    {
        $exp = $this->exponent($currency);
        $rows = [
            ['Cost center unallocated lines', $report['from'], $report['to']],
            $this->header(['Entry no.', 'Date', 'Account', 'Amount', 'Allocation', 'Currency']),
        ];

        foreach ($report['lines'] as $line) {
            $rows[] = [
                $line['journal_entry'],
                $this->date($line['entry_date']),
                $line['account'],
                $this->money($line['amount_minor'], $exp),
                $line['allocation_type'],
                $currency,
            ];
        }

        $rows[] = $this->total([
            'Total', '', '', $this->money($report['unallocated_total_minor'], $exp), '', $currency,
        ]);

        return $rows;
    }

    /** @param array<string, mixed> $report Result of FixedAssetReportService::carryingValues() */
    public function fixedAssetCarryingValuesRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Fixed assets - carrying values', $this->date($report['as_of'])],
            $this->header(['Code', 'Description', 'Category', 'Branch', 'Status', 'Capitalization date', 'Disposed on', 'Cost', 'Accumulated depreciation', 'Carrying value', 'Currency']),
        ];

        foreach ($report['assets'] as $asset) {
            $rows[] = [
                $asset['code'], $asset['description'], $asset['category'], $asset['branch']['name_en'] ?? '', $asset['status'],
                $this->date($asset['capitalization_date']), $this->date($asset['disposed_on']),
                $this->money($asset['cost_minor'], $exp),
                $this->money($asset['accumulated_minor'], $exp),
                $this->money($asset['carrying_value_minor'], $exp),
                $currency,
            ];
        }

        $rows[] = $this->total([
            'Total', '', '', '', '', '', '',
            $this->money($report['total_cost_minor'], $exp),
            $this->money($report['total_accumulated_minor'], $exp),
            $this->money($report['total_carrying_value_minor'], $exp),
            $currency,
        ]);

        return $rows;
    }

    /** @param array<string, mixed> $report Result of FixedAssetReportService::rollForward() */
    public function fixedAssetRollForwardRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Fixed assets - roll-forward', $report['from'], $report['to']],
            $this->header(['Section', 'Line', 'Amount', 'Currency']),
        ];

        $sections = [
            'Cost' => ['cost', ['opening_minor' => 'Opening', 'additions_minor' => 'Additions', 'disposals_minor' => 'Disposals', 'closing_minor' => 'Closing']],
            'Accumulated depreciation' => ['accumulated_depreciation', ['opening_minor' => 'Opening', 'depreciation_minor' => 'Depreciation', 'impairment_minor' => 'Impairment', 'disposals_minor' => 'Disposals', 'closing_minor' => 'Closing']],
            'Carrying value' => ['carrying_value', ['opening_minor' => 'Opening', 'closing_minor' => 'Closing', 'register_closing_minor' => 'Register closing', 'difference_minor' => 'Difference']],
        ];

        foreach ($sections as $label => [$key, $lines]) {
            foreach ($lines as $field => $line) {
                $row = [$label, $line, $this->money($report[$key][$field], $exp), $currency];
                $rows[] = $line === 'Closing' ? $this->total($row) : $row;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $report Result of FixedAssetReportService::movements() */
    public function fixedAssetMovementsRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Fixed assets - movements', $report['from'], $report['to']],
            $this->header(['Type', 'Code', 'Description', 'Category', 'Branch', 'Date', 'Cost', 'Accumulated depreciation', 'Proceeds', 'Gain / loss', 'Currency']),
        ];

        foreach ($report['additions'] as $asset) {
            $rows[] = [
                'Addition', $asset['code'], $asset['description'], $asset['category'], $asset['branch']['name_en'] ?? '',
                $this->date($asset['capitalization_date']), $this->money((int) $asset['cost_minor'], $exp), '', '', '', $currency,
            ];
        }
        $rows[] = $this->total(['Total additions', '', '', '', '', '', $this->money($report['additions_total_minor'], $exp), '', '', '', $currency]);

        foreach ($report['disposals'] as $asset) {
            $rows[] = [
                'Disposal', $asset['code'], $asset['description'], $asset['category'], $asset['branch']['name_en'] ?? '',
                $this->date($asset['disposal_date']),
                $this->money((int) $asset['cost_minor'], $exp),
                $this->money((int) $asset['accumulated_minor'], $exp),
                $this->money((int) $asset['proceeds_minor'], $exp),
                $this->money((int) $asset['gain_loss_minor'], $exp),
                $currency,
            ];
        }
        $rows[] = $this->total(['Total disposals', '', '', '', '', '', $this->money($report['disposals_total_minor'], $exp), '', '', '', $currency]);

        return $rows;
    }

    /** @param array<string, mixed> $report Result of FixedAssetReportService::reconciliation() */
    public function fixedAssetReconciliationRows(array $report): array
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $opt = fn (?int $minor) => $minor === null ? '' : $this->money($minor, $exp);

        $rows = [
            ['Fixed assets - reconciliation', $this->date($report['as_of']), 'Scope: '.$report['scope']],
            ['Reconciled', $report['is_reconciled'] ? 'Yes' : 'No'],
            ['Cost difference', $this->money($report['cost_difference_minor'], $exp), $currency],
            ['Accumulated depreciation difference', $this->money($report['accumulated_difference_minor'], $exp), $currency],
            $this->header(['Category', 'Name', 'Register cost', 'GL cost', 'Cost difference', 'Register accumulated', 'GL accumulated', 'Accumulated difference', 'Reconciled', 'Currency']),
        ];

        foreach ($report['categories'] as $category) {
            $rows[] = [
                $category['category_code'], $category['category_name'],
                $this->money($category['register_cost_minor'], $exp),
                $opt($category['gl_cost_minor']),
                $opt($category['cost_difference_minor']),
                $this->money($category['register_accumulated_minor'], $exp),
                $opt($category['gl_accumulated_minor']),
                $opt($category['accumulated_difference_minor']),
                $category['is_reconciled'] ? 'Yes' : 'No',
                $currency,
            ];
        }

        $rows[] = $this->header(['Role', 'Account', 'Name', 'Register', 'GL', 'Difference', 'Reconciled', 'Currency']);
        foreach ($report['accounts'] as $account) {
            $rows[] = [
                $account['role'], $account['account']['code'] ?? '', $account['account']['name_en'] ?? '',
                $this->money($account['register_minor'], $exp), $this->money($account['gl_minor'], $exp),
                $this->money($account['difference_minor'], $exp), $account['is_reconciled'] ? 'Yes' : 'No', $currency,
            ];
        }

        foreach ($report['unregistered_accounts'] as $account) {
            $rows[] = [
                'Unregistered', $account['account']['code'] ?? '', $account['account']['name_en'] ?? '',
                '', $this->money($account['gl_minor'], $exp), $this->money($account['difference_minor'], $exp), 'No', $currency,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $schedule  Result of FixedAssetReportService::schedule()
     * @param  string  $currency  Base currency code
     */
    public function fixedAssetScheduleRows(array $schedule, string $currency): array
    {
        $exp = $this->exponent($currency);
        $rows = [
            ['Fixed assets - depreciation schedule', $currency],
            $this->header(['Code', 'Description', 'Period', 'Depreciation', 'Accumulated', 'Carrying value', 'Status', 'Currency']),
        ];

        $line = fn (array $period, string $code, string $description): array => [
            $code, $description, $period['period'],
            $this->money((int) $period['amount_minor'], $exp),
            $this->money((int) $period['accumulated_minor'], $exp),
            $this->money((int) $period['carrying_value_minor'], $exp),
            $period['status'] ?? '', $currency,
        ];

        foreach ($schedule as $entry) {
            if (isset($entry['periods'])) {
                foreach ($entry['periods'] as $period) {
                    $rows[] = $line($period, (string) $entry['code'], (string) $entry['description']);
                }
            } else {
                $rows[] = $line($entry, '', '');
            }
        }

        return $rows;
    }

    // ---- CSV renderings of the typed rows ---------------------------------

    /** @param array<string, mixed> $report */
    public function trialBalance(array $report): string
    {
        return $this->build($this->trialBalanceRows($report));
    }

    /** @param array<string, mixed> $ledger */
    public function generalLedger(array $ledger): string
    {
        return $this->build($this->generalLedgerRows($ledger));
    }

    /** @param array<string, mixed> $statement */
    public function partyStatement(array $statement): string
    {
        return $this->build($this->partyStatementRows($statement));
    }

    /** @param array<string, mixed> $report */
    public function agingReconciliation(array $report): string
    {
        return $this->build($this->agingReconciliationRows($report));
    }

    /** @param array<string, mixed> $report */
    public function incomeStatement(array $report): string
    {
        return $this->build($this->incomeStatementRows($report));
    }

    /** @param array<string, mixed> $report */
    public function balanceSheet(array $report): string
    {
        return $this->build($this->balanceSheetRows($report));
    }

    /** @param array<string, mixed> $report */
    public function aging(array $report): string
    {
        return $this->build($this->agingRows($report));
    }
}
