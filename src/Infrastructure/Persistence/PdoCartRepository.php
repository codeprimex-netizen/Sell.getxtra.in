<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\CartRepositoryInterface;

/**
 * PDO-backed cart store. A cart belongs to a user or (for guests) a session
 * key; on login the guest cart is attached to the user. Items snapshot the
 * unit price and enforce one row per product via a unique key.
 */
final class PdoCartRepository extends Repository implements CartRepositoryInterface
{
    protected string $table = 'carts';

    public function findByUser(int $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }

    public function findBySession(string $sessionKey): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE session_key = :s AND user_id IS NULL LIMIT 1"
        );
        $stmt->execute(['s' => $sessionKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function createForUser(int $userId, string $currency): int
    {
        return $this->insert(['user_id' => $userId, 'currency' => $currency]);
    }

    public function createForSession(string $sessionKey, string $currency): int
    {
        return $this->insert(['session_key' => $sessionKey, 'currency' => $currency]);
    }

    public function attachToUser(int $cartId, int $userId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET user_id = :u, session_key = NULL WHERE id = :id"
        );
        $stmt->execute(['u' => $userId, 'id' => $cartId]);
    }

    public function items(int $cartId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT ci.*, p.title, p.slug, p.seller_id, p.currency, p.status, p.scan_status, p.thumbnail_url
             FROM cart_items ci
             INNER JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = :c ORDER BY ci.created_at ASC"
        );
        $stmt->execute(['c' => $cartId]);
        return $stmt->fetchAll();
    }

    public function addItem(int $cartId, int $productId, ?int $licenseTierId, float $unitPrice): void
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT INTO cart_items (cart_id, product_id, license_tier_id, unit_price)
             VALUES (:c, :p, :t, :price)
             ON DUPLICATE KEY UPDATE license_tier_id = VALUES(license_tier_id), unit_price = VALUES(unit_price)"
        );
        $stmt->execute(['c' => $cartId, 'p' => $productId, 't' => $licenseTierId, 'price' => $unitPrice]);
    }

    public function removeItem(int $cartId, int $productId): void
    {
        $stmt = $this->connection->write()->prepare(
            'DELETE FROM cart_items WHERE cart_id = :c AND product_id = :p'
        );
        $stmt->execute(['c' => $cartId, 'p' => $productId]);
    }

    public function clear(int $cartId): void
    {
        $stmt = $this->connection->write()->prepare('DELETE FROM cart_items WHERE cart_id = :c');
        $stmt->execute(['c' => $cartId]);
    }

    // Optional default keeps the signature compatible with the base
    // Repository::count(): int while satisfying the cart interface.
    public function count(int $cartId = 0): int
    {
        $stmt = $this->connection->read()->prepare(
            'SELECT COUNT(*) FROM cart_items WHERE cart_id = :c'
        );
        $stmt->execute(['c' => $cartId]);
        return (int) $stmt->fetchColumn();
    }
}
