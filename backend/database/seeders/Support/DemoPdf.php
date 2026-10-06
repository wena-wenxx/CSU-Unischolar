<?php

namespace Database\Seeders\Support;

/**
 * Makes small one-page SAMPLE PDFs for the demo data (fictional people only).
 *
 * Every file has a red "SAMPLE - NOT AN OFFICIAL DOCUMENT" banner and a
 * diagonal watermark. The text is real PDF text, so the AI service can read
 * it exactly as it reads an uploaded PDF.
 */
class DemoPdf
{
    /**
     * @param  string   $title  Big heading, e.g. "CERTIFICATE OF REGISTRATION"
     * @param  string[] $lines  Body lines (plain text)
     */
    public static function make(string $title, array $lines, string $issuer = 'CSU UniScholar Demo'): string
    {
        $content = [];

        // Diagonal watermark (very light red)
        $content[] = 'BT 0.95 0.82 0.82 rg /F2 46 Tf 0.819 0.574 -0.574 0.819 95 230 Tm '
            . self::str('SAMPLE - NOT OFFICIAL') . ' Tj ET';

        // Banner
        $content[] = '0.71 0.14 0.09 RG 1 w 50 790 495 22 re S';
        $content[] = 'BT 0.71 0.14 0.09 rg /F2 9 Tf 92 797 Td '
            . self::str('SAMPLE FOR CSU UNISCHOLAR TESTING ONLY - NOT AN OFFICIAL DOCUMENT - FICTIONAL PERSON') . ' Tj ET';

        // Issuer and title
        $content[] = 'BT 0.3 0.3 0.3 rg /F1 10 Tf 50 765 Td ' . self::str($issuer) . ' Tj ET';
        $content[] = 'BT 0 0 0 rg /F2 18 Tf 50 735 Td ' . self::str($title) . ' Tj ET';

        // Body
        $y = 700;
        foreach ($lines as $line) {
            foreach (self::wrap((string) $line, 88) as $part) {
                $content[] = 'BT 0.1 0.1 0.1 rg /F1 11 Tf 50 ' . $y . ' Td ' . self::str($part) . ' Tj ET';
                $y -= 18;
            }
        }

        $stream = implode("\n", $content);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                . '/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /**
     * A page with no readable text at all, like a failed or very faint scan.
     * (Only a light grey box is drawn, so the AI finds almost nothing to read.)
     */
    public static function blank(): string
    {
        $stream = '0.93 0.93 0.93 rg 60 120 475 600 re f';

        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>\nendobj\n"
            . "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream\nendobj\n"
            . "trailer\n<< /Size 5 /Root 1 0 R >>\n%%EOF\n";
    }

    // PDF string literal in Windows-1252 (keeps ñ / Ñ).
    private static function str(string $text): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return '(' . strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }

    private static function wrap(string $line, int $width): array
    {
        return $line === '' ? [''] : explode("\n", wordwrap($line, $width, "\n", true));
    }
}
