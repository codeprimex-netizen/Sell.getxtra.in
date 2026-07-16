<?php

declare(strict_types=1);

namespace App\Domain\Seller;

interface PayoutRepositoryInterface
{
    /** @param array<string,mixed> $data @return int payout id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /**
     * A user's payouts (newest first). Filter by source ('seller'|'affiliate')
     * or null for all.
     *
     * @return array<int, array<string,mixed>>
     */
    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0, ?string $source = null): array;

    /**
     * Payouts in a status (finance queue), optionally filtered by source.
     *
     * @return array<int, array<string,mixed>>
     */
    public function byStatus(string $status, int $limit = 50, int $offset = 0, ?string $source = null): array;

    public function updateStatus(int $id, string $status, ?string $gatewayRef = null, ?string $note = null): bool;

    /**
     * Sum of amounts still reserved (requested + processing) for a user,
     * optionally scoped to a payout source so seller and affiliate balances
     * reserve independently.
     */
    public function reservedAmount(int $sellerId, ?string $source = null): float;
}
