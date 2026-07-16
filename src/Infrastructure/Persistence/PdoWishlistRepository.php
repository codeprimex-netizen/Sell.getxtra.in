<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Review\WishlistRepositoryInterface;
use PDO;

/**
 * PDO-backed wishlist store (Req 7.1). Uses INSERT IGNORE so toggling is
 * idempotent against the composite primary key.
 */
final class PdoWishlistRepository extends Repository implements WishlistRepositoryInterface
{
    protected string $table = 'wishlists';

    public function add(int $userId, int $productId): void
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT IGNORE INTO {$this->table} (user_id, product_id) VALUES (:u, :p)"
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);
    }

    public function remove(int $userId, int $productId): void
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE user_id = :u AND product_id = :p"
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);
    }

    public function has(int $userId, int $productId): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT 1 FROM {$this->table} WHERE user_id = :u AND product_id = :p LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);
        return $stmt->fetchColumn() !== false;
    }

    public function productIds(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT product_id FROM {$this->table} WHERE user_id = :u"
        );
        $stmt->execute(['u' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT p.* FROM {$this->table} w
             INNER JOIN products p ON p.id = w.product_id
             WHERE w.user_id = :u AND p.deleted_at IS NULL
             ORDER BY w.created_at DESC"
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }
}
