<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Http\UploadedFile;
use App\Infrastructure\Queue\QueueInterface;
use App\Infrastructure\Security\AntivirusScanner;
use App\Infrastructure\Storage\StorageManager;
use App\Jobs\ScanProductVersion;
use App\Support\Security\Token;

/**
 * Handles versioned deliverable uploads (Req 5). The archive is validated,
 * stored on the private disk, checksummed, made the current version, and
 * queued for an antivirus scan before it can ever be sold (Req 4.4).
 */
final class ProductVersionService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductVersionRepositoryInterface $versions,
        private FileValidator $validator,
        private StorageManager $storage,
        private QueueInterface $queue,
        private AntivirusScanner $scanner,
    ) {
    }

    /**
     * @throws CatalogException on ownership/validation failure
     * @return int the new version id
     */
    public function addVersion(
        int $productId,
        int $sellerId,
        string $versionNumber,
        ?string $changelog,
        UploadedFile $file,
    ): int {
        $product = $this->products->findById($productId);
        if ($product === null) {
            throw CatalogException::notFound();
        }
        if ((int) $product['seller_id'] !== $sellerId) {
            throw CatalogException::forbidden();
        }

        $errors = $this->validator->validateArchive($file);
        if ($errors !== []) {
            throw new CatalogException($errors[0], 'invalid_upload');
        }

        $versionNumber = trim($versionNumber) !== '' ? trim($versionNumber) : '1.0.0';
        $checksum = $file->sha256();
        $key = sprintf('products/%d/versions/%s.zip', $productId, Token::random(16));

        $this->storage->private()->putFile($key, $file->tmpPath());

        $versionId = $this->versions->create([
            'product_id'      => $productId,
            'version_number'  => mb_substr($versionNumber, 0, 30),
            'changelog'       => $changelog !== null ? trim($changelog) : null,
            'storage_key'     => $key,
            'file_size_bytes' => $file->size(),
            'checksum_sha256' => $checksum !== '' ? $checksum : null,
            'scan_status'     => 'pending',
            'is_current'      => 1,
        ]);

        // Newest upload becomes the current downloadable version.
        $this->versions->markCurrent($versionId, $productId);
        // Reset product scan state until this version is cleared.
        $this->products->setScanStatus($productId, 'pending');

        // Queue the antivirus scan (runs inline under the sync driver).
        $this->queue->push(new ScanProductVersion(
            $versionId,
            $this->versions,
            $this->products,
            $this->scanner,
            $this->storage,
        ));

        return $versionId;
    }
}
