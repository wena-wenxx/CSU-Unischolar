<?php

namespace App\Support;

use ZipArchive;

/*
| Writes small SAMPLE list files (Excel .xlsx, Word .docx, PDF, CSV) for
| testing External Grantees, without extra PHP libraries:
| .xlsx and .docx are ZIP files of XML, made with ZipArchive (PHP "zip"
| extension); the PDF is written by hand with real, selectable text.
| Used only by: php artisan demo:external-lists
*/
class DemoListFiles
{
    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** CSV (opens in Excel). */
    public static function csv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Ñ correctly
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    /** Excel workbook with one sheet: title lines, then the header and rows. */
    public static function xlsx(string $path, array $titles, array $header, array $rows, string $sheet = 'Grantees'): void
    {
        $lines = array_merge(array_map(fn ($t) => [$t], $titles), [[]], [$header], $rows);
        $xmlRows = '';
        foreach ($lines as $r => $cells) {
            $xmlRows .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_int($value)) {
                    $xmlRows .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } elseif ($value !== null && $value !== '') {
                    $xmlRows .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.self::esc((string) $value).'</t></is></c>';
                }
            }
            $xmlRows .= '</row>';
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::esc(mb_substr($sheet, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols><col min="1" max="1" width="6" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/>'
            .'<col min="3" max="6" width="22" customWidth="1"/></cols>'
            .'<sheetData>'.$xmlRows.'</sheetData></worksheet>');
        $zip->close();
    }

    /** Word document: title paragraphs, a table, and a closing note. */
    public static function docx(string $path, array $titles, array $header, array $rows, string $footer = ''): void
    {
        $p = fn (string $text, bool $bold = false) => '<w:p><w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '')
            .'<w:t xml:space="preserve">'.self::esc($text).'</w:t></w:r></w:p>';
        $cell = fn (string $text, bool $bold = false) => '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>'.$p($text, $bold).'</w:tc>';

        $body = '';
        foreach ($titles as $i => $title) {
            $body .= $p($title, $i === 0);
        }
        $border = '<w:tblBorders>'.implode('', array_map(fn ($side) => "<w:{$side} w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"999999\"/>",
            ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])).'</w:tblBorders>';
        $body .= '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>'.$border.'</w:tblPr>';
        $body .= '<w:tr>'.implode('', array_map(fn ($h) => $cell((string) $h, true), $header)).'</w:tr>';
        foreach ($rows as $row) {
            $body .= '<w:tr>'.implode('', array_map(fn ($v) => $cell((string) $v), $row)).'</w:tr>';
        }
        $body .= '</w:tbl>';
        if ($footer !== '') {
            $body .= $p('').$p($footer);
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>'
            .'</w:body></w:document>');
        $zip->close();
    }

    /**
     * One-page PDF with a real text table (selectable text, like a list
     * exported from Excel or Word). $columns = [x position, ...] in points.
     */
    public static function pdf(string $path, array $titles, array $header, array $rows, array $columns, string $footer = ''): void
    {
        $str = function (string $text) {
            $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text;

            return '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).')';
        };
        $text = fn (string $font, float $size, float $x, float $y, string $value) => "BT 0 0 0 rg /{$font} {$size} Tf {$x} {$y} Td ".$str($value).' Tj ET';

        $content = [];
        $y = 790;
        foreach ($titles as $i => $title) {
            $content[] = $text($i === 0 ? 'F2' : 'F1', $i === 0 ? 14 : 11, 50, $y, $title);
            $y -= $i === 0 ? 22 : 16;
        }
        $y -= 14;
        foreach ($header as $c => $value) {
            $content[] = $text('F2', 10, $columns[$c], $y, (string) $value);
        }
        $content[] = '0.6 0.6 0.6 RG 0.5 w 48 '.($y - 6).' m 547 '.($y - 6).' l S';
        $y -= 22;
        foreach ($rows as $row) {
            foreach (array_values($row) as $c => $value) {
                if ($value !== '' && $value !== null) {
                    $content[] = $text('F1', 10, $columns[$c], $y, (string) $value);
                }
            }
            $y -= 20;
        }
        if ($footer !== '') {
            $content[] = $text('F1', 8, 50, 40, $footer);
        }

        $stream = implode("\n", $content);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        file_put_contents($path, $pdf);
    }
}
