<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Domain\Review\WishlistRepositoryInterface;

/**
 * Wishlist operations (Req 7.1). Guests accumulate a wishlist in the session;
 * on login it is merged into the persistent per-user wishlist.
 */
final class WishlistService
{
    public function __construct(private WishlistRepositoryInterface $wishlist)
    {
    }

    /** Toggle membership; returns true if the product is now on the wishlist. */
    public function toggle(int $userId, int $productId): bool
    {
        if ($this->wishlist->has($userId, $productId)) {
            $this->wishlist->remove($userId, $productId);
            return false;
        }
        $this->wishlist->add($userId, $productId);
        return true;
    }

    public function has(int $userId, int $productId): bool
    {
        return $this->wishlist->has($userId, $productId);
    }

    /** @return array<int, array<string,mixed>> */
    public function list(int $userId): array
    {
        return $this->wishlist->forUser($userId);
    }

    /** @return array<int,int> */
    public function productIds(int $userId): array
    {
        return $this->wishlist->productIds($userId);
    }

    /**
     * Merge a guest's session wishlist into the user's persistent wishlist.
     *
     * @param array<int,int> $guestProductIds
     */
    public function mergeGuest(int $userId, array $guestProductIds): void
    {
        foreach ($guestProductIds as $productId) {
            $this->wishlist->add($userId, (int) $productId);
        }
    }
}
