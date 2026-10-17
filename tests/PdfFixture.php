<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Builds small, valid, uncompressed single-font PDFs in memory, so the PDF
 * tests do not need a binary fixture checked into the repository.
 */
final class PdfFixture
{
    /**
     * Object numbering: 1 = catalog, 2 = pages tree, 3 = font, then a
     * page/content-stream pair per page starting at object 4.
     */
    public static function create(string ...$pages): string
    {
        $pageCount = \count($pages);
        $pageIds = [];

        foreach (array_keys($pages) as $index) {
            $pageIds[] = (4 + $index * 2).' 0 R';
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $pageIds), $pageCount),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        foreach (array_values($pages) as $index => $text) {
            $stream = sprintf('BT /F1 24 Tf 72 700 Td (%s) Tj ET', $text);

            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents %d 0 R /Resources << /Font << /F1 3 0 R >> >> >>',
                5 + $index * 2,
            );
            $objects[] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($stream), $stream);
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[$index + 1] = \strlen($pdf);
            $pdf .= ($index + 1).' 0 obj'."\n".$body."\nendobj\n";
        }

        $size = \count($objects) + 1;
        $xref = \strlen($pdf);

        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
