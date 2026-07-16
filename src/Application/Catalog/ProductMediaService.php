<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\ProductFileRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Http\UploadedFile;
use App\Infrastructure\Storage\StorageManager;
use App\Support\Security\Token;

/**
 * Uploads product media (thumbnail, gallery screenshots) to the public disk
 * and records references. Media is CDN-served; deliverables stay private.
 * See Req 4.2.
 */
final class ProductMediaService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductFileRepositoryInterface $files,
        private FileValidator $validator,
        private StorageManager $storage,
    ) {
    }

    /** @throws CatalogException */
    public function setThumbnail(int $productId, int $sellerId, UploadedFile $image): string
    {
        $this->assertOwner($productId, $sellerId);
        $key = $this->storeImage($productId, $image);

        $url = $this->storage->public()->url($key);
        $this->products->update($productId, ['thumbnail_url' => $url]);
        $this->files->add($productId, 'thumbnail', $key);

        return $url;
    }

    /** @throws CatalogException */
    public function addScreenshot(int $productId, int $sellerId, UploadedFile $image, int $sortOrder = 0): int
    {
        $this->assertOwner($productId, $sellerId);
        $key = $this->storeImage($productId, $image);

        return $this->files->add($productId, 'screenshot', $key, $sortOrder);
    }

    /**
     * Gallery screenshots for a product with their public (CDN) URLs.
     *
     * @return array<int, array{id:int, url:string, sort_order:int}>
     */
    public function screenshots(int $productId): array
    {
        return array_map(
            fn (array $f): array => [
                'id'         => (int) $f['id'],
                'url'        => $this->storage->public()->url((string) $f['storage_key']),
                'sort_order' => (int) ($f['sort_order'] ?? 0),
            ],
            $this->files->forProduct($productId, 'screenshot'),
        );
    }

    /**
     * Remove a screenshot (owner-scoped): deletes the stored object and the
     * reference. Returns false if it doesn't belong to the product.
     *
     * @throws CatalogException
     */
    public function deleteScreenshot(int $productId, int $sellerId, int $fileId): bool
    {
        $this->assertOwner($productId, $sellerId);

        $match = null;
        foreach ($this->files->forProduct($productId, 'screenshot') as $f) {
            if ((int) $f['id'] === $fileId) {
                $match = $f;
                break;
            }
        }
        if ($match === null) {
            return false;
        }

        $this->storage->public()->delete((string) $match['storage_key']);
        return $this->files->delete($fileId, $productId);
    }

    /** @throws CatalogException */
    private function storeImage(int $productId, UploadedFile $image): string
    {
        $errors = $this->validator->validateImage($image);
        if ($errors !== []) {
            throw new CatalogException($errors[0], 'invalid_upload');
        }

        $ext = $image->extension() !== '' ? $image->extension() : 'jpg';
        $key = sprintf('products/%d/media/%s.%s', $productId, Token::random(12), $ext);
        $this->storage->public()->putFile($key, $image->tmpPath());

        return $key;
    }

    /** @throws CatalogException */
    private function assertOwner(int $productId, int $sellerId): void
    {
        $product = $this->products->findById($productId);
        if ($product === null) {
            throw CatalogException::notFound();
        }
        if ((int) $product['seller_id'] !== $sellerId) {
            throw CatalogException::forbidden();
        }
    }
}
