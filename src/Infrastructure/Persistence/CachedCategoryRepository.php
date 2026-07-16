<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Infrastructure\Cache\CacheInterface;

/**
 * Read-through cache decorator for categories (Req 16.1). Category data is hot
 * and rarely changes, so list reads are cached under the "categories" tag and
 * every write invalidates the whole tag — guaranteeing correctness without
 * per-key bookkeeping.
 */
final class CachedCategoryRepository implements CategoryRepositoryInterface
{
    private const TAG = 'categories';
    private const TTL = 3600;

    public function __construct(
        private CategoryRepositoryInterface $inner,
        private CacheInterface $cache,
    ) {
    }

    public function allActive(): array
    {
        return $this->cache->remember('categories:active', self::TTL, fn () => $this->inner->allActive(), [self::TAG]);
    }

    public function all(): array
    {
        return $this->cache->remember('categories:all', self::TTL, fn () => $this->inner->all(), [self::TAG]);
    }

    public function findById(int $id): ?array
    {
        return $this->cache->remember('categories:id:' . $id, self::TTL, fn () => $this->inner->findById($id), [self::TAG]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->cache->remember('categories:slug:' . $slug, self::TTL, fn () => $this->inner->findBySlug($slug), [self::TAG]);
    }

    public function create(array $data): int
    {
        $id = $this->inner->create($data);
        $this->cache->deleteByTag(self::TAG);
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $ok = $this->inner->update($id, $data);
        $this->cache->deleteByTag(self::TAG);
        return $ok;
    }

    public function delete(int $id): bool
    {
        $ok = $this->inner->delete($id);
        $this->cache->deleteByTag(self::TAG);
        return $ok;
    }
}
