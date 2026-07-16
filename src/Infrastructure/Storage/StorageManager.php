<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use RuntimeException;

/**
 * Resolves named storage disks. "public" holds CDN-served media; "private"
 * holds deliverables never exposed directly. In production these map to S3
 * buckets; in development both are local directories.
 */
final class StorageManager
{
    /** @var array<string, StorageInterface> */
    private array $disks = [];

    public function register(string $name, StorageInterface $disk): void
    {
        $this->disks[$name] = $disk;
    }

    public function disk(string $name): StorageInterface
    {
        if (!isset($this->disks[$name])) {
            throw new RuntimeException("Storage disk [{$name}] is not configured.");
        }
        return $this->disks[$name];
    }

    public function public(): StorageInterface
    {
        return $this->disk('public');
    }

    public function private(): StorageInterface
    {
        return $this->disk('private');
    }
}
