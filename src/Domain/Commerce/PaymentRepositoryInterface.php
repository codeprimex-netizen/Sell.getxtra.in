<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface PaymentRepositoryInterface
{
    /** @param array<string,mixed> $data @return int payment id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findByOrder(int $orderId): ?array;

    public function updateStatus(int $paymentId, string $status, ?string $gatewayRef = null): bool;
}
