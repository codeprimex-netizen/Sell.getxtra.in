<?php

declare(strict_types=1);

namespace App\Domain\Review;

/**
 * Persistence contract for buyer wishlists (Req 7.1).
 */
interface WishlistRepositoryInterface
{
    public function add(int $userId, int $productId): void;

    public function remove(int $userId, int $productId): void;

    public function has(int $userId, int $productId): bool;

    /** @return array<int,int> product ids on a user's wishlist */
    public function productIds(int $userId): array;

    /**
     * Wishlist joined to product rows for display.
     *
     * @return array<int, array<string,mixed>>
     */
    public function forUser(int $userId): array;
}
