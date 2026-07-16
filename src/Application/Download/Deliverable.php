<?php

declare(strict_types=1);

namespace App\Application\Download;

/**
 * Resolved deliverable to stream to an entitled buyer. The storage key is an
 * internal reference on the private disk and is never exposed to clients.
 */
final class Deliverable
{
    public function __construct(
        public readonly string $storageKey,
        public readonly string $filename,
        public readonly int $sizeBytes,
    ) {
    }
}
