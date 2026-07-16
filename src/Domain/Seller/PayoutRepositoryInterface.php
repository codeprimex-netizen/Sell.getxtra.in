<?php

declare(strict_types=1);

namespace App\Domain\Seller;

interface PayoutRepositoryInterface
{
    /** @param array<string,mixed> $data @return int payout id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<int, array<string,mixed>> a seller's payouts (newest first) */
    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0): array;

    /** @return array<int, array<string,mixed>> payouts in a status (finance queue) */
    public function byStatus(string $status, int $limit = 50, int $offset = 0): array;

    public function updateStatus(int $id, string $status, ?string $gatewayRef = null, ?string $note = null): bool;

    /** Sum of amounts still reserved (requested + processing) for a seller. */
    public function reservedAmount(int $sellerId): float;
}
