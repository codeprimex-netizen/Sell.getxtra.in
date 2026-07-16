<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Api\WebhookService;
use App\Domain\Api\WebhookEvent;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVersionRepositoryInterface;

/**
 * Moderation actions on pending products (Req 12.1 / 4.8). Approving
 * publishes the product; a product with an infected current version cannot
 * be approved. Rejecting records a reason for the seller.
 */
final class ModerationService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductVersionRepositoryInterface $versions,
        private ?WebhookService $webhooks = null,
    ) {
    }

    /** @return array<int, array<string,mixed>> products awaiting review */
    public function queue(int $limit = 50): array
    {
        return array_merge(
            $this->products->listByStatus(ProductStatus::Pending->value, $limit),
            $this->products->listByStatus(ProductStatus::InReview->value, $limit),
        );
    }

    /** @throws CatalogException */
    public function approve(int $productId): void
    {
        $product = $this->load($productId);
        $this->transition($product, ProductStatus::Approved);

        $current = $this->versions->currentForProduct($productId);
        if ($current === null) {
            throw new CatalogException('Cannot approve a product without a deliverable.', 'no_version');
        }
        if (($current['scan_status'] ?? '') === 'infected') {
            throw new CatalogException('Cannot approve: the current version failed the malware scan.', 'infected');
        }

        $this->products->updateStatus($productId, ProductStatus::Approved->value, null);
        $this->products->markPublished($productId);
        // Reflect the current version's scan status on the product.
        $this->products->setScanStatus($productId, (string) ($current['scan_status'] ?? 'pending'));

        // Notify subscribed integrations (Req 19.4).
        $this->webhooks?->emit(WebhookEvent::PRODUCT_APPROVED, [
            'product_id' => $productId,
            'slug'       => (string) ($product['slug'] ?? ''),
            'title'      => (string) ($product['title'] ?? ''),
            'seller_id'  => (int) ($product['seller_id'] ?? 0),
        ]);
    }

    /** @throws CatalogException */
    public function reject(int $productId, string $reason): void
    {
        $product = $this->load($productId);
        $this->transition($product, ProductStatus::Rejected);

        $reason = trim($reason);
        if ($reason === '') {
            throw new CatalogException('A rejection reason is required.', 'validation');
        }

        $this->products->updateStatus($productId, ProductStatus::Rejected->value, mb_substr($reason, 0, 500));
    }

    /** Suspend a live product. @throws CatalogException */
    public function suspend(int $productId, string $reason): void
    {
        $product = $this->load($productId);
        $this->transition($product, ProductStatus::Suspended);
        $this->products->updateStatus($productId, ProductStatus::Suspended->value, mb_substr(trim($reason), 0, 500) ?: null);
    }

    /** @return array<string,mixed> */
    private function load(int $productId): array
    {
        $product = $this->products->findById($productId);
        if ($product === null) {
            throw CatalogException::notFound();
        }
        return $product;
    }

    /** @param array<string,mixed> $product */
    private function transition(array $product, ProductStatus $target): void
    {
        $current = ProductStatus::from((string) $product['status']);
        if (!$current->canTransitionTo($target)) {
            throw CatalogException::invalidTransition($current->value, $target->value);
        }
    }
}
