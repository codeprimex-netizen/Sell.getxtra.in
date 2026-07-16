<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Infrastructure\Queue\Job;
use App\Infrastructure\Security\AntivirusScanner;
use App\Infrastructure\Security\ScanResult;
use App\Infrastructure\Storage\StorageManager;

/**
 * Scans an uploaded deliverable for malware and records the result on both
 * the version and, when it is the current version, the product. A product
 * stays unpurchasable until its current version is clean (Req 4.4).
 */
final class ScanProductVersion implements Job
{
    public function __construct(
        private int $versionId,
        private ProductVersionRepositoryInterface $versions,
        private ProductRepositoryInterface $products,
        private AntivirusScanner $scanner,
        private StorageManager $storage,
    ) {
    }

    public function queue(): string
    {
        return 'scans';
    }

    public function handle(): void
    {
        $version = $this->versions->findById($this->versionId);
        if ($version === null) {
            return;
        }

        $disk = $this->storage->private();
        $path = $disk->path((string) $version['storage_key']);

        $result = $path !== null && $disk->exists((string) $version['storage_key'])
            ? $this->scanner->scan($path)
            : ScanResult::error('deliverable missing');

        $status = $result->status();
        $this->versions->setScanStatus($this->versionId, $status);

        // Reflect the scan on the product when this is its current version.
        if ((int) ($version['is_current'] ?? 0) === 1) {
            $this->products->setScanStatus((int) $version['product_id'], $status);
        }
    }
}
