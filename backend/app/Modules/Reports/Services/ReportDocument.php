<?php

namespace App\Modules\Reports\Services;

use App\Modules\Core\Services\SettingService;
use App\Modules\Reports\Support\DateCell;
use App\Modules\Reports\Support\MoneyCell;
use App\Modules\Reports\Support\ReportRow;
use App\Support\ArabicText;
use App\Support\LogoDataUri;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Renders typed report rows (see ReportCsv) as native XLSX and PDF documents.
 *
 * XLSX: real numeric and date cells, formula-injection safe text cells.
 * PDF: Dompdf with the bundled DejaVu Sans font (no remote fetch), Arabic
 * shaping/bidi via ArabicText, letterhead, repeated table header and page
 * numbers on every page.
 */
class ReportDocument
{
    /** PDF rendering is memory heavy; larger reports must use CSV or XLSX. */
    public const PDF_MAX_ROWS = 5000;

    public function __construct(private readonly SettingService $settings, private readonly ReportCsv $csv = new ReportCsv) {}

    // ------------------------------------------------------------------
    // XLSX
    // ------------------------------------------------------------------

    /** @param array<int, ReportRow|array<int, mixed>> $rows */
    public function xlsx(array $rows, string $title = 'Report'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the report export file.');
        }

        // Shortest round-trip float formatting, so decimals are written exactly (1234.56, not 1234.5599999).
        $previousPrecision = ini_get('precision');
        ini_set('precision', '-1');

        try {
            $options = new XlsxOptions;
            $options->setProperties(new Properties(
                title: $this->plainTitle($title),
                creator: (string) $this->settings->get('general', 'company_name_en', 'Tourism ERP'),
            ));
            foreach ($this->columnWidths($rows) as $column => $width) {
                $options->setColumnWidth($width, $column + 1);
            }

            $writer = new Writer($options);
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName($this->sheetName($title));

            $header = (new Style)->setFontBold()->setBackgroundColor('EEF2F7');
            $total = (new Style)->setFontBold();
            $money = [];
            $date = (new Style)->setFormat('yyyy-mm-dd');

            foreach ($rows as $row) {
                $kind = $row instanceof ReportRow ? $row->kind : ReportRow::DATA;
                $rowStyle = match ($kind) {
                    ReportRow::HEADER => $header,
                    ReportRow::TOTAL => $total,
                    default => null,
                };
                $cells = [];
                foreach ($row instanceof ReportRow ? $row->cells : $row as $value) {
                    $cells[] = $this->xlsxCell($value, $money, $date, $rowStyle);
                }
                $writer->addRow(new Row($cells));
            }

            $writer->close();

            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Unable to read the report export file.');
            }

            return $content;
        } finally {
            ini_set('precision', (string) $previousPrecision);
            @unlink($path);
        }
    }

    /** @param array<string, Style> $moneyStyles */
    private function xlsxCell(mixed $value, array &$moneyStyles, Style $dateStyle, ?Style $rowStyle): Cell
    {
        if ($value === null || $value === '') {
            return new EmptyCell(null, $rowStyle);
        }

        if ($value instanceof MoneyCell) {
            // Beyond 15 significant digits a spreadsheet cannot hold the value exactly: keep it as text.
            if (strlen(ltrim($value->decimal, '-')) - ($value->exponent > 0 ? 1 : 0) > 15) {
                return new StringCell($value->decimal, $rowStyle);
            }
            $bold = $rowStyle !== null && $rowStyle->isFontBold();
            $style = $moneyStyles[$value->exponent.($bold ? 'b' : '')] ??= $this->moneyStyle($value->exponent, $bold);

            return new NumericCell((float) $value->decimal, $style);
        }

        if ($value instanceof DateCell) {
            return new DateTimeCell(new DateTimeImmutable($value->date), $dateStyle);
        }

        if (is_int($value)) {
            return new NumericCell($value, $rowStyle);
        }

        // Always an explicit string cell, never auto-detected as a formula; the
        // apostrophe prefix additionally protects copies pasted into other tools.
        return new StringCell($this->csv->text((string) $value), $rowStyle);
    }

    private function moneyStyle(int $exponent, bool $bold): Style
    {
        $format = $exponent > 0 ? '#,##0.'.str_repeat('0', $exponent) : '#,##0';
        $style = (new Style)->setFormat($format);
        if ($bold) {
            $style->setFontBold();
        }

        return $style;
    }

    /** @param array<int, ReportRow|array<int, mixed>> $rows @return array<int, float> */
    private function columnWidths(array $rows): array
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row instanceof ReportRow ? $row->cells : $row as $i => $value) {
                $widths[$i] = max($widths[$i] ?? 8, min(60, mb_strlen((string) $value) + 2));
            }
        }

        return array_map(static fn (int $w): float => (float) $w, $widths);
    }

    private function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', $this->plainTitle($title)));

        return mb_substr($name !== '' ? $name : 'Report', 0, 31);
    }

    /** The English part of an "English / Arabic" title. */
    private function plainTitle(string $title): string
    {
        return trim(explode(' / ', $title)[0]);
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /**
     * @param  array<int, ReportRow|array<int, mixed>>  $rows
     * @param  array{branch?: ?array{name_ar?: ?string, name_en?: ?string, code?: ?string}, period?: ?string, currency?: ?string}  $meta
     */
    public function pdf(array $rows, string $title, string $language = 'bilingual', array $meta = []): string
    {
        $rtl = in_array($language, ['ar', 'bilingual'], true);
        $lang = $language === 'en' ? 'en' : 'ar';
        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';
        $labels = $this->labels($language);

        [$companyLines, $docTitle] = $this->identity($language, $title);
        $timezone = (string) $this->settings->get('general', 'timezone', 'UTC');
        try {
            $generated = (new DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i');
        } catch (\Exception) {
            $generated = gmdate('Y-m-d H:i');
        }

        [$preamble, $headerRow, $body] = $this->split($rows);
        $cols = max(1, ...array_map(static fn ($r): int => count($r instanceof ReportRow ? $r->cells : $r), $rows ?: [[]]));
        $maxChars = max(14, (int) floor(1100 / $cols / 3.2));

        $branchLine = $this->branchLine($meta['branch'] ?? null, $language, $labels);

        $logo = $this->logoDataUri();

        $metaLines = [];
        $metaLines[] = $this->text($labels['branch'].': '.$branchLine, $rtl);
        if (! empty($meta['period'])) {
            $metaLines[] = $this->text($labels['period'].': '.$meta['period'], $rtl);
        }
        if (! empty($meta['currency'])) {
            $metaLines[] = $this->text($labels['currency'].': '.$meta['currency'], $rtl);
        }
        $metaLines[] = $this->text($labels['generated'].': '.$generated, $rtl);
        // DEC-025: internal management reporting, never presented as externally certified.
        $metaLines[] = $this->text($labels['internal'], $rtl);

        $logoCell = $logo !== null ? '<td class="logo"><img src="'.$logo.'" alt=""></td>' : '';
        $nameCell = '<td class="company">'.implode('<br>', array_map(fn (string $l): string => $this->escape($this->text($l, $rtl)), $companyLines)).'</td>';
        $titleCell = '<td class="doc-title"><div class="t">'.$this->escape($this->text($docTitle, $rtl)).'</div><div class="m">'
            .implode('<br>', array_map($this->escape(...), $metaLines)).'</div></td>';
        $headerCells = [$logoCell, $nameCell, $titleCell];
        // The first cell sits at the reading start: left for LTR, right for RTL.
        if ($rtl) {
            $headerCells = array_reverse($headerCells);
        }

        $style = '@page{margin:112px 24px 44px 24px;size:A4 landscape;}'
            .'body{font-family:"DejaVu Sans",sans-serif;font-size:8px;color:#172033;direction:ltr}'
            .'#hdr{position:fixed;top:-102px;left:0;right:0;height:96px;border-bottom:2px solid #172033}'
            .'#hdr table{width:100%;border-collapse:collapse}'
            .'#hdr td{border:0;padding:0 4px;vertical-align:top;background:none}'
            .'#hdr .logo img{max-height:44px;max-width:120px}'
            .'#hdr .company{font-size:13px;font-weight:bold;text-align:'.$align.'}'
            .'#hdr .doc-title{text-align:'.($rtl ? 'left' : 'right').';font-size:7px;color:#4a5568}'
            .'#hdr .doc-title .t{font-size:12px;font-weight:bold;color:#172033;margin-bottom:3px}'
            .'#ftr{position:fixed;bottom:-34px;left:0;right:0;height:22px;border-top:1px solid #d8dee9;font-size:7px;color:#718096;padding-top:3px;text-align:'.$align.'}'
            .'table.data{border-collapse:collapse;width:100%;margin-top:6px}'
            .'table.data th,table.data td{border:1px solid #d8dee9;padding:3px 4px;vertical-align:top;text-align:'.$align.'}'
            .'table.data th{background:#eef2f7;font-weight:bold}'
            .'table.data thead{display:table-header-group}'
            .'table.data tr{page-break-inside:avoid}'
            .'table.data td.num,table.data th.num{text-align:right}'
            .'tr.total td{font-weight:bold;background:#f3f5f9}'
            .'tr.spacer td{border:0;height:6px;padding:0}'
            .'table.pre{border-collapse:collapse;margin-bottom:4px}'
            .'table.pre td{border:0;padding:1px 8px 1px 0;text-align:'.$align.'}'
            .'.signoff{margin-top:22px;font-size:8px;page-break-inside:avoid}'
            .'.signoff td{border:0;padding:14px 6px 0 6px;text-align:'.$align.'}';

        $html = '<!doctype html><html lang="'.$lang.'" dir="'.$dir.'"><head><meta charset="UTF-8"><style>'.$style.'</style></head><body>'
            .'<div id="hdr"><table><tr>'.implode('', $headerCells).'</tr></table></div>'
            .'<div id="ftr">'.$this->escape($this->text($companyLines[0].' - '.$docTitle, $rtl)).'</div>';

        if ($preamble !== []) {
            $html .= '<table class="pre">';
            foreach ($preamble as $row) {
                $html .= $this->htmlRow($row, 'td', '', $cols, $rtl, $maxChars, false);
            }
            $html .= '</table>';
        }

        $html .= '<table class="data">';
        if ($headerRow !== null) {
            $html .= '<thead>'.$this->htmlRow($headerRow, 'th', '', $cols, $rtl, $maxChars, true).'</thead>';
        }
        $html .= '<tbody>';
        foreach ($body as $row) {
            $cells = $row instanceof ReportRow ? $row->cells : $row;
            if ($cells === []) {
                $html .= '<tr class="spacer"><td colspan="'.$cols.'"></td></tr>';

                continue;
            }
            $kind = $row instanceof ReportRow ? $row->kind : ReportRow::DATA;
            $html .= $this->htmlRow($row, $kind === ReportRow::HEADER ? 'th' : 'td', $kind === ReportRow::TOTAL ? 'total' : '', $cols, $rtl, $maxChars, true);
        }
        $html .= '</tbody></table>';

        if ($this->settings->bool('general', 'report_signoff', true)) {
            $names = [$labels['prepared'], $labels['approved'], $labels['date']];
            if ($rtl) {
                $names = array_reverse($names);
            }
            $html .= '<table class="signoff" style="width:100%"><tr>';
            foreach ($names as $name) {
                $html .= '<td style="width:33%">'.$this->escape($this->text($name, $rtl)).': ____________________</td>';
            }
            $html .= '</tr></table>';
        }

        $html .= '</body></html>';

        $dompdf = new Dompdf($this->dompdfOptions());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // Page numbers need the final page count, so they are drawn after layout.
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $size = 7.5;
        $sample = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], '00', $this->pageText($labels, $rtl));
        $width = $metrics->getTextWidth($sample, $font, $size);
        $canvas->page_text(
            ($canvas->get_width() - $width) / 2,
            $canvas->get_height() - 24,
            $this->pageText($labels, $rtl),
            $font,
            $size,
            [0.44, 0.5, 0.56],
        );

        return $dompdf->output();
    }

    /** Count of PDF pages is not needed by callers; exposed for tests that assert multi-page output. */
    public static function pageCount(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf) ?: 0;
    }

    private function pageText(array $labels, bool $rtl): string
    {
        return $rtl
            ? '{PAGE_NUM} / {PAGE_COUNT} '.ArabicText::visual($labels['page'], true)
            : $labels['page'].' {PAGE_NUM} / {PAGE_COUNT}';
    }

    private function dompdfOptions(): Options
    {
        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('allowedProtocols', ['data://' => ['rules' => []]]);
        if (is_dir($cache) && is_writable($cache)) {
            $options->set('fontCache', $cache);
        }

        return $options;
    }

    /**
     * @param  array<int, ReportRow|array<int, mixed>>  $rows
     * @return array{0: array<int, ReportRow|array<int, mixed>>, 1: ReportRow|array<int, mixed>|null, 2: array<int, ReportRow|array<int, mixed>>}
     */
    private function split(array $rows): array
    {
        $first = null;
        foreach ($rows as $i => $row) {
            if ($row instanceof ReportRow && $row->kind === ReportRow::HEADER) {
                $first = $i;
                break;
            }
        }
        if ($first === null) {
            return [[], null, $rows];
        }

        // Rows above the first header are report parameters (account, period, ...).
        $preamble = array_values(array_filter(array_slice($rows, 0, $first), static fn ($r): bool => ($r instanceof ReportRow ? $r->cells : $r) !== []));

        return [$preamble, $rows[$first], array_slice($rows, $first + 1)];
    }

    /** @param ReportRow|array<int, mixed> $row */
    private function htmlRow(ReportRow|array $row, string $tag, string $class, int $cols, bool $rtl, int $maxChars, bool $pad): string
    {
        $cells = $row instanceof ReportRow ? $row->cells : $row;
        if ($pad) {
            $cells = array_pad($cells, $cols, '');
        }
        if ($rtl) {
            $cells = array_reverse($cells);
        }

        $html = '<tr'.($class !== '' ? ' class="'.$class.'"' : '').'>';
        foreach ($cells as $cell) {
            if ($cell instanceof MoneyCell) {
                $html .= '<'.$tag.' class="num">'.$this->escape($this->groupDigits($cell->decimal)).'</'.$tag.'>';

                continue;
            }
            $text = (string) ($cell ?? '');
            $lines = ArabicText::hasArabic($text) ? ArabicText::wrap($text, $maxChars) : [$text];
            $lines = array_map(fn (string $line): string => $this->escape($this->text($line, $rtl)), $lines);
            $html .= '<'.$tag.'>'.implode('<br>', $lines).'</'.$tag.'>';
        }

        return $html.'</tr>';
    }

    /** 1234567.50 => 1,234,567.50 (string-based; no float). */
    private function groupDigits(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $parts = explode('.', ltrim($decimal, '-'), 2);
        $int = strrev(implode(',', str_split(strrev($parts[0]), 3)));

        return ($negative ? '-' : '').$int.(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    /** Arabic text in visual order for the left-to-right PDF renderer (direction from the first strong letter). */
    private function text(string $text, bool $rtl): string
    {
        return ArabicText::visual($text);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array{0: array<int, string>, 1: string} company name lines and document title
     */
    private function identity(string $language, string $title): array
    {
        $ar = (string) $this->settings->get('general', 'company_name_ar', '');
        $en = (string) $this->settings->get('general', 'company_name_en', '');
        $parts = explode(' / ', $title, 2);
        $titleEn = trim($parts[0]);
        $titleAr = trim($parts[1] ?? $parts[0]);

        return match ($language) {
            'en' => [[$en !== '' ? $en : $ar], $titleEn],
            'ar' => [[$ar !== '' ? $ar : $en], $titleAr],
            default => [array_values(array_filter([$ar, $en], static fn (string $n): bool => $n !== '')), $titleEn === $titleAr ? $titleEn : $titleAr.' / '.$titleEn],
        };
    }

    /** @param array{name_ar?: ?string, name_en?: ?string, code?: ?string}|null $branch */
    private function branchLine(?array $branch, string $language, array $labels): string
    {
        if ($branch === null) {
            return $labels['all_branches'];
        }

        $ar = (string) ($branch['name_ar'] ?? '');
        $en = (string) ($branch['name_en'] ?? '');
        $name = match ($language) {
            'en' => $en !== '' ? $en : $ar,
            'ar' => $ar !== '' ? $ar : $en,
            default => implode(' / ', array_unique(array_filter([$ar, $en]))),
        };

        return $name;
    }

    /** @return array<string, string> */
    private function labels(string $language): array
    {
        $en = [
            'branch' => 'Branch', 'period' => 'Period', 'currency' => 'Currency', 'generated' => 'Generated',
            'page' => 'Page', 'all_branches' => 'All branches', 'prepared' => 'Prepared by',
            'approved' => 'Approved by', 'date' => 'Date', 'internal' => 'Internal management report',
        ];
        $ar = [
            'branch' => 'الفرع', 'period' => 'الفترة', 'currency' => 'العملة', 'generated' => 'تاريخ الإصدار',
            'page' => 'صفحة', 'all_branches' => 'كل الفروع', 'prepared' => 'أعده',
            'approved' => 'اعتمده', 'date' => 'التاريخ', 'internal' => 'تقرير إداري للاستخدام الداخلي',
        ];

        if ($language === 'en') {
            return $en;
        }
        if ($language === 'ar') {
            return $ar;
        }

        $both = [];
        foreach ($en as $key => $label) {
            $both[$key] = $key === 'page' ? $ar[$key] : $ar[$key].' / '.$label;
        }

        return $both;
    }

    /** Letterhead logo as a data URI (png/jpeg only), from a storage path or an inline data URI. */
    private function logoDataUri(): ?string
    {
        return LogoDataUri::fromValue((string) $this->settings->get('general', 'company_logo', ''));
    }
}
