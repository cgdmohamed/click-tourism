<?php

namespace App\Modules\Reports\Services;

use App\Modules\Money\Models\Currency;

/**
 * Builds spreadsheet-safe CSV for report exports.
 *
 * Amounts are written as plain decimals derived from integer minor units (no
 * floats). Text cells that a spreadsheet could interpret as a formula are
 * prefixed with an apostrophe (OWASP "CSV injection").
 */
class ReportCsv
{
    /** @param  array<int, array<int, string|int|null>>  $rows */
    public function build(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($cell) => $this->cell($cell), $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // UTF-8 BOM so Excel opens Arabic text correctly.
        return "\xEF\xBB\xBF".$csv;
    }

    public function cell(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        if ($text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
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

    public function exponent(string $currency): int
    {
        return (int) (Currency::query()->where('code', $currency)->value('exponent') ?? 2);
    }

    /** @param  array<string, mixed>  $report  Result of ReportService::trialBalance() */
    public function trialBalance(array $report): string
    {
        $exp = $this->exponent($report['currency']);
        $rows = [[
            'Code', 'Name (EN)', 'Name (AR)',
            'Opening debit', 'Opening credit', 'Debit', 'Credit',
            'Closing debit', 'Closing credit', 'Abnormal', 'Currency',
        ]];

        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row->code, $row->name_en, $row->name_ar,
                $this->amount($row->opening_debit_minor, $exp),
                $this->amount($row->opening_credit_minor, $exp),
                $this->amount($row->debit_minor, $exp),
                $this->amount($row->credit_minor, $exp),
                $this->amount($row->closing_debit_minor, $exp),
                $this->amount($row->closing_credit_minor, $exp),
                ($row->abnormal ?? false) ? 'Yes' : '',
                $report['currency'],
            ];
        }

        $rows[] = [
            '', 'Total', '',
            $this->amount($report['total_opening_debit_minor'], $exp),
            $this->amount($report['total_opening_credit_minor'], $exp),
            $this->amount($report['total_debit_minor'], $exp),
            $this->amount($report['total_credit_minor'], $exp),
            $this->amount($report['total_closing_debit_minor'], $exp),
            $this->amount($report['total_closing_credit_minor'], $exp),
            '',
            $report['currency'],
        ];

        return $this->build($rows);
    }

    /**
     * @param  array{summary: array<string, mixed>, lines: array<int, array<string, mixed>>}  $ledger
     */
    public function generalLedger(array $ledger): string
    {
        $summary = $ledger['summary'];
        $currency = $summary['currency'];
        $exp = $this->exponent($currency);

        $rows = [
            ['Account', $summary['account']['code'], $summary['account']['name_en'], $summary['account']['name_ar']],
            ['Period', $summary['from'], $summary['to'], "Basis: {$summary['basis']}"],
            ['Opening balance', '', '', '', '', $this->amount($summary['opening_minor'], $exp), $currency],
            ['Date', 'Entry no.', 'Account', 'Description', 'Debit', 'Credit', 'Balance', 'Currency', 'Source'],
        ];

        foreach ($ledger['lines'] as $line) {
            $debit = $summary['basis'] === 'original' ? $line['debit_minor'] : $line['base_debit_minor'];
            $credit = $summary['basis'] === 'original' ? $line['credit_minor'] : $line['base_credit_minor'];

            $rows[] = [
                $line['entry_date'],
                $line['number'],
                $line['account_code'],
                $line['description'] ?: $line['memo'],
                $this->amount($debit, $exp),
                $this->amount($credit, $exp),
                $this->amount($line['balance_minor'], $exp),
                $currency,
                $line['source_type'],
            ];
        }

        $rows[] = [
            'Totals', '', '', '',
            $this->amount($summary['total_debit_minor'], $exp),
            $this->amount($summary['total_credit_minor'], $exp),
            $this->amount($summary['closing_minor'], $exp),
            $currency,
        ];

        return $this->build($rows);
    }

    /** @param array<string, mixed> $statement Result of PartyStatementService::statement() */
    public function partyStatement(array $statement): string
    {
        $currency = $statement['currency'];
        $exp = $this->exponent($currency);
        $reconciliation = $statement['reconciliation'];

        $rows = [
            ['Party', $statement['party']['name_en'], $statement['party']['name_ar'], $statement['party_type']],
            ['Period', $statement['from'], $statement['to'], "Basis: {$statement['basis']}"],
            ['Control account', $statement['control_account']['code'], $statement['control_account']['name_en'], $statement['control_account']['name_ar']],
            ['Opening balance', '', '', '', '', $this->amount($statement['opening_minor'], $exp), $currency],
            ['Date', 'Entry no.', 'Account', 'Description', 'Source', 'Debit', 'Credit', 'Balance', 'Currency'],
        ];

        foreach ($statement['lines'] as $line) {
            $debit = $statement['basis'] === 'original' ? $line['debit_minor'] : $line['base_debit_minor'];
            $credit = $statement['basis'] === 'original' ? $line['credit_minor'] : $line['base_credit_minor'];

            $rows[] = [
                $line['entry_date'],
                $line['number'],
                $line['account_code'],
                $line['description'] ?: $line['memo'],
                $line['source_reference'] ?: $line['source_type'],
                $this->amount($debit, $exp),
                $this->amount($credit, $exp),
                $this->amount($line['balance_minor'], $exp),
                $currency,
            ];
        }

        $rows[] = [
            'Totals', '', '', '', '',
            $this->amount($statement['total_debit_minor'], $exp),
            $this->amount($statement['total_credit_minor'], $exp),
            $this->amount($statement['closing_minor'], $exp),
            $currency,
        ];

        $rows[] = [];
        $rows[] = ['Reconciliation', 'Ledger', 'Operational', 'Difference', 'Currency'];
        $rows[] = [
            'Open invoices / advances',
            $this->amount($reconciliation['normalized_ledger_minor'], $exp),
            $this->amount($reconciliation['operational_open_invoices_minor'], $exp),
            $this->amount($reconciliation['difference_minor'], $exp),
            $currency,
        ];
        $rows[] = ['Unapplied credits', $this->amount($reconciliation['unapplied_credits_minor'], $exp), '', '', $currency];

        foreach ($reconciliation['differences'] as $difference) {
            $rows[] = ['Difference: '.$difference['code'], $this->amount($difference['amount_minor'], $exp), '', '', $currency];
        }

        return $this->build($rows);
    }

    /** @param array<string, mixed> $report Result of AgingReconciliationService::reconcile() */
    public function agingReconciliation(array $report): string
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);

        $rows = [
            ['Type', $report['type'], 'As of', $report['as_of']],
            ['Control account', $report['ledger']['control_account']['code'], $report['ledger']['control_account']['name_en']],
            ['Ledger balance', $this->amount($report['ledger']['balance_minor'], $exp), $currency, ''],
            ['Open invoices', $this->amount($report['operational']['open_invoices_minor'], $exp), $currency, ''],
            ['Unapplied advances', $this->amount($report['operational']['unapplied_advances_minor'], $exp), $currency, ''],
            ['Difference', $this->amount($report['difference_minor'], $exp), $currency, $report['reconciled'] ? 'Yes' : 'No'],
            [],
            ['Bucket', 'Amount', 'Currency', ''],
        ];

        foreach ($report['operational']['buckets'] as $bucket) {
            $rows[] = [$bucket['key'], $this->amount($bucket['amount_minor'], $exp), $currency, ''];
        }

        $rows[] = ['Total open invoices', $this->amount($report['operational']['open_invoices_minor'], $exp), $currency, ''];
        $rows[] = [];
        $rows[] = ['Party', 'Ledger', 'Open invoices', 'Difference', 'Currency'];

        foreach ($report['parties'] as $party) {
            $rows[] = [
                $party['name_en'] ?? $party['name_ar'] ?? $party['party_id'],
                $this->amount($party['ledger_minor'], $exp),
                $this->amount($party['open_invoices_minor'], $exp),
                $this->amount($party['difference_minor'], $exp),
                $currency,
            ];
        }

        foreach ($report['differences'] as $difference) {
            $rows[] = ['Difference: '.$difference['code'], $this->amount($difference['amount_minor'], $exp), '', '', $currency];
        }

        return $this->build($rows);
    }

    /** @param array<string, mixed> $report Result of ReportService::incomeStatement() */
    public function incomeStatement(array $report): string
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Income statement', $report['from'], $report['to']],
            ['Section', 'Code', 'Name (EN)', 'Name (AR)', 'Amount', 'Currency'],
        ];

        foreach ($report['revenue'] as $line) {
            $rows[] = ['Revenue', $line['code'], $line['name_en'], $line['name_ar'], $this->amount($line['amount_minor'], $exp), $currency];
        }
        $rows[] = ['Total revenue', '', '', '', $this->amount($report['total_revenue_minor'], $exp), $currency];

        // Older payloads without the split fall back to a single expense list.
        $cost = $report['cost_of_services'] ?? [];
        $opex = $report['operating_expenses'] ?? $report['expenses'];

        foreach ($cost as $line) {
            $rows[] = ['Cost of services', $line['code'], $line['name_en'], $line['name_ar'], $this->amount($line['amount_minor'], $exp), $currency];
        }
        if (isset($report['total_cost_of_services_minor'])) {
            $rows[] = ['Total cost of services', '', '', '', $this->amount($report['total_cost_of_services_minor'], $exp), $currency];
            $rows[] = ['Gross profit', '', '', '', $this->amount($report['gross_profit_minor'], $exp), $currency];
        }

        foreach ($opex as $line) {
            $rows[] = ['Operating expense', $line['code'], $line['name_en'], $line['name_ar'], $this->amount($line['amount_minor'], $exp), $currency];
        }
        if (isset($report['total_operating_expenses_minor'])) {
            $rows[] = ['Total operating expenses', '', '', '', $this->amount($report['total_operating_expenses_minor'], $exp), $currency];
            $rows[] = ['Operating profit', '', '', '', $this->amount($report['operating_profit_minor'], $exp), $currency];
        }
        $rows[] = ['Total expenses', '', '', '', $this->amount($report['total_expense_minor'], $exp), $currency];
        $rows[] = ['Net profit', '', '', '', $this->amount($report['net_profit_minor'], $exp), $currency];

        return $this->build($rows);
    }

    /** @param array<string, mixed> $report Result of ReportService::balanceSheet() */
    public function balanceSheet(array $report): string
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Balance sheet', $report['as_of']],
            ['Section', 'Code', 'Name (EN)', 'Name (AR)', 'Amount', 'Currency'],
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
                $rows[] = [$label, $line['code'], $line['name_en'], $line['name_ar'], $this->amount($line['amount_minor'], $exp), $currency];
            }
        }

        if (isset($report['current_assets_minor'])) {
            foreach ([
                'current_assets_minor' => 'Total current assets',
                'non_current_assets_minor' => 'Total non-current assets',
                'current_liabilities_minor' => 'Total current liabilities',
                'non_current_liabilities_minor' => 'Total non-current liabilities',
            ] as $key => $label) {
                $rows[] = [$label, '', '', '', $this->amount($report[$key], $exp), $currency];
            }
            $rows[] = ['Classification', $report['classification'] ?? ''];
        }

        $rows[] = ['Current earnings', '', '', '', $this->amount($report['current_earnings_minor'], $exp), $currency];
        $rows[] = ['Total assets', '', '', '', $this->amount($report['total_assets_minor'], $exp), $currency];
        $rows[] = ['Total liabilities and equity', '', '', '', $this->amount($report['total_liabilities_and_equity_minor'], $exp), $currency];
        $rows[] = ['Balanced', $report['balanced'] ? 'Yes' : 'No'];

        return $this->build($rows);
    }

    /** @param array<string, mixed> $report Result of ReportService::aging() */
    public function aging(array $report): string
    {
        $currency = $report['currency'];
        $exp = $this->exponent($currency);
        $rows = [
            ['Aging', $report['type'], $report['as_of']],
            ['Bucket', 'Amount', 'Currency'],
        ];

        foreach ($report['buckets'] as $bucket) {
            $rows[] = [$bucket['label'], $this->amount($bucket['amount_minor'], $exp), $currency];
        }

        $rows[] = ['Total', $this->amount($report['total_minor'], $exp), $currency];

        return $this->build($rows);
    }
}
