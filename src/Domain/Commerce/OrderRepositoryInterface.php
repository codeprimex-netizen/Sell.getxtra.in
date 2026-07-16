<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface OrderRepositoryInterface
{
    /**
     * Persist an order and its items atomically.
     *
     * @param array<string,mixed> $order
     * @param array<int, array<string,mixed>> $items
     * @return int new order id
     */
    public function create(array $order, array $items): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findByNumber(string $orderNumber): ?array;

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $key): ?array;

    /** @return array<int, array<string,mixed>> */
    public function items(int $orderId): array;

    public function updateStatus(int $orderId, string $status): bool;

    /** @return array<int, array<string,mixed>> orders for a buyer (newest first) */
    public function forBuyer(int $buyerId, int $limit = 50, int $offset = 0): array;
}
