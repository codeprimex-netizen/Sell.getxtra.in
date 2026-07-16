<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Domain\Catalog\LicenseTierRepositoryInterface;
use App\Domain\Catalog\ProductFileRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;

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
}

final class InMemoryProductFileRepository implements ProductFileRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function add(int $productId, string $type, string $storageKey, int $sortOrder = 0): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = compact('id', 'productId', 'type', 'storageKey', 'sortOrder');
        return $id;
    }

    public function forProduct(int $productId, ?string $type = null): array
    {
        return array_values(array_filter($this->rows, static function ($r) use ($productId, $type) {
            return (int) $r['productId'] === $productId && ($type === null || $r['type'] === $type);
        }));
    }

    public function delete(int $id, int $productId): bool
    {
        unset($this->rows[$id]);
        return true;
    }
}
