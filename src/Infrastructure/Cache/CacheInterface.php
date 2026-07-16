<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

/**
 * Cache contract for hot data (Req 16.1). Supports TTLs and tag-based
 * invalidation so a write can atomically expire every dependent entry
 * (e.g. invalidate the "categories" tag when a category changes).
 */
interface CacheInterface
{
    /** Return the cached value, or null on miss/expiry. */
    public function get(string $key): mixed;

    /**
     * Store a value with an optional TTL (seconds; 0 = no expiry) and tags.
     *
     * @param array<int,string> $tags
     */
    public function set(string $key, mixed $value, int $ttl = 0, array $tags = []): void;

    public function has(string $key): bool;

    public function delete(string $key): void;

    /** Invalidate every key associated with a tag. Returns the count removed. */
    public function deleteByTag(string $tag): int;

    /**
     * Return the cached value or compute, store, and return it (read-through).
     *
     * @param array<int,string> $tags
     */
    public function remember(string $key, int $ttl, callable $callback, array $tags = []): mixed;

    public function flush(): void;
}
