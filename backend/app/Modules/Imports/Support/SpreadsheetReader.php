<?php

namespace App\Modules\Imports\Support;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Streams rows out of a CSV or XLSX file as lists of strings, enforcing the
 * encoding, row and column limits. Row numbers are the physical spreadsheet
 * row numbers (header = first non-empty row).
 */
class SpreadsheetReader
{
    public function __construct(
        private readonly int $maxRows,
        private readonly int $maxColumns,
        private readonly int $maxCellLength,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('imports.max_rows'),
            (int) config('imports.max_columns'),
            (int) config('imports.max_cell_length'),
        );
    }

    /** Sniff the real format from the content, not the client-supplied name. */
    public static function detectFormat(string $path, string $clientName): string
    {
        $handle = fopen($path, 'rb');
        $head = $handle ? (string) fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }
        $extension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        if (str_starts_with($head, "PK\x03\x04")) {
            if ($extension !== 'xlsx') {
                throw new ImportFileException('unsupported_file_type', 'Only .csv and .xlsx files are accepted.');
            }

            return 'xlsx';
        }

        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw new ImportFileException('unsupported_file_type', 'Only .csv and .xlsx files are accepted.');
        }

        return 'csv';
    }

    /**
     * @return Generator<int, array{row: int, cells: list<string>}> non-empty rows only
     */
    public function rows(string $path, string $format): Generator
    {
        $dataRows = 0;

        foreach ($format === 'xlsx' ? $this->xlsx($path) : $this->csv($path) as $physical => $cells) {
            if ($physical > $this->maxRows + 1000) {
                throw new ImportFileException('too_many_rows', "The file has more than {$this->maxRows} rows.", ['max' => $this->maxRows]);
            }

            if (count($cells) > $this->maxColumns) {
                throw new ImportFileException('too_many_columns', "The file has more than {$this->maxColumns} columns.", ['max' => $this->maxColumns]);
            }

            if (count(array_filter($cells, fn (string $cell) => trim($cell) !== '')) === 0) {
                continue;
            }

            // The first non-empty row is the header; only data rows are capped.
            if ($dataRows++ > $this->maxRows) {
                throw new ImportFileException('too_many_rows', "The file has more than {$this->maxRows} rows.", ['max' => $this->maxRows]);
            }

            yield ['row' => $physical, 'cells' => $cells];
        }
    }

    /** @return Generator<int, list<string>> */
    private function csv(string $path): Generator
    {
        $content = (string) file_get_contents($path);
        $content = $this->toUtf8($content);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        unset($content);

        $delimiter = $this->delimiter($stream);
        $row = 0;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $row++;
            $values = [];
            foreach ($cells as $cell) {
                $value = (string) $cell;
                if (mb_strlen($value) > $this->maxCellLength) {
                    throw new ImportFileException('cell_too_long', "A cell on row {$row} exceeds {$this->maxCellLength} characters.", ['row' => $row, 'max' => $this->maxCellLength]);
                }
                $values[] = $value;
            }
            yield $row => $values;
        }
        fclose($stream);
    }

    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw new ImportFileException('invalid_encoding', 'The file is not valid UTF-8 text. Save it as "CSV UTF-8" or upload an .xlsx file.');
        }

        return $content;
    }

    /** @param  resource  $stream */
    private function delimiter($stream): string
    {
        $first = (string) fgets($stream);
        rewind($stream);
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($first, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @return Generator<int, list<string>> */
    private function xlsx(string $path): Generator
    {
        $options = new XlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new XlsxReader($options);

        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $physical = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $physical++;
                    $values = [];
                    foreach ($row->toArray() as $cell) {
                        $value = $this->cellToString($cell);
                        if (mb_strlen($value) > $this->maxCellLength) {
                            throw new ImportFileException('cell_too_long', "A cell on row {$physical} exceeds {$this->maxCellLength} characters.", ['row' => $physical, 'max' => $this->maxCellLength]);
                        }
                        $values[] = $value;
                    }
                    yield $physical => $values;
                }
                break; // first sheet only
            }
        } catch (ImportFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImportFileException('unreadable_file', 'The spreadsheet could not be read. Re-save it as .xlsx or .csv and try again.');
        } finally {
            try {
                $reader->close();
            } catch (Throwable) {
            }
        }
    }

    private function cellToString(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('H:i:s') === '00:00:00' ? $cell->format('Y-m-d') : $cell->format('Y-m-d H:i:s');
        }
        if (is_bool($cell)) {
            return $cell ? 'true' : 'false';
        }
        if (is_float($cell)) {
            return rtrim(rtrim(number_format($cell, 8, '.', ''), '0'), '.');
        }

        return is_scalar($cell) ? (string) $cell : '';
    }
}
