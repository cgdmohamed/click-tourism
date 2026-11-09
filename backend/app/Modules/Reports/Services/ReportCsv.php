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
        $rows = [['Code', 'Name (EN)', 'Name (AR)', 'Debit', 'Credit', 'Balance', 'Currency']];

        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row->code, $row->name_en, $row->name_ar,
                $this->amount($row->debit_minor, $exp),
                $this->amount($row->credit_minor, $exp),
                $this->amount($row->balance_minor, $exp),
                $report['currency'],
            ];
        }

        $rows[] = [
            '', 'Total', '',
            $this->amount($report['total_debit_minor'], $exp),
            $this->amount($report['total_credit_minor'], $exp),
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
}
