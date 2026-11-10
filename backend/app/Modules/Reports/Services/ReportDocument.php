<?php

namespace App\Modules\Reports\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/** Converts the canonical spreadsheet-safe report CSV into native documents. */
class ReportDocument
{
    public function xlsx(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the report export file.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($this->rows($csv) as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();

            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Unable to read the report export file.');
            }

            return $content;
        } finally {
            @unlink($path);
        }
    }

    public function pdf(string $csv, string $title, string $language = 'bilingual'): string
    {
        $rtl = in_array($language, ['ar', 'bilingual'], true);
        $rows = $this->rows($csv);
        $html = '<!doctype html><html lang="'.($language === 'en' ? 'en' : 'ar').'" dir="'.($rtl ? 'rtl' : 'ltr').'"><head><meta charset="UTF-8">'
            .'<style>@page{margin:24px}body{font-family:"DejaVu Sans",sans-serif;font-size:9px;color:#172033}'
            .'h1{font-size:16px;margin:0 0 14px}table{border-collapse:collapse;width:100%;direction:'.($rtl ? 'rtl' : 'ltr').'}'
            .'th,td{border:1px solid #d8dee9;padding:5px;text-align:start;vertical-align:top}th{background:#eef2f7;font-weight:bold}'
            .'tr:nth-child(even) td{background:#fafbfc}</style></head><body><h1>'.$this->escape($title).'</h1><table>';

        foreach ($rows as $index => $row) {
            $tag = $index === 0 ? 'th' : 'td';
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<'.$tag.'>'.$this->escape($cell).'</'.$tag.'>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></body></html>';

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return array<int, array<int, string>> */
    private function rows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(static fn ($cell): string => (string) ($cell ?? ''), $row);
        }
        fclose($handle);

        return $rows;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
