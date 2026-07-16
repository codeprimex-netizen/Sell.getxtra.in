<?php

declare(strict_types=1);

namespace App\Application\Invoice;

use App\Domain\Commerce\OrderRepositoryInterface;
use App\Infrastructure\Invoice\HtmlInvoiceRenderer;
use App\Infrastructure\Invoice\InvoiceRenderer;
use App\Infrastructure\Storage\StorageManager;

/**
 * Generates an invoice document for a paid order (Req 8.4) and stores it on
 * the private disk. The document format is pluggable via {@see InvoiceRenderer}
 * (HTML in dev, PDF in production); it defaults to HTML when none is injected.
 */
final class InvoiceService
{
    private InvoiceRenderer $renderer;

    public function __construct(
        private OrderRepositoryInterface $orders,
        private StorageManager $storage,
        ?InvoiceRenderer $renderer = null,
    ) {
        $this->renderer = $renderer ?? new HtmlInvoiceRenderer();
    }

    /** @return string the stored invoice key, or '' if the order is missing */
    public function generate(int $orderId): string
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            return '';
        }

        $document = $this->renderer->render($this->invoiceData($order, $this->orders->items($orderId)));

        $key = 'invoices/' . (string) $order['order_number'] . '.' . $this->renderer->extension();
        $this->storage->private()->put($key, $document);
        $this->orders->setInvoiceKey($orderId, $key);

        return $key;
    }

    /**
     * Normalize an order + items into the renderer-agnostic invoice shape.
     *
     * @param array<string,mixed> $order
     * @param array<int, array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function invoiceData(array $order, array $items): array
    {
        return [
            'number'    => (string) $order['order_number'],
            'currency'  => (string) $order['currency'],
            'site'      => 'Sell.getxtra.in',
            'developer' => 'ANSHU E-MITRA AND CSC CENTER',
            'items'     => array_map(static fn (array $i): array => [
                'title' => (string) $i['title_snapshot'],
                'price' => (float) $i['unit_price'],
            ], $items),
            'subtotal'  => (float) $order['subtotal'],
            'discount'  => (float) ($order['discount'] ?? 0),
            'tax'       => (float) ($order['tax'] ?? 0),
            'total'     => (float) $order['total'],
        ];
    }
}
