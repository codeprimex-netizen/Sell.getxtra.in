<?php

declare(strict_types=1);

namespace App\Application\Invoice;

use App\Domain\Commerce\OrderRepositoryInterface;
use App\Infrastructure\Storage\StorageManager;

/**
 * Generates an invoice document for a paid order (Req 8.4) and stores it on
 * the private disk. This dev implementation renders HTML; a DomPDF/TCPDF
 * renderer swaps in for production behind the same call.
 */
final class InvoiceService
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private StorageManager $storage,
    ) {
    }

    /** @return string the stored invoice key, or '' if the order is missing */
    public function generate(int $orderId): string
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            return '';
        }

        $items = $this->orders->items($orderId);
        $html = $this->render($order, $items);

        $key = 'invoices/' . (string) $order['order_number'] . '.html';
        $this->storage->private()->put($key, $html);
        $this->orders->setInvoiceKey($orderId, $key);

        return $key;
    }

    /**
     * @param array<string,mixed> $order
     * @param array<int, array<string,mixed>> $items
     */
    private function render(array $order, array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td>' . htmlspecialchars((string) $item['title_snapshot'])
                . '</td><td style="text-align:right">'
                . number_format((float) $item['unit_price'], 2) . '</td></tr>';
        }

        $num = htmlspecialchars((string) $order['order_number']);
        $cur = htmlspecialchars((string) $order['currency']);

        return '<!doctype html><html><head><meta charset="utf-8"><title>Invoice ' . $num . '</title></head>'
            . '<body style="font-family:sans-serif"><h1>Invoice ' . $num . '</h1>'
            . '<p>Sell.getxtra.in &mdash; ANSHU E-MITRA AND CSC CENTER</p>'
            . '<table border="1" cellpadding="6" cellspacing="0"><thead><tr><th>Item</th><th>Price</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p>Subtotal: ' . number_format((float) $order['subtotal'], 2) . ' ' . $cur . '<br>'
            . 'Discount: ' . number_format((float) $order['discount'], 2) . ' ' . $cur . '<br>'
            . 'Tax: ' . number_format((float) $order['tax'], 2) . ' ' . $cur . '<br>'
            . '<strong>Total: ' . number_format((float) $order['total'], 2) . ' ' . $cur . '</strong></p>'
            . '</body></html>';
    }
}
