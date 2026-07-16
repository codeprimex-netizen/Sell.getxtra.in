<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoice;

/**
 * Renders a normalized invoice into a storable document (Req 8.4). Swappable
 * behind {@see \App\Application\Invoice\InvoiceService}: HTML for dev, a real
 * PDF for production.
 *
 * The invoice array shape:
 *   number, currency, site, developer,
 *   items: [ ['title' => string, 'price' => float], ... ],
 *   subtotal, discount, tax, total (all float)
 */
interface InvoiceRenderer
{
    /** @param array<string,mixed> $invoice @return string document bytes */
    public function render(array $invoice): string;

    /** File extension without the dot, e.g. "pdf" or "html". */
    public function extension(): string;

    public function mimeType(): string;
}
