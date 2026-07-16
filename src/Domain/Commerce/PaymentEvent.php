<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Normalized payment webhook event, parsed from a gateway-specific payload
 * (Req 9.3). Carries the id used for idempotent de-duplication.
 */
final class PaymentEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $orderNumber,
        public readonly string $gatewayRef,
        public readonly PaymentStatus $status,
    ) {
    }
}
