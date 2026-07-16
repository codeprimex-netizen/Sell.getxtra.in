<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Review\ReviewRepositoryInterface;

/**
 * PDO-backed review store. Aggregates only count published reviews so the
 * displayed rating reflects moderated content (Req 7.5).
 */
final class PdoReviewRepository extends Repository implements ReviewRepositoryInterface
{
    protected string $table = 'reviews';

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByUserAndProduct(int $userId, int $productId): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :u AND product_id = :p LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function publishedForProduct(int $productId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT r.*, u.name AS author_name
             FROM {$this->table} r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.product_id = :p AND r.status = 'published'
             ORDER BY r.created_at DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('p', $productId, \PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 200)), \PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function pending(int $limit = 50): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT :lim"
        );
        $stmt->bindValue('lim', max(1, min($limit, 200)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function setStatus(int $id, string $status): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'id' => $id]);
    }

    public function setSellerReply(int $id, string $reply): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET seller_reply = :r WHERE id = :id"
        );
        return $stmt->execute(['r' => $reply, 'id' => $id]);
    }

    // Widened param type keeps compatibility with base Repository::delete().
    public function delete(int|string $id): bool
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    public function aggregate(int $productId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt
             FROM {$this->table} WHERE product_id = :p AND status = 'published'"
        );
        $stmt->execute(['p' => $productId]);
        $row = $stmt->fetch() ?: [];

        return [
            'avg'   => (float) ($row['avg_rating'] ?? 0),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }
}
