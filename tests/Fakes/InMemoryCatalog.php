<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Domain\Catalog\LicenseTierRepositoryInterface;
use App\Domain\Catalog\ProductFileRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\SearchResult;
use App\Domain\Catalog\TagRepositoryInterface;
use App\Domain\Review\ReviewRepositoryInterface;
use App\Domain\Review\WishlistRepositoryInterface;

/** In-memory catalog repositories for DB-free Phase 3 tests. */
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    /** @var array<int, array<int,int>> */
    public array $tags = [];
    private int $seq = 0;

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->rows as $r) {
            if ($r['slug'] === $slug) {
                return $r;
            }
        }
        return null;
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        foreach ($this->rows as $r) {
            if ($r['slug'] === $slug && (int) $r['id'] !== $ignoreId) {
                return true;
            }
        }
        return false;
    }

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $data['views'] = 0;
        $data['scan_status'] ??= 'pending';
        $data['deleted_at'] ??= null;
        $this->rows[$id] = $data;
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return true;
    }

    public function updateStatus(int $id, string $status, ?string $rejectReason = null): bool
    {
        return $this->update($id, ['status' => $status, 'reject_reason' => $rejectReason]);
    }

    public function markPublished(int $id): bool
    {
        return $this->update($id, ['published_at' => date('Y-m-d H:i:s')]);
    }

    public function setScanStatus(int $id, string $scanStatus): bool
    {
        return $this->update($id, ['scan_status' => $scanStatus]);
    }

    public function incrementViews(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['views'] = (int) $this->rows[$id]['views'] + 1;
        }
    }

    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => (int) $r['seller_id'] === $sellerId));
    }

    public function listApproved(?int $categoryId = null, int $limit = 24, int $offset = 0): array
    {
        return array_values(array_filter($this->rows, static function ($r) use ($categoryId) {
            $ok = $r['status'] === 'approved' && ($r['scan_status'] ?? '') === 'clean';
            return $categoryId === null ? $ok : ($ok && (int) ($r['category_id'] ?? 0) === $categoryId);
        }));
    }

    public function listApprovedKeyset(?int $categoryId = null, ?int $afterId = null, int $limit = 24): array
    {
        $rows = array_values(array_filter($this->rows, static function ($r) use ($categoryId, $afterId) {
            $ok = $r['status'] === 'approved' && ($r['scan_status'] ?? '') === 'clean';
            if ($categoryId !== null) {
                $ok = $ok && (int) ($r['category_id'] ?? 0) === $categoryId;
            }
            if ($afterId !== null) {
                $ok = $ok && (int) $r['id'] < $afterId;
            }
            return $ok;
        }));
        usort($rows, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_slice($rows, 0, max(1, min($limit, 100)));
    }

    public function listByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => $r['status'] === $status));
    }

    public function syncTags(int $productId, array $tagIds): void
    {
        $this->tags[$productId] = array_values(array_unique($tagIds));
    }

    public function tagIds(int $productId): array
    {
        return $this->tags[$productId] ?? [];
    }

    public function search(SearchCriteria $c): SearchResult
    {
        $matches = array_filter($this->rows, function ($r) use ($c) {
            if ($r['status'] !== 'approved' || ($r['scan_status'] ?? '') !== 'clean') {
                return false;
            }
            if ($c->hasQuery() && stripos((string) $r['title'], $c->query) === false) {
                return false;
            }
            if ($c->categoryId !== null && (int) ($r['category_id'] ?? 0) !== $c->categoryId) {
                return false;
            }
            if ($c->priceMin !== null && (float) $r['base_price'] < $c->priceMin) {
                return false;
            }
            if ($c->priceMax !== null && (float) $r['base_price'] > $c->priceMax) {
                return false;
            }
            if ($c->minRating !== null && (float) ($r['avg_rating'] ?? 0) < $c->minRating) {
                return false;
            }
            return true;
        });

        $items = array_values($matches);
        $total = count($items);
        $items = array_slice($items, $c->offset(), $c->perPage);

        return new SearchResult($items, $total, $c->page, $c->perPage, 'mysql');
    }

    public function related(int $productId, ?int $categoryId, array $tagIds, int $limit = 6): array
    {
        return array_values(array_filter($this->rows, static function ($r) use ($productId, $categoryId) {
            return (int) $r['id'] !== $productId
                && $r['status'] === 'approved'
                && ($categoryId === null || (int) ($r['category_id'] ?? 0) === $categoryId);
        }));
    }

    public function updateRating(int $productId, float $avgRating, int $ratingCount): bool
    {
        return $this->update($productId, ['avg_rating' => $avgRating, 'rating_count' => $ratingCount]);
    }

    public function incrementSales(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['sales_count'] = (int) ($this->rows[$id]['sales_count'] ?? 0) + 1;
        }
    }
}

final class InMemoryProductVersionRepository implements ProductVersionRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function forProduct(int $productId): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => (int) $r['product_id'] === $productId));
    }

    public function currentForProduct(int $productId): ?array
    {
        foreach ($this->rows as $r) {
            if ((int) $r['product_id'] === $productId && (int) ($r['is_current'] ?? 0) === 1) {
                return $r;
            }
        }
        return null;
    }

    public function setScanStatus(int $versionId, string $scanStatus): bool
    {
        if (!isset($this->rows[$versionId])) {
            return false;
        }
        $this->rows[$versionId]['scan_status'] = $scanStatus;
        return true;
    }

    public function markCurrent(int $versionId, int $productId): void
    {
        foreach ($this->rows as $id => $r) {
            if ((int) $r['product_id'] === $productId) {
                $this->rows[$id]['is_current'] = $id === $versionId ? 1 : 0;
            }
        }
    }
}

final class InMemoryTagRepository implements TagRepositoryInterface
{
    /** @var array<string,int> slug=>id */
    public array $bySlug = [];
    /** @var array<int,string> id=>name */
    public array $names = [];
    private int $seq = 0;

    public function resolveOrCreate(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $slug = trim(strtolower($name));
            if ($slug === '') {
                continue;
            }
            if (!isset($this->bySlug[$slug])) {
                $id = ++$this->seq;
                $this->bySlug[$slug] = $id;
                $this->names[$id] = $name;
            }
            $ids[] = $this->bySlug[$slug];
        }
        return array_values(array_unique($ids));
    }

    public function namesFor(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->names[$id])) {
                $out[] = $this->names[$id];
            }
        }
        return $out;
    }
}

final class InMemoryLicenseTierRepository implements LicenseTierRepositoryInterface
{
    /** @var array<int, array<int,array<string,mixed>>> */
    public array $byProduct = [];

    public function forProduct(int $productId): array
    {
        return $this->byProduct[$productId] ?? [];
    }

    public function replaceForProduct(int $productId, array $tiers): void
    {
        $this->byProduct[$productId] = $tiers;
    }

    public function findById(int $id): ?array
    {
        return null;
    }
}

final class InMemoryCategoryRepository implements CategoryRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [1 => ['id' => 1, 'name' => 'PHP Scripts', 'slug' => 'php-scripts', 'is_active' => 1]];

    public function allActive(): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => (int) ($r['is_active'] ?? 1) === 1));
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->rows as $r) {
            if ($r['slug'] === $slug) {
                return $r;
            }
        }
        return null;
    }

    public function create(array $data): int
    {
        $id = count($this->rows) + 1;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return true;
    }

    public function delete(int $id): bool
    {
        unset($this->rows[$id]);
        return true;
    }
}

final class InMemoryProductFileRepository implements ProductFileRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function add(int $productId, string $type, string $storageKey, int $sortOrder = 0): int
    {
        $id = ++$this->seq;
        // Mirror the DB column names returned by the PDO repository.
        $this->rows[$id] = [
            'id'          => $id,
            'product_id'  => $productId,
            'type'        => $type,
            'storage_key' => $storageKey,
            'sort_order'  => $sortOrder,
        ];
        return $id;
    }

    public function forProduct(int $productId, ?string $type = null): array
    {
        return array_values(array_filter($this->rows, static function ($r) use ($productId, $type) {
            return (int) $r['product_id'] === $productId && ($type === null || $r['type'] === $type);
        }));
    }

    public function delete(int $id, int $productId): bool
    {
        unset($this->rows[$id]);
        return true;
    }
}


final class InMemoryReviewRepository implements ReviewRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByUserAndProduct(int $userId, int $productId): ?array
    {
        foreach ($this->rows as $r) {
            if ((int) $r['user_id'] === $userId && (int) $r['product_id'] === $productId) {
                return $r;
            }
        }
        return null;
    }

    public function publishedForProduct(int $productId, int $limit = 50, int $offset = 0): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn ($r) => (int) $r['product_id'] === $productId && $r['status'] === 'published'
        ));
    }

    public function pending(int $limit = 50): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => $r['status'] === 'pending'));
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['status'] = $status;
        return true;
    }

    public function setSellerReply(int $id, string $reply): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['seller_reply'] = $reply;
        return true;
    }

    public function delete(int $id): bool
    {
        unset($this->rows[$id]);
        return true;
    }

    public function aggregate(int $productId): array
    {
        $ratings = [];
        foreach ($this->rows as $r) {
            if ((int) $r['product_id'] === $productId && $r['status'] === 'published') {
                $ratings[] = (int) $r['rating'];
            }
        }
        $count = count($ratings);
        return [
            'avg'   => $count > 0 ? array_sum($ratings) / $count : 0.0,
            'count' => $count,
        ];
    }
}

final class InMemoryWishlistRepository implements WishlistRepositoryInterface
{
    /** @var array<int, array<int,int>> userId => productIds */
    public array $items = [];

    public function add(int $userId, int $productId): void
    {
        $this->items[$userId][$productId] = $productId;
    }

    public function remove(int $userId, int $productId): void
    {
        unset($this->items[$userId][$productId]);
    }

    public function has(int $userId, int $productId): bool
    {
        return isset($this->items[$userId][$productId]);
    }

    public function productIds(int $userId): array
    {
        return array_values($this->items[$userId] ?? []);
    }

    public function forUser(int $userId): array
    {
        return array_map(static fn ($id) => ['id' => $id], $this->productIds($userId));
    }
}
