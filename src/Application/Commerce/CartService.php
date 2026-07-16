<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Config\Config;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Commerce\CartRepositoryInterface;

/**
 * Persistent multi-item cart (Req 8.1). A cart is resolved for the logged-in
 * user or a guest session key; the guest cart is merged into the user's cart
 * on login. Only approved, clean, non-duplicate products may be added.
 */
final class CartService
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private ProductRepositoryInterface $products,
    ) {
    }

    /** Resolve (creating if needed) the cart id for a user or guest session. */
    public function resolveCartId(?int $userId, string $sessionKey): int
    {
        $currency = (string) Config::get('commerce.currency', 'INR');

        if ($userId !== null) {
            $cart = $this->carts->findByUser($userId);
            if ($cart !== null) {
                return (int) $cart['id'];
            }
            // Adopt a guest cart if one exists for this session.
            $guest = $this->carts->findBySession($sessionKey);
            if ($guest !== null) {
                $this->carts->attachToUser((int) $guest['id'], $userId);
                return (int) $guest['id'];
            }
            return $this->carts->createForUser($userId, $currency);
        }

        $cart = $this->carts->findBySession($sessionKey);
        return $cart !== null ? (int) $cart['id'] : $this->carts->createForSession($sessionKey, $currency);
    }

    /** @throws CommerceException */
    public function add(int $cartId, int $productId, ?int $licenseTierId = null): void
    {
        $product = $this->products->findById($productId);
        if ($product === null || ($product['status'] ?? '') !== 'approved' || ($product['scan_status'] ?? '') !== 'clean') {
            throw CommerceException::unavailable($product['title'] ?? 'Item');
        }

        $this->carts->addItem($cartId, $productId, $licenseTierId, (float) $product['base_price']);
    }

    public function remove(int $cartId, int $productId): void
    {
        $this->carts->removeItem($cartId, $productId);
    }

    public function clear(int $cartId): void
    {
        $this->carts->clear($cartId);
    }

    /** @return array<int, array<string,mixed>> */
    public function items(int $cartId): array
    {
        return $this->carts->items($cartId);
    }

    public function count(int $cartId): int
    {
        return $this->carts->count($cartId);
    }
}
