<?php

namespace Tests\Unit;

use App\Modules\Reports\Services\ReportCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportCsvTest extends TestCase
{
    /** @return array<string, array{int, int, string}> */
    public static function amounts(): array
    {
        return [
            'zero' => [0, 2, '0.00'],
            'cents' => [5, 2, '0.05'],
            'whole' => [100, 2, '1.00'],
            'large' => [9007199254740993, 2, '90071992547409.93'],
            'negative' => [-5, 2, '-0.05'],
            'three decimals' => [1234, 3, '1.234'],
            'small three decimals' => [7, 3, '0.007'],
            'no decimals' => [-42, 0, '-42'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_formatted_from_minor_units_without_floats(int $minor, int $exponent, string $expected): void
    {
        $this->assertSame($expected, (new ReportCsv)->amount($minor, $exponent));
    }

    public function test_formula_prefixes_are_neutralised_but_numbers_are_untouched(): void
    {
        $csv = new ReportCsv;

        foreach (['=1+1', '+SUM(A1)', '-cmd', '@x', "\tcmd"] as $dangerous) {
            $this->assertStringStartsWith("'", $csv->cell($dangerous), $dangerous);
        }

        $this->assertSame('-12.50', $csv->cell('-12.50'));
        $this->assertSame('42', $csv->cell(42));
        $this->assertSame('', $csv->cell(null));
        $this->assertSame('عميل', $csv->cell('عميل'));
    }

    public function test_the_output_starts_with_a_utf8_bom(): void
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", (new ReportCsv)->build([['a', 'b']]));
    }
}
