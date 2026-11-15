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
            $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($xpath->query('//m:si') ?: [] as $item) {
                $shared[] = $item->textContent;
            }
        }

        $out = [];
        $length = 0;
        for ($sheet = 1; $sheet <= self::MAX_SHEETS && $length <= $max; $sheet++) {
            $doc = self::xml($zip, "xl/worksheets/sheet{$sheet}.xml");
            if ($doc === null) {
                continue;
            }
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $out[] = "[Sheet {$sheet}]";
            $rows = 0;
            foreach ($xpath->query('//m:row') ?: [] as $row) {
                if (++$rows > self::MAX_ROWS || $length > $max) {
                    break;
                }
                $cells = [];
                foreach ($xpath->query('m:c', $row) ?: [] as $cell) {
                    /** @var DOMElement $cell */
                    $type = $cell->getAttribute('t');
                    $value = (string) ($xpath->query('m:v', $cell)->item(0)?->textContent ?? '');
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string) ($xpath->query('m:is', $cell)->item(0)?->textContent ?? '');
                    }
                    $cells[] = trim($value);
                }
                $line = rtrim(implode("\t", $cells), "\t");
                if ($line !== '') {
                    $out[] = $line;
                    $length += mb_strlen($line);
                }
            }
        }

        return implode("\n", $out);
    }
}
