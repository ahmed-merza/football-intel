<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Builds a minimal single-page, text-layer PDF on the fly so feature tests
 * don't need to ship binary fixtures. The byte layout is the absolute
 * smallest thing smalot/pdfparser will happily parse.
 */
class MakePdf
{
    public static function withText(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $content = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET";
        $contentLength = strlen($content);

        $objects = [
            '',  // index 0 unused
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length {$contentLength} >>\nstream\n{$content}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $obj) {
            if ($index === 0) {
                continue;
            }
            $offsets[$index] = strlen($pdf);
            $pdf .= "{$index} 0 obj\n{$obj}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".count($objects)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= 'trailer << /Size '.count($objects).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}
