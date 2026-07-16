<?php

declare(strict_types=1);

namespace App\Domain\Review;

/**
 * Persistence contract for product reviews (Req 7). One review per
 * (product, user) is enforced by the store.
 */
interface ReviewRepositoryInterface
{
    /** @param array<string,mixed> $data @return int new review id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findByUserAndProduct(int $userId, int $productId): ?array;

    /**
     * Published reviews for a product (newest first).
     *
     * @return array<int, array<string,mixed>>
     */
    public function publishedForProduct(int $productId, int $limit = 50, int $offset = 0): array;

    /** @return array<int, array<string,mixed>> reviews awaiting moderation */
    public function pending(int $limit = 50): array;

    public function setStatus(int $id, string $status): bool;

    public function setSellerReply(int $id, string $reply): bool;

    public function delete(int $id): bool;

    /**
     * Aggregate rating over published reviews.
     *
     * @return array{avg: float, count: int}
     */
    public function aggregate(int $productId): array;
}
