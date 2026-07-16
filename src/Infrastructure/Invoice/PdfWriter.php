<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoice;

/**
 * Minimal, dependency-free PDF generator (Req 8.4). Produces a valid single-
 * page PDF laying out monospaced text lines — enough for invoices without
 * pulling in DomPDF/TCPDF. The content stream is left uncompressed for
 * portability. For rich, styled documents in production, swap this for a
 * DomPDF/TCPDF-backed renderer behind the same {@see InvoiceRenderer}.
 */
final class PdfWriter
{
    private const PAGE_WIDTH = 595;   // A4 @ 72dpi (points)
    private const PAGE_HEIGHT = 842;
    private const MARGIN_X = 50;
    private const TOP_Y = 800;
    private const FONT_SIZE = 11;
    private const LEADING = 15;
    private const LINES_PER_PAGE = 50;

    /**
     * Build a PDF from plain text lines (one page, paginated if long).
     *
     * @param array<int, string> $lines
     */
    public function fromLines(array $lines): string
    {
        $pages = array_chunk($lines === [] ? [''] : $lines, self::LINES_PER_PAGE);

        // Object plan: 1=Catalog, 2=Pages, 3=Font, then per page: Page + Content.
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        // Pages kids filled after we know page object ids.
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

        $nextId = 4;
        $kids = [];
        foreach ($pages as $pageLines) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $kids[] = $pageId . ' 0 R';

            $stream = "BT\n/F1 " . self::FONT_SIZE . " Tf\n"
                . self::MARGIN_X . ' ' . self::TOP_Y . " Td\n"
                . self::LEADING . " TL\n";
            foreach ($pageLines as $line) {
                $stream .= '(' . $this->escape((string) $line) . ") Tj T*\n";
            }
            $stream .= 'ET';

            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R '
                . '/MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] '
                . '/Resources << /Font << /F1 3 0 R >> >> '
                . '/Contents ' . $contentId . ' 0 R >>';
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        ksort($objects);

        // Assemble with byte-offset tracking for the xref table.
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $size = count($objects) + 1; // + free object 0
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    /** Escape for a PDF literal string and keep bytes within WinAnsi range. */
    private function escape(string $text): string
    {
        // Replace non-ASCII (e.g. ₹) so the standard Courier font renders cleanly.
        $text = (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
