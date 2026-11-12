<?php

namespace Tests\Unit;

use App\Modules\Imports\Support\Sanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportSanitizerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function inbound(): array
    {
        return [
            'equals' => ['=1+1', true],
            'equals after spaces' => ['  =1+1', true],
            'at sign' => ['@SUM(A1)', true],
            'plus formula' => ['+cmd|calc!A0', true],
            'minus formula' => ['-2+3+cmd', true],
            'leading tab' => ["\t=1", true],
            'leading cr' => ["\r=1", true],
            'dde' => ["=cmd|' /C calc'!A0", true],
            'international phone' => ['+20 100 123 4567', false],
            'negative number' => ['-12.50', false],
            'dashed phone' => ['010-0123-4567', false],
            'plain text' => ['Nile Tours', false],
            'arabic text' => ['شركة النيل', false],
            'equals inside' => ['a=b', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('inbound')]
    public function test_detects_formula_cells(string $value, bool $expected): void
    {
        $this->assertSame($expected, Sanitizer::isFormula($value));
    }

    public function test_export_neutralises_risky_cells_and_keeps_numbers(): void
    {
        $this->assertSame("'=1+1", Sanitizer::forExport('=1+1'));
        $this->assertSame("'@SUM(A1)", Sanitizer::forExport('@SUM(A1)'));
        $this->assertSame("'+cmd", Sanitizer::forExport('+cmd'));
        $this->assertSame("'-cmd", Sanitizer::forExport('-cmd'));
        $this->assertSame("'\t=1", Sanitizer::forExport("\t=1"));
        $this->assertSame('-12.5', Sanitizer::forExport('-12.5'));
        $this->assertSame('Nile', Sanitizer::forExport('Nile'));
        $this->assertSame('', Sanitizer::forExport(''));
    }
}
