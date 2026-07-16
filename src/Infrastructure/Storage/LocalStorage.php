<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use RuntimeException;

/**
 * Filesystem-backed storage disk. Keys are relative paths under a root
 * directory; directory traversal is prevented. Used in development and as a
 * fallback; production swaps in an S3 driver behind StorageInterface.
 */
final class LocalStorage implements StorageInterface
{
    public function __construct(
        private string $root,
        private string $baseUrl = '',
        private bool $public = false,
    ) {
        $this->root = rtrim($root, '/');
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0775, true);
        }
    }

    public function put(string $key, string $contents): string
    {
        $target = $this->resolve($key);
        $this->ensureDir(dirname($target));
        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write [{$key}].");
        }
        return $key;
    }

    public function putFile(string $key, string $sourcePath): string
    {
        $target = $this->resolve($key);
        $this->ensureDir(dirname($target));

        // Prefer move_uploaded_file for real uploads; fall back to copy/rename.
        $moved = is_uploaded_file($sourcePath)
            ? @move_uploaded_file($sourcePath, $target)
            : @rename($sourcePath, $target);

        if (!$moved && !@copy($sourcePath, $target)) {
            throw new RuntimeException("Failed to store uploaded file at [{$key}].");
        }

        return $key;
    }

    public function get(string $key): ?string
    {
        $target = $this->resolve($key);
        if (!is_file($target)) {
            return null;
        }
        $data = file_get_contents($target);
        return $data === false ? null : $data;
    }

    public function exists(string $key): bool
    {
        return is_file($this->resolve($key));
    }

    public function delete(string $key): bool
    {
        $target = $this->resolve($key);
        return is_file($target) ? @unlink($target) : false;
    }

    public function size(string $key): int
    {
        $target = $this->resolve($key);
        return is_file($target) ? (int) filesize($target) : 0;
    }

    public function path(string $key): ?string
    {
        return $this->resolve($key);
    }

    public function url(string $key): string
    {
        if (!$this->public || $this->baseUrl === '') {
            return '';
        }
        return rtrim($this->baseUrl, '/') . '/' . ltrim($key, '/');
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /** Resolve a key to an absolute path, rejecting traversal outside root. */
    private function resolve(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if (str_contains($key, '..')) {
            throw new RuntimeException('Invalid storage key.');
        }
        return $this->root . '/' . $key;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
