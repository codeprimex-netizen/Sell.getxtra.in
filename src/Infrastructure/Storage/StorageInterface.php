<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Object-storage contract. A "public" disk serves media via CDN; a "private"
 * disk holds deliverables that are only released through signed/streamed
 * downloads (Req 4.2 / 10.1). The local driver backs development; an
 * S3-compatible driver implements the same interface in production.
 */
interface StorageInterface
{
    /** Store raw contents at a logical key; returns the stored key. */
    public function put(string $key, string $contents): string;

    /** Move an uploaded/temp file into storage under $key; returns the key. */
    public function putFile(string $key, string $sourcePath): string;

    public function get(string $key): ?string;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    public function size(string $key): int;

    /** Absolute filesystem path for local streaming (null for remote drivers). */
    public function path(string $key): ?string;

    /** Public URL for a key (empty string when the disk is private). */
    public function url(string $key): string;

    public function isPublic(): bool;
}
