<?php

namespace App\Support;

use ZipArchive;

/*
|--------------------------------------------------------------------------
| OfficeTable: reads a Word (.docx) or Excel (.xlsx) list into rows of cells
|--------------------------------------------------------------------------
| Both files are a zip folder with XML inside, so PHP can read them with
| ZipArchive alone (no extra package, and the AI service does not need to be
| running). Used by Approved Lists and Enrollment (POST /staff/read-table).
|
|   .xlsx  every sheet, every row; shared text, inline text, numbers, dates
|   .docx  every table row; when the file has no table, one row per
|          paragraph, split on tabs or 2+ spaces
|
| Old .doc / .xls files are a different (binary) format and are not read:
| open them and "Save As" .docx / .xlsx.
*/
class OfficeTable
{
    public const MAX_ROWS = 5000;

    /** @return array{rows: array<int, array<int, string>>, method: string, warnings: array<int, string>} */
    public static function read(string $path, string $extension): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('This file is not a real .'.$extension.' file (it may be an old .doc/.xls that was renamed). '
                .'Open it in Word or Excel and use "Save As" .'.$extension.'.');
        }

        try {
            [$rows, $method] = $extension === 'xlsx' ? self::xlsx($zip) : self::docx($zip);
        } finally {
            $zip->close();
        }

        $rows = array_values(array_filter($rows, fn ($row) => count(array_filter($row, fn ($c) => $c !== '')) > 0));
        $warnings = [];
        if (count($rows) > self::MAX_ROWS) {
            $warnings[] = 'Only the first '.self::MAX_ROWS.' rows were read.';
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }
        if (!$rows) {
            $warnings[] = 'No text was found in this file.';
        }

        return ['rows' => $rows, 'method' => $method, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ Excel

    private static function xlsx(ZipArchive $zip): array
    {
        $shared = [];
        if (($xml = self::xml($zip, 'xl/sharedStrings.xml')) !== null) {
            foreach ($xml->si as $si) {
                $shared[] = self::text($si);
            }
        }

        $dateStyles = self::dateStyles($zip);
        $rows = [];

        foreach (self::sheetPaths($zip) as $sheetPath) {
            $sheet = self::xml($zip, $sheetPath);
            if (!$sheet || !isset($sheet->sheetData)) {
                continue;
            }
            foreach ($sheet->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $column = self::columnIndex((string) $c['r']);
                    $type = (string) $c['t'];
                    $raw = isset($c->v) ? (string) $c->v : '';

                    $value = match ($type) {
                        's' => $shared[(int) $raw] ?? '',
                        'inlineStr' => self::text($c->is),
                        'b' => $raw === '1' ? 'TRUE' : 'FALSE',
                        'str', 'e' => $raw,
                        default => self::number($raw, isset($dateStyles[(int) $c['s']])),
                    };

                    $column = $column ?? count($cells);
                    while (count($cells) < $column) {
                        $cells[] = '';
                    }
                    $cells[$column] = self::clean($value);
                }
                $rows[] = $cells;
            }
        }

        return [$rows, 'excel'];
    }

    /** Sheet files in workbook order (falls back to every sheet file found). */
    private static function sheetPaths(ZipArchive $zip): array
    {
        $paths = [];
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');

        if ($workbook && $rels) {
            $targets = [];
            foreach ($rels->Relationship as $rel) {
                $targets[(string) $rel['Id']] = (string) $rel['Target'];
            }
            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (isset($targets[$id])) {
                    $target = ltrim($targets[$id], '/');
                    $paths[] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        if (!$paths) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $paths[] = $name;
                }
            }
            natsort($paths);
        }

        return array_values($paths);
    }

    /** Style numbers whose number format is a date. */
    private static function dateStyles(ZipArchive $zip): array
    {
        $styles = self::xml($zip, 'xl/styles.xml');
        if (!$styles) {
            return [];
        }

        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $fmt['formatCode']));
            if (preg_match('/[dy]|m{3,}/', $code)) {
                $custom[(int) $fmt['numFmtId']] = true;
            }
        }

        $dates = [];
        $index = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($custom[$id])) {
                $dates[$index] = true;
            }
            $index++;
        }

        return $dates;
    }

    private static function number(string $raw, bool $isDate): string
    {
        if ($raw === '' || !is_numeric($raw)) {
            return $raw;
        }
        if ($isDate) {
            // Excel counts days from 1899-12-30.
            return date('Y-m-d', (int) round(((float) $raw - 25569) * 86400));
        }
        $number = (float) $raw;

        // 202600001.0 -> "202600001" (student numbers typed as numbers)
        return floor($number) === $number && abs($number) < 1e15 ? (string) (int) $number : (string) $number;
    }

    /** "C12" -> 2 */
    private static function columnIndex(string $ref): ?int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $letter) {
            $n = $n * 26 + (ord($letter) - 64);
        }

        return $n - 1;
    }

    // ------------------------------------------------------------------- Word

    private static function docx(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('word/document.xml');
        if ($raw === false) {
            throw new \RuntimeException('This Word file has no text part. Open it in Word and "Save As" .docx again.');
        }

        $dom = new \DOMDocument();
        $dom->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $rows = [];
        foreach ($xpath->query('//w:tbl/w:tr') as $tr) {
            $cells = [];
            foreach ($xpath->query('w:tc', $tr) as $tc) {
                $parts = [];
                foreach ($xpath->query('.//w:p', $tc) as $p) {
                    $parts[] = self::paragraphText($xpath, $p);
                }
                $cells[] = self::clean(implode(' ', $parts));
                // A cell merged across columns keeps the columns lined up (empty cells after it);
                // a cell merged down (vMerge "continue") is left empty.
                $span = (int) ($xpath->query('w:tcPr/w:gridSpan/@w:val', $tc)->item(0)?->nodeValue ?? 1);
                for ($i = 1; $i < $span; $i++) {
                    $cells[] = '';
                }
            }
            $rows[] = $cells;
        }
        if ($rows) {
            return [$rows, 'word-table'];
        }

        foreach ($xpath->query('//w:body//w:p') as $p) {
            $text = self::paragraphText($xpath, $p);
            if (trim($text) !== '') {
                $parts = array_values(array_filter(array_map('trim', preg_split('/\t|\s{2,}|\s\|\s/', $text)), 'strlen'));
                $rows[] = array_map([self::class, 'clean'], $parts ?: [$text]);
            }
        }

        return [$rows, 'word-text'];
    }

    private static function paragraphText(\DOMXPath $xpath, \DOMNode $p): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $p) as $node) {
            $text .= $node->localName === 't' ? $node->textContent : "\t";
        }

        return $text;
    }

    // ---------------------------------------------------------------- helpers

    private static function xml(ZipArchive $zip, string $name): ?\SimpleXMLElement
    {
        $raw = $zip->getFromName($name);
        if ($raw === false) {
            return null;
        }
        $xml = @simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

        return $xml === false ? null : $xml;
    }

    /** All <t> text inside a shared-string or inline-string item (rich text has several). */
    private static function text(?\SimpleXMLElement $node): string
    {
        if (!$node) {
            return '';
        }
        $node->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $parts = $node->xpath('.//x:t') ?: [];
        if (!$parts) {
            return (string) ($node->t ?? '');
        }

        return implode('', array_map('strval', $parts));
    }

    private static function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }
}
