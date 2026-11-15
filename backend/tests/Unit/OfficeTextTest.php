<?php

namespace Tests\Unit;

use App\Modules\Assistant\Support\OfficeText;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class OfficeTextTest extends TestCase
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @param  array<string, string>  $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ot');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function sheet(string $rows): string
    {
        return '<?xml version="1.0"?><worksheet xmlns="'.self::MAIN.'"><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $tabs  name, r:id, target, state
     * @param  array<string, string>  $parts  worksheet part name => xml
     */
    private function workbook(array $tabs, array $parts, string $shared = ''): string
    {
        $sheets = '';
        $rels = '';
        foreach ($tabs as [$name, $id, $target, $state]) {
            $sheets .= '<sheet name="'.$name.'" sheetId="1" r:id="'.$id.'"'.($state !== '' ? ' state="'.$state.'"' : '').'/>';
            $rels .= '<Relationship Id="'.$id.'" Target="'.$target.'"/>';
        }

        return $this->zip([
            '[Content_Types].xml' => '<Types/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="'.self::MAIN.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="'.self::MAIN.'">'.$shared.'</sst>',
        ] + $parts);
    }

    private function text(string $bytes): string
    {
        $this->assertSame(OfficeText::XLSX, OfficeText::kind($bytes));

        return OfficeText::text($bytes, OfficeText::XLSX);
    }

    public function test_empty_cells_keep_their_column(): void
    {
        $shared = '<si><t>Customer</t></si><si><t>Debit</t></si><si><t>Credit</t></si><si><t>Nile Travel</t></si>';
        $bytes = $this->workbook([['Sheet1', 'rId1', 'worksheets/sheet1.xml', '']], [
            'xl/worksheets/sheet1.xml' => $this->sheet(
                '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
                .'<row r="2"><c r="A2" t="s"><v>3</v></c><c r="C2"><v>5000</v></c></row>',
            ),
        ], $shared);

        $lines = explode("\n", $this->text($bytes));

        $this->assertSame("Customer\tDebit\tCredit", $lines[1]);
        $this->assertSame("Nile Travel\t\t5000", $lines[2]);
    }

    public function test_sheets_are_found_through_the_workbook_not_the_file_names(): void
    {
        $bytes = $this->workbook([['Summary', 'rId7', 'worksheets/sheet4.xml', '']], [
            'xl/worksheets/sheet4.xml' => $this->sheet('<row r="1"><c r="A1" t="inlineStr"><is><t>Only sheet</t></is></c></row>'),
        ]);

        $text = $this->text($bytes);

        $this->assertStringContainsString('[Sheet: Summary]', $text);
        $this->assertStringContainsString('Only sheet', $text);
    }

    public function test_tab_order_comes_from_the_workbook_and_the_limit_applies_to_that_list(): void
    {
        $cell = fn (string $v) => $this->sheet('<row r="1"><c r="A1" t="inlineStr"><is><t>'.$v.'</t></is></c></row>');
        $bytes = $this->workbook([
            ['Second tab', 'rId2', 'worksheets/sheet1.xml', ''],
            ['First tab', 'rId1', 'worksheets/sheet9.xml', ''],
            ['Third', 'rId3', 'worksheets/sheet2.xml', ''],
            ['Fourth', 'rId4', 'worksheets/sheet3.xml', ''],
        ], [
            'xl/worksheets/sheet1.xml' => $cell('from-sheet1'), 'xl/worksheets/sheet9.xml' => $cell('from-sheet9'),
            'xl/worksheets/sheet2.xml' => $cell('from-sheet2'), 'xl/worksheets/sheet3.xml' => $cell('from-sheet3'),
        ]);

        $text = $this->text($bytes);

        $this->assertLessThan(strpos($text, 'from-sheet9'), strpos($text, 'from-sheet1'), 'tab order follows the workbook list');
        $this->assertStringContainsString('from-sheet2', $text);
        $this->assertStringNotContainsString('from-sheet3', $text, 'only three sheets are read');
    }

    public function test_hidden_sheets_are_left_out(): void
    {
        $bytes = $this->workbook([['Visible', 'rId1', 'worksheets/sheet1.xml', ''], ['Secret', 'rId2', 'worksheets/sheet2.xml', 'hidden']], [
            'xl/worksheets/sheet1.xml' => $this->sheet('<row r="1"><c r="A1" t="inlineStr"><is><t>shown</t></is></c></row>'),
            'xl/worksheets/sheet2.xml' => $this->sheet('<row r="1"><c r="A1" t="inlineStr"><is><t>hidden-data</t></is></c></row>'),
        ]);

        $text = $this->text($bytes);

        $this->assertStringContainsString('shown', $text);
        $this->assertStringNotContainsString('hidden-data', $text);
    }
}
