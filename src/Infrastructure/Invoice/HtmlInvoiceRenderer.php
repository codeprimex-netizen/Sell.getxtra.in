<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoice;

/**
 * Renders an invoice as a self-contained HTML document (dev/default).
 */
final class HtmlInvoiceRenderer implements InvoiceRenderer
{
    public function render(array $invoice): string
    {
        $num = htmlspecialchars((string) $invoice['number']);
        $cur = htmlspecialchars((string) $invoice['currency']);
        $dev = htmlspecialchars((string) ($invoice['developer'] ?? 'ANSHU E-MITRA AND CSC CENTER'));
        $site = htmlspecialchars((string) ($invoice['site'] ?? 'Code.getxtra.in'));

        $rows = '';
        foreach ((array) $invoice['items'] as $item) {
            $rows .= '<tr><td>' . htmlspecialchars((string) $item['title'])
                . '</td><td style="text-align:right">'
                . number_format((float) $item['price'], 2) . '</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Invoice ' . $num . '</title></head>'
            . '<body style="font-family:sans-serif"><h1>Invoice ' . $num . '</h1>'
            . '<p>' . $site . ' &mdash; ' . $dev . '</p>'
            . '<table border="1" cellpadding="6" cellspacing="0"><thead><tr><th>Item</th><th>Price</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p>Subtotal: ' . number_format((float) $invoice['subtotal'], 2) . ' ' . $cur . '<br>'
            . 'Discount: ' . number_format((float) $invoice['discount'], 2) . ' ' . $cur . '<br>'
            . 'Tax: ' . number_format((float) $invoice['tax'], 2) . ' ' . $cur . '<br>'
            . '<strong>Total: ' . number_format((float) $invoice['total'], 2) . ' ' . $cur . '</strong></p>'
            . '</body></html>';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function mimeType(): string
    {
        return 'text/html; charset=utf-8';
    }
}
