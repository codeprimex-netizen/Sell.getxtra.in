<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoice;

/**
 * Renders an invoice as a real PDF document (production) via {@see PdfWriter}.
 */
final class PdfInvoiceRenderer implements InvoiceRenderer
{
    public function __construct(private PdfWriter $pdf = new PdfWriter())
    {
    }

    public function render(array $invoice): string
    {
        $cur = (string) $invoice['currency'];
        $lines = [
            'INVOICE  ' . (string) $invoice['number'],
            (string) ($invoice['site'] ?? 'Code.getxtra.in') . '  -  ' . (string) ($invoice['developer'] ?? 'ANSHU E-MITRA AND CSC CENTER'),
            str_repeat('-', 56),
            $this->row('Item', 'Price (' . $cur . ')'),
            str_repeat('-', 56),
        ];

        foreach ((array) $invoice['items'] as $item) {
            $lines[] = $this->row((string) $item['title'], number_format((float) $item['price'], 2));
        }

        $lines[] = str_repeat('-', 56);
        $lines[] = $this->row('Subtotal', number_format((float) $invoice['subtotal'], 2) . ' ' . $cur);
        $lines[] = $this->row('Discount', number_format((float) $invoice['discount'], 2) . ' ' . $cur);
        $lines[] = $this->row('Tax', number_format((float) $invoice['tax'], 2) . ' ' . $cur);
        $lines[] = $this->row('TOTAL', number_format((float) $invoice['total'], 2) . ' ' . $cur);

        return $this->pdf->fromLines($lines);
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    /** Two-column monospaced row: left label, right-aligned value within 56 cols. */
    private function row(string $left, string $right): string
    {
        $left = mb_substr($left, 0, 40);
        $pad = max(1, 56 - mb_strlen($left) - mb_strlen($right));
        return $left . str_repeat(' ', $pad) . $right;
    }
}
