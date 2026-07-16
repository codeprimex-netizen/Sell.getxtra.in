<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Application\Invoice\InvoiceService;
use App\Infrastructure\Queue\JobHandler;

/**
 * Generates + stores an order invoice off the request path (Req 8.4).
 * Payload: order_id.
 */
final class GenerateInvoiceHandler implements JobHandler
{
    public function __construct(private InvoiceService $invoices)
    {
    }

    public function handle(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->invoices->generate($orderId);
        }
    }
}
