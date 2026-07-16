<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface CartRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findByUser(int $userId): ?array;

    /** @return array<string,mixed>|null */
    public function findBySession(string $sessionKey): ?array;

    public function createForUser(int $userId, string $currency): int;

    public function createForSession(string $sessionKey, string $currency): int;

    public function attachToUser(int $cartId, int $userId): void;

    /** @return array<int, array<string,mixed>> items joined to product rows */
    public function items(int $cartId): array;

    public function addItem(int $cartId, int $productId, ?int $licenseTierId, float $unitPrice): void;

    public function removeItem(int $cartId, int $productId): void;

    public function clear(int $cartId): void;

    public function count(int $cartId): int;
}
