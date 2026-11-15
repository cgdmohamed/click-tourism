<?php

namespace App\Modules\Assistant\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Word (.docx) and Excel (.xlsx) files attached to an assistant message (ADR-0033). The provider cannot read
 * them, so the server turns them into plain text. Nothing is executed: the file is only opened as a zip, the
 * macro-enabled variants and anything with a DOCTYPE or entity declaration are refused, the sizes are capped
 * (zip bombs) and the text is cut short. The result is untrusted data like any attachment.
 */
final class OfficeText
{
    public const DOCX = 'docx';

    public const XLSX = 'xlsx';

    public const MIME = [
        self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const MAX_ENTRIES = 2000;

    private const MAX_UNCOMPRESSED = 40 * 1024 * 1024;

    private const MAX_SHEETS = 3;

    private const MAX_ROWS = 200;

    private const MAX_COLUMNS = 60;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @return 'docx'|'xlsx'|null null when the bytes are not a plain (macro-free) Word or Excel file */
    public static function kind(string $bytes): ?string
    {
        return self::open($bytes, function (ZipArchive $zip): ?string {
            $names = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return null;
                }
                $names[] = $stat['name'];
                $total += (int) $stat['size'];
            }
            if (count($names) > self::MAX_ENTRIES || $total > self::MAX_UNCOMPRESSED || ! in_array('[Content_Types].xml', $names, true)) {
                return null;
            }
            foreach ($names as $name) {
                if (stripos($name, 'vbaProject') !== false || str_contains($name, '..')) {
                    return null;
                }
            }
            if (in_array('word/document.xml', $names, true)) {
                return self::DOCX;
            }

            return in_array('xl/workbook.xml', $names, true) ? self::XLSX : null;
        });
    }

    /** The readable text of a Word or Excel file, at most $max characters. */
    public static function text(string $bytes, string $kind, int $max = 20000): string
    {
        $text = self::open($bytes, fn (ZipArchive $zip) => $kind === self::DOCX ? self::word($zip, $max) : self::excel($zip, $max)) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    /**
     * @template T
     *
     * @param  callable(ZipArchive): T  $use
     * @return T|null
     */
    private static function open(string $bytes, callable $use): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'office');
        if ($path === false) {
            return null;
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
            try {
                return $use($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private static function xml(ZipArchive $zip, string $name): ?DOMDocument
    {
        $raw = $zip->getFromName($name);
        if ($raw === false || str_contains($raw, '<!DOCTYPE') || str_contains($raw, '<!ENTITY')) {
            return null;
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $doc : null;
    }

    private static function word(ZipArchive $zip, int $max): string
    {
        $doc = self::xml($zip, 'word/document.xml');
        if ($doc === null) {
            return '';
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $lines = [];
        $length = 0;
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $line = '';
            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
                $line .= $node->localName === 't' ? $node->textContent : ($node->localName === 'tab' ? "\t" : ' ');
            }
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
                $length += mb_strlen($line);
            }
            if ($length > $max) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    private static function excel(ZipArchive $zip, int $max): string
    {
        $shared = [];
        $strings = self::xml($zip, 'xl/sharedStrings.xml');
        if ($strings !== null) {
            $xpath = new DOMXPath($strings);
            $xpath->registerNamespace('m', self::NS_MAIN);
            foreach ($xpath->query('//m:si') ?: [] as $item) {
                $shared[] = $item->textContent;
            }
        }

        $out = [];
        $length = 0;
        foreach (array_slice(self::sheets($zip), 0, self::MAX_SHEETS) as [$name, $path]) {
            if ($length > $max) {
                break;
            }
            $doc = self::xml($zip, $path);
            if ($doc === null) {
                continue;
            }
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('m', self::NS_MAIN);
            $out[] = '[Sheet: '.$name.']';
            $rows = 0;
            foreach ($xpath->query('//m:row') ?: [] as $row) {
                if (++$rows > self::MAX_ROWS || $length > $max) {
                    break;
                }
                $cells = [];
                $next = 0;
                foreach ($xpath->query('m:c', $row) ?: [] as $cell) {
                    /** @var DOMElement $cell */
                    // A row lists only the cells that have a value: the reference (C2) says which column this is.
                    $column = self::column($cell->getAttribute('r'), $next);
                    $next = $column + 1;
                    if ($column >= self::MAX_COLUMNS) {
                        continue;
                    }
                    $type = $cell->getAttribute('t');
                    $value = (string) ($xpath->query('m:v', $cell)->item(0)?->textContent ?? '');
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string) ($xpath->query('m:is', $cell)->item(0)?->textContent ?? '');
                    }
                    $cells[$column] = trim($value);
                }
                if ($cells === []) {
                    continue;
                }
                $line = [];
                for ($i = 0; $i <= max(array_keys($cells)); $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $text = rtrim(implode("\t", $line), "\t");
                if ($text !== '') {
                    $out[] = $text;
                    $length += mb_strlen($text);
                }
            }
        }

        return implode("\n", $out);
    }

    /** Zero-based column of a cell reference such as "C2" (a missing reference continues after the previous cell). */
    private static function column(string $reference, int $fallback): int
    {
        if (preg_match('/^([A-Za-z]+)/', $reference, $m) !== 1) {
            return $fallback;
        }
        $index = 0;
        foreach (str_split(strtoupper($m[1])) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * The visible sheets in tab order, as [name, part path]: the order and the targets come from the workbook
     * and its relationships, never from the file names (sheet4.xml may be the first tab).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function sheets(ZipArchive $zip): array
    {
        $targets = [];
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($rels !== null) {
            foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }

        $sheets = [];
        $book = self::xml($zip, 'xl/workbook.xml');
        if ($book !== null) {
            foreach ($book->getElementsByTagName('sheet') as $sheet) {
                $target = $targets[$sheet->getAttributeNS(self::NS_REL, 'id')] ?? '';
                if ($target === '' || str_contains($target, '..') || in_array($sheet->getAttribute('state'), ['hidden', 'veryHidden'], true)) {
                    continue;
                }
                $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                if ($zip->locateName($path) !== false) {
                    $sheets[] = [$sheet->getAttribute('name') ?: basename($path, '.xml'), $path];
                }
            }
        }
        if ($sheets !== []) {
            return $sheets;
        }

        // A workbook that lists nothing usable: fall back to the worksheet parts in natural name order.
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $name) === 1) {
                $parts[] = $name;
            }
        }
        natsort($parts);

        return array_map(fn (string $path) => [basename($path, '.xml'), $path], array_values($parts));
    }
}
