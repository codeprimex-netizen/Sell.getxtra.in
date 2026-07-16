<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

/**
 * File-backed cache — the offline/dev default that works without Redis while
 * implementing the same tag-invalidation semantics. Values are serialized to
 * per-key files; each tag maintains an index of its keys.
 */
final class FileCache implements CacheInterface
{
    public function __construct(private string $dir)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => true]);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
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
        $entry = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : ($ttl < 0 ? time() - 1 : 0)];
        @file_put_contents($this->file($key), serialize($entry), LOCK_EX);
        foreach ($tags as $tag) {
            $this->addToTag($tag, $key);
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function deleteByTag(string $tag): int
    {
        $tagFile = $this->tagFile($tag);
        if (!is_file($tagFile)) {
            return 0;
        }
        $keys = array_filter(explode("\n", (string) file_get_contents($tagFile)));
        foreach ($keys as $key) {
            $this->delete($key);
        }
        @unlink($tagFile);
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
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function addToTag(string $tag, string $key): void
    {
        $tagFile = $this->tagFile($tag);
        $existing = is_file($tagFile) ? array_filter(explode("\n", (string) file_get_contents($tagFile))) : [];
        if (!in_array($key, $existing, true)) {
            @file_put_contents($tagFile, $key . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/k_' . hash('sha256', $key) . '.cache';
    }

    private function tagFile(string $tag): string
    {
        return $this->dir . '/tag_' . hash('sha256', $tag) . '.idx';
    }
}
