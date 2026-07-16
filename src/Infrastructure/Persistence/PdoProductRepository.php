<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\ProductRepositoryInterface;
use PDO;

/**
 * PDO-backed product repository. All queries use prepared statements and
 * respect soft-deletes (deleted_at IS NULL). See Req 14.1.
 */
final class PdoProductRepository extends Repository implements ProductRepositoryInterface
{
    protected string $table = 'products';

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE id = :id AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE slug = :slug AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE slug = :slug";
        $params = ['slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore';
            $params['ignore'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->connection->read()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    public function updateStatus(int $id, string $status, ?string $rejectReason = null): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s, reject_reason = :r WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'r' => $rejectReason, 'id' => $id]);
    }

    public function markPublished(int $id): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET published_at = COALESCE(published_at, NOW()) WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    public function setScanStatus(int $id, string $scanStatus): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET scan_status = :s WHERE id = :id"
        );
        return $stmt->execute(['s' => $scanStatus, 'id' => $id]);
    }

    public function incrementViews(int $id): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET views = views + 1 WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE seller_id = :s AND deleted_at IS NULL
             ORDER BY updated_at DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('s', $sellerId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listApproved(?int $categoryId = null, int $limit = 24, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE status = 'approved' AND scan_status = 'clean' AND deleted_at IS NULL";
        $params = [];
        if ($categoryId !== null) {
            $sql .= ' AND category_id = :cat';
            $params['cat'] = $categoryId;
        }
        $sql .= ' ORDER BY is_featured DESC, published_at DESC LIMIT :lim OFFSET :off';

        $stmt = $this->connection->read()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue('lim', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = :st AND deleted_at IS NULL
             ORDER BY updated_at ASC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('st', $status);
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function syncTags(int $productId, array $tagIds): void
    {
        $pdo = $this->connection->write();
        $del = $pdo->prepare('DELETE FROM product_tag WHERE product_id = :p');
        $del->execute(['p' => $productId]);

        if ($tagIds === []) {
            return;
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO product_tag (product_id, tag_id) VALUES (:p, :t)');
        foreach (array_unique($tagIds) as $tagId) {
            $ins->execute(['p' => $productId, 't' => $tagId]);
        }
    }

    public function tagIds(int $productId): array
    {
        $stmt = $this->connection->read()->prepare(
            'SELECT tag_id FROM product_tag WHERE product_id = :p'
        );
        $stmt->execute(['p' => $productId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
