<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

/**
 * In-process cache for tests and single-request memoization. Not shared across
 * requests — production uses {@see RedisCache}.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value:mixed, expires:int}> */
    private array $store = [];

    /** @var array<string, array<int,string>> tag => keys */
    private array $tags = [];

    public function get(string $key): mixed
    {
        $entry = $this->store[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            $this->delete($key);
            return null;
        }
        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0, array $tags = []): void
    {
        $this->store[$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : ($ttl < 0 ? time() - 1 : 0),
        ];
        foreach ($tags as $tag) {
            $this->tags[$tag][$key] = $key;
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
        foreach ($this->tags as $tag => $keys) {
            unset($this->tags[$tag][$key]);
        }
    }

    public function deleteByTag(string $tag): int
    {
        $keys = $this->tags[$tag] ?? [];
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }
        unset($this->tags[$tag]);
        return count($keys);
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
        $this->store = [];
        $this->tags = [];
    }
}
