<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Definitions\ImportDefinition;
use App\Modules\Imports\Support\Sanitizer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Builds the downloadable, versioned templates. The header row uses the
 * Arabic or English labels; an XLSX template also carries a "Guide" sheet
 * that lists type, required flag, description and an example per column.
 */
class ImportTemplateService
{
    public function filename(ImportDefinition $definition, string $locale, string $format): string
    {
        return "{$definition->entity()}-template-v{$definition->version()}-{$locale}.{$format}";
    }

    /** @return array{path: string, mime: string} a temp file the caller must delete after sending */
    public function build(ImportDefinition $definition, string $locale, string $format): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl');

        if ($format === 'xlsx') {
            $this->xlsx($definition, $locale, $path);

            return ['path' => $path, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        $handle = fopen($path, 'wb');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM so Excel opens Arabic headers correctly
        fputcsv($handle, $this->headers($definition, $locale), ',', '"', '');
        fclose($handle);

        return ['path' => $path, 'mime' => 'text/csv; charset=UTF-8'];
    }

    /** @return list<string> */
    private function headers(ImportDefinition $definition, string $locale): array
    {
        return array_map(fn ($column) => Sanitizer::forExport($column->label($locale)), $definition->columns());
    }

    private function xlsx(ImportDefinition $definition, string $locale, string $path): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName($locale === 'ar' ? 'القالب' : 'Template');
        $writer->addRow(Row::fromValues($this->headers($definition, $locale)));

        $guide = $writer->addNewSheetAndMakeItCurrent();
        $guide->setName($locale === 'ar' ? 'الدليل' : 'Guide');
        $ar = $locale === 'ar';
        $writer->addRow(Row::fromValues($ar
            ? ['العمود', 'مطلوب', 'النوع', 'الوصف', 'مثال']
            : ['Column', 'Required', 'Type', 'Description', 'Example']));
        foreach ($definition->columns() as $column) {
            $writer->addRow(Row::fromValues([
                $column->label($locale),
                $column->required ? ($ar ? 'نعم' : 'Yes') : ($ar ? 'لا' : 'No'),
                $column->type,
                Sanitizer::forExport($ar ? $column->descAr : $column->descEn),
                Sanitizer::forExport($column->example),
            ]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([$ar ? 'إصدار القالب' : 'Template version', (string) $definition->version()]));
        foreach ($definition->options() as $key => $option) {
            $writer->addRow(Row::fromValues([
                $ar ? 'خيار الرفع' : 'Upload option', $key, $ar ? $option['ar'] : $option['en'],
                $option['required'] ? ($ar ? 'مطلوب' : 'required') : ($ar ? 'اختياري' : 'optional'),
            ]));
        }

        $writer->close();
    }
}
