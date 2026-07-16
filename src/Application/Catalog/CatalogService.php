<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\LicenseTierRepositoryInterface;
use App\Domain\Catalog\ProductFileRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;

/**
 * Read-side catalog queries for the storefront. Full search/faceting arrives
 * in Phase 4; this covers approved listings and the product detail bundle.
 */
final class CatalogService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductVersionRepositoryInterface $versions,
        private LicenseTierRepositoryInterface $tiers,
        private ProductFileRepositoryInterface $files,
        private TagRepositoryInterface $tags,
    ) {
    }

    /** @return array<int, array<string,mixed>> */
    public function listApproved(?int $categoryId = null, int $limit = 24, int $offset = 0): array
    {
        return $this->products->listApproved($categoryId, $limit, $offset);
    }

    /**
     * Related products for a product-detail page (Req 6.5).
     *
     * @param array<string,mixed> $product
     * @return array<int, array<string,mixed>>
     */
    public function related(array $product, int $limit = 6): array
    {
        $productId = (int) $product['id'];
        $categoryId = isset($product['category_id']) ? (int) $product['category_id'] : null;

        return $this->products->related(
            $productId,
            $categoryId,
            $this->products->tagIds($productId),
            $limit,
        );
    }

    /**
     * Hydrate a set of product ids into full rows (for "recently viewed").
     *
     * @param array<int,int> $ids
     * @return array<int, array<string,mixed>>
     */
    public function byIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $product = $this->products->findById((int) $id);
            if ($product !== null && $this->isPubliclyVisible($product)) {
                $out[] = $product;
            }
        }
        return $out;
    }

    /**
     * Full detail bundle for an approved product page, or null if not
     * publicly visible. Increments the view counter as a side effect.
     *
     * @return array<string,mixed>|null
     */
    public function detailBySlug(string $slug): ?array
    {
        $product = $this->products->findBySlug($slug);
        if ($product === null || !$this->isPubliclyVisible($product)) {
            return null;
        }

        $productId = (int) $product['id'];
        $this->products->incrementViews($productId);

        return [
            'product'     => $product,
            'tiers'       => $this->tiers->forProduct($productId),
            'versions'    => $this->versions->forProduct($productId),
            'screenshots' => $this->files->forProduct($productId, 'screenshot'),
            'tags'        => $this->tags->namesFor($this->products->tagIds($productId)),
            'purchasable' => $this->isPurchasable($product),
        ];
    }

    /** @param array<string,mixed> $product */
    public function isPubliclyVisible(array $product): bool
    {
        return ($product['status'] ?? '') === 'approved' && empty($product['deleted_at']);
    }

    /** A product is purchasable only when approved AND its current version is clean. @param array<string,mixed> $product */
    public function isPurchasable(array $product): bool
    {
        return ($product['status'] ?? '') === 'approved' && ($product['scan_status'] ?? '') === 'clean';
    }
}
