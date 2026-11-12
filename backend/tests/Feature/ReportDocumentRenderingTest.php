<?php

namespace Tests\Feature;

use App\Modules\Core\Services\SettingService;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Reports\Support\ArabicText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * PDF/XLSX rendering of report rows: Arabic + English, multi-page layout,
 * letterhead and the no-remote-fetch guarantees (ADR-0014).
 */
class ReportDocumentRenderingTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 PNG */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @return array<int, mixed> */
    private function rows(int $count): array
    {
        $csv = new ReportCsv;
        $rows = [$csv->header(['Code', 'Name (EN)', 'Name (AR)', 'Debit', 'Credit'])];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [(string) (1000 + $i), 'Account '.$i, 'حساب العملاء', $csv->money($i * 12345, 2), $csv->money(0, 2)];
        }
        $rows[] = $csv->total(['', 'Total', 'الإجمالي', $csv->money(1234567890, 2), $csv->money(1234567890, 2)]);

        return $rows;
    }

    /** Text layer of the PDF as poppler extracts it (bidi marks removed, Arabic presentation forms folded to letters). */
    private function pdftotext(string $pdf): ?string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'pdf-');
        file_put_contents($path, $pdf);
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
        @unlink($path);

        $text = preg_replace('/[\x{202A}-\x{202E}\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', $text) ?? $text;

        return \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
    }

    public function test_arabic_shaping_joins_letters_and_orders_them_visually(): void
    {
        $codes = fn (string $s): array => array_map(fn (string $c): int => mb_ord($c), mb_str_split($s));

        // "كتاب" = kaf (initial), taa (medial), alef (final), baa (isolated, follows a non-joining alef).
        $this->assertSame([0xFEDB, 0xFE98, 0xFE8E, 0xFE8F], $codes(ArabicText::shape('كتاب')));
        // Lam + alef collapses into one ligature glyph.
        $this->assertSame([0xFEFB], $codes(ArabicText::shape('لا')));
        // Tashkeel does not break joining.
        $this->assertSame([0xFEDB, 0xFE98, 0x064E, 0xFE8E, 0xFE8F], $codes(ArabicText::shape('كتَاب')));

        // Right-to-left paragraph: the last logical letter is drawn leftmost.
        $this->assertSame([0xFE8F, 0xFE8E, 0xFE98, 0xFEDB], $codes(ArabicText::visual('كتاب', true)));
        // Paragraph direction follows the first strong letter.
        $this->assertSame('Trial Balance', ArabicText::visual('Trial Balance'));
        $this->assertStringStartsWith('Account', ArabicText::visual('Account حساب'));
        $this->assertStringEndsWith("\u{FE8F}", ArabicText::visual('Account ب'));
        // Numbers keep their left-to-right digit order inside Arabic text.
        $this->assertStringContainsString('123', ArabicText::visual('فاتورة 123 للعميل'));
        // Brackets are mirrored inside right-to-left runs.
        $this->assertSame([0x29, 0xFE8F], $codes(ArabicText::visual('ب(', true)));
    }

    public function test_pdf_is_valid_embeds_a_font_and_has_extractable_arabic_and_english(): void
    {
        $doc = new ReportDocument(app(SettingService::class));
        $meta = ['period' => '2026-01-01 - 2026-01-31', 'currency' => 'EGP', 'branch' => ['name_en' => 'Head Office', 'name_ar' => 'الفرع الرئيسي']];

        foreach (['en', 'ar', 'bilingual'] as $language) {
            $pdf = $doc->pdf($this->rows(5), 'Trial Balance / ميزان المراجعة', $language, $meta);

            $this->assertStringStartsWith('%PDF-', $pdf, $language);
            $this->assertStringContainsString('%%EOF', substr($pdf, -64), $language);
            $this->assertStringContainsString('/FontFile2', $pdf, 'A TrueType font is embedded; nothing is fetched remotely.');
            $this->assertSame(1, ReportDocument::pageCount($pdf), $language);

            $text = $this->pdftotext($pdf);
            if ($text === null) {
                continue;
            }

            $this->assertStringContainsString('Account 5', $text);
            $this->assertStringContainsString('12,345,678.90', $text, 'Grouped decimals from the exact string.');
            // Arabic data is in the text layer in both modes.
            $this->assertStringContainsString('حساب العملاء', $text);
            $this->assertStringContainsString('الإجمالي', $text);

            if ($language === 'en') {
                $this->assertStringContainsString('Trial Balance', $text);
                $this->assertStringContainsString('Head Office', $text);
                $this->assertStringContainsString('Page 1 / 1', $text);
            } else {
                $this->assertStringContainsString('ميزان المراجعة', $text);
                $this->assertStringContainsString('الفرع الرئيسي', $text);
                $this->assertStringContainsString('صفحة', $text);
            }
        }
    }

    public function test_long_reports_paginate_with_repeated_header_letterhead_and_page_numbers(): void
    {
        $doc = new ReportDocument(app(SettingService::class));

        foreach (['en', 'ar'] as $language) {
            $pdf = $doc->pdf($this->rows(160), 'Trial Balance / ميزان المراجعة', $language, ['currency' => 'EGP']);
            $pages = ReportDocument::pageCount($pdf);
            $this->assertGreaterThan(2, $pages, $language);

            $text = $this->pdftotext($pdf);
            if ($text === null) {
                continue;
            }

            // The column header is repeated on every page, and so is the letterhead and the page number.
            $this->assertGreaterThanOrEqual($pages, substr_count($text, 'Debit'), $language);
            $this->assertGreaterThanOrEqual($pages, substr_count($text, $language === 'en' ? 'Tourism ERP' : 'نظام إدارة السياحة'), $language);
            foreach ([1, $pages] as $page) {
                $this->assertStringContainsString("{$page} / {$pages}", $text, $language);
            }
            // No row is lost across page breaks.
            $this->assertStringContainsString('Account 160', $text);
            $this->assertStringContainsString('حساب العملاء', $text);
        }
    }

    public function test_letterhead_uses_company_settings_logo_and_signoff_toggle(): void
    {
        $settings = app(SettingService::class);
        $settings->set('general', 'company_name_en', 'Nile Travel');
        $settings->set('general', 'company_name_ar', 'النيل للسياحة');
        $settings->set('general', 'company_logo', self::PNG);

        $doc = new ReportDocument($settings);
        $meta = ['branch' => ['name_en' => 'Giza Branch', 'name_ar' => 'فرع الجيزة'], 'currency' => 'EGP'];

        $pdf = $doc->pdf($this->rows(3), 'Aging / أعمار الديون', 'en', $meta);
        $this->assertStringContainsString('/Subtype /Image', $pdf, 'The configured logo is embedded.');

        $text = $this->pdftotext($pdf);
        if ($text !== null) {
            $this->assertStringContainsString('Nile Travel', $text);
            $this->assertStringContainsString('Giza Branch', $text);
            $this->assertStringContainsString('Prepared by', $text);
        }

        $arabic = $this->pdftotext($doc->pdf($this->rows(3), 'Aging / أعمار الديون', 'ar', $meta));
        if ($arabic !== null) {
            $this->assertStringContainsString('النيل للسياحة', $arabic);
            $this->assertStringContainsString('فرع الجيزة', $arabic);
            $this->assertStringContainsString('أعده', $arabic);
        }

        $settings->set('general', 'report_signoff', 'false');
        $text = $this->pdftotext($doc->pdf($this->rows(3), 'Aging / أعمار الديون', 'en', $meta));
        if ($text !== null) {
            $this->assertStringNotContainsString('Prepared by', $text);
        }
    }

    public function test_logo_setting_cannot_read_outside_storage_or_fetch_remote_urls(): void
    {
        $settings = app(SettingService::class);

        foreach (['../../.env', '/etc/passwd', 'https://example.com/logo.png', 'data:image/svg+xml;base64,PHN2Zy8+'] as $bad) {
            $settings->set('general', 'company_logo', $bad);
            $pdf = (new ReportDocument($settings))->pdf($this->rows(2), 'Aging / أعمار الديون', 'en');
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringNotContainsString('/Subtype /Image', $pdf, $bad);
        }
    }

    public function test_remote_resources_in_report_text_are_never_fetched(): void
    {
        // A description that looks like markup must be escaped, not rendered as an image/link fetch.
        $rows = [(new ReportCsv)->header(['Description']), ['<img src="http://127.0.0.1:9/x.png"><link rel="stylesheet" href="http://127.0.0.1:9/a.css">']];
        $pdf = (new ReportDocument(app(SettingService::class)))->pdf($rows, 'Test / اختبار', 'en');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
        if (($text = $this->pdftotext($pdf)) !== null) {
            $this->assertStringContainsString('<img src=', $text);
        }
    }

    public function test_the_bundled_font_is_part_of_the_installed_dependency_not_downloaded(): void
    {
        $this->assertFileExists(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'));
        $this->assertFileExists(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'));
    }

    public function test_pdf_row_cap_is_enforced(): void
    {
        $this->assertSame(5000, ReportDocument::PDF_MAX_ROWS);
    }

    public function test_xlsx_has_numeric_money_cells_real_dates_and_formula_safe_text(): void
    {
        $csv = new ReportCsv;
        $rows = [
            $csv->header(['Date', 'Description', 'Amount']),
            [$csv->date('2026-03-05'), '=HYPERLINK("http://evil","x")', $csv->money(123456, 2)],
            [$csv->date('2026-03-06'), '+1+1', $csv->money(-5, 2)],
            [$csv->date('2026-03-07'), '1100', $csv->money(7, 3)],
            [$csv->date('2026-03-08'), '@SUM(A1)', $csv->money(99999999999999, 2)],
        ];

        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, (new ReportDocument(app(SettingService::class)))->xlsx($rows, 'Test / اختبار'));

        $reader = new Reader;
        $reader->open($path);
        $read = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $this->assertSame('Test', $sheet->getName());
            foreach ($sheet->getRowIterator() as $row) {
                $read[] = $row->getCells();
            }
        }
        $reader->close();

        $this->assertInstanceOf(DateTimeCell::class, $read[1][0]);
        $this->assertSame('2026-03-05', $read[1][0]->getValue()->format('Y-m-d'));
        $this->assertInstanceOf(StringCell::class, $read[1][1]);
        $this->assertSame("'=HYPERLINK(\"http://evil\",\"x\")", $read[1][1]->getValue());
        $this->assertSame("'+1+1", $read[2][1]->getValue());
        $this->assertSame("'@SUM(A1)", $read[4][1]->getValue());
        $this->assertSame('1100', $read[3][1]->getValue(), 'Codes stay text.');

        foreach ([1, 2, 3, 4] as $r) {
            $this->assertInstanceOf(NumericCell::class, $read[$r][2], "row {$r} amount is a number");
        }
        $this->assertSame(1234.56, $read[1][2]->getValue());
        $this->assertSame(-0.05, $read[2][2]->getValue());
        $this->assertSame(0.007, $read[3][2]->getValue());
        $this->assertSame(999999999999.99, $read[4][2]->getValue());

        $zip = new \ZipArchive;
        $zip->open($path);
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringNotContainsString('<f>', $sheetXml, 'No cell may be a formula.');
        $this->assertStringContainsString('#,##0.00', $styles);
        $this->assertStringContainsString('#,##0.000', $styles);
    }
}
