<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface RefundRepositoryInterface
{
    /** @param array<string,mixed> $data @return int refund id */
    public function create(array $data): int;

    /** Total amount already refunded against an order (processed refunds). */
    public function totalRefundedForOrder(int $orderId): float;

    public function markProcessed(int $refundId, ?string $gatewayRef): bool;

    /** @return array<int, array<string,mixed>> */
    public function forOrder(int $orderId): array;
}
