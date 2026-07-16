<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Redis;

/**
 * Redis-backed cache for production (Req 16.1). Values are serialized; tag
 * membership is tracked in Redis sets so a single SMEMBERS + pipeline expires
 * every dependent key. Requires ext-redis.
 */
final class RedisCache implements CacheInterface
{
    public function __construct(
        private Redis $redis,
        private string $prefix = 'cache:',
    ) {
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);
        if ($raw === false) {
            return null;
        }
        $value = @unserialize((string) $raw, ['allowed_classes' => true]);
        return $value === false && $raw !== serialize(false) ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0, array $tags = []): void
    {
        $payload = serialize($value);
        if ($ttl > 0) {
            $this->redis->setex($this->prefix . $key, $ttl, $payload);
        } else {
            $this->redis->set($this->prefix . $key, $payload);
        }
        foreach ($tags as $tag) {
            $this->redis->sAdd($this->tagKey($tag), $key);
        }
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefix . $key);
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function deleteByTag(string $tag): int
    {
        $members = $this->redis->sMembers($this->tagKey($tag)) ?: [];
        foreach ($members as $key) {
            $this->redis->del($this->prefix . $key);
        }
        $this->redis->del($this->tagKey($tag));
        return count($members);
    }

    public function remember(string $key, int $ttl, callable $callback, array $tags = []): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        $this->set($key, $value, $ttl, $tags);
        return $value;
    }

    public function flush(): void
    {
        $keys = $this->redis->keys($this->prefix . '*') ?: [];
        foreach ($keys as $key) {
            $this->redis->del($key);
        }
    }

    private function tagKey(string $tag): string
    {
        return $this->prefix . 'tag:' . $tag;
    }
}
