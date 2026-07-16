<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\SearchResult;
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

    public function create(array $data): int
    {
        return $this->insert($data);
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

    public function incrementSales(int $id): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET sales_count = sales_count + 1 WHERE id = :id"
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

    public function search(SearchCriteria $c): SearchResult
    {
        // Base: only approved + clean + not deleted products are searchable.
        $where = ["status = 'approved'", "scan_status = 'clean'", 'deleted_at IS NULL'];
        $params = [];
        $relevance = '0';

        if ($c->hasQuery()) {
            // Boolean-mode FULLTEXT for typo-light matching + relevance score.
            $where[] = 'MATCH(title, short_desc, description) AGAINST (:q IN BOOLEAN MODE)';
            $relevance = 'MATCH(title, short_desc, description) AGAINST (:qScore)';
            $params['q'] = $this->booleanTerms($c->query);
            $params['qScore'] = $c->query;
        }
        if ($c->categoryId !== null) {
            $where[] = 'category_id = :cat';
            $params['cat'] = $c->categoryId;
        }
        if ($c->priceMin !== null) {
            $where[] = 'base_price >= :pmin';
            $params['pmin'] = $c->priceMin;
        }
        if ($c->priceMax !== null) {
            $where[] = 'base_price <= :pmax';
            $params['pmax'] = $c->priceMax;
        }
        if ($c->difficulty !== null) {
            $where[] = 'difficulty = :diff';
            $params['diff'] = $c->difficulty;
        }
        if ($c->minRating !== null) {
            $where[] = 'avg_rating >= :minr';
            $params['minr'] = $c->minRating;
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = $this->orderBy($c->sort, $c->hasQuery());

        $read = $this->connection->read();

        // Total count (without the relevance column).
        $countStmt = $read->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT *, {$relevance} AS relevance FROM {$this->table}
                WHERE {$whereSql} ORDER BY {$orderSql} LIMIT :lim OFFSET :off";
        $stmt = $read->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('lim', $c->perPage, PDO::PARAM_INT);
        $stmt->bindValue('off', $c->offset(), PDO::PARAM_INT);
        $stmt->execute();

        return new SearchResult($stmt->fetchAll(), $total, $c->page, $c->perPage, 'mysql');
    }

    public function related(int $productId, ?int $categoryId, array $tagIds, int $limit = 6): array
    {
        $read = $this->connection->read();

        // Prefer products sharing tags; fall back to same category.
        if ($tagIds !== []) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $sql = "SELECT DISTINCT p.* FROM {$this->table} p
                    INNER JOIN product_tag pt ON pt.product_id = p.id
                    WHERE pt.tag_id IN ({$placeholders})
                      AND p.id <> ? AND p.status = 'approved' AND p.scan_status = 'clean'
                      AND p.deleted_at IS NULL
                    ORDER BY p.sales_count DESC, p.avg_rating DESC
                    LIMIT " . (int) $limit;
            $stmt = $read->prepare($sql);
            $stmt->execute([...array_values($tagIds), $productId]);
            $rows = $stmt->fetchAll();
            if ($rows !== []) {
                return $rows;
            }
        }

        if ($categoryId !== null) {
            $stmt = $read->prepare(
                "SELECT * FROM {$this->table}
                 WHERE category_id = :cat AND id <> :id AND status = 'approved'
                   AND scan_status = 'clean' AND deleted_at IS NULL
                 ORDER BY sales_count DESC, avg_rating DESC LIMIT :lim"
            );
            $stmt->bindValue('cat', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue('id', $productId, PDO::PARAM_INT);
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        return [];
    }

    public function updateRating(int $productId, float $avgRating, int $ratingCount): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET avg_rating = :avg, rating_count = :cnt WHERE id = :id"
        );
        return $stmt->execute([
            'avg' => round($avgRating, 2),
            'cnt' => $ratingCount,
            'id'  => $productId,
        ]);
    }

    /** Map a sort key to an ORDER BY clause. */
    private function orderBy(string $sort, bool $hasQuery): string
    {
        return match ($sort) {
            'newest'     => 'published_at DESC, id DESC',
            'price_asc'  => 'base_price ASC',
            'price_desc' => 'base_price DESC',
            'rating'     => 'avg_rating DESC, rating_count DESC',
            'popular'    => 'sales_count DESC, views DESC',
            default      => $hasQuery ? 'relevance DESC, sales_count DESC' : 'is_featured DESC, published_at DESC',
        };
    }

    /** Turn a free-text query into a safe boolean-mode FULLTEXT expression. */
    private function booleanTerms(string $query): string
    {
        $words = preg_split('/\s+/', trim($query)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            $word = preg_replace('/[+\-><\(\)~*"@]+/', '', $word) ?? '';
            if (mb_strlen($word) >= 2) {
                $terms[] = '+' . $word . '*';
            }
        }
        return $terms === [] ? $query : implode(' ', $terms);
    }
}
