<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Audit\AuditLogger;
use App\Application\Catalog\ModerationService;
use App\Domain\Catalog\ProductRepositoryInterface;

/**
 * Admin product controls beyond moderation (Req 12.2): featured/spotlight
 * toggle and suspension of a live product.
 */
final class ProductAdminService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ModerationService $moderation,
        private AuditLogger $audit,
    ) {
    }

    /** @throws AdminException */
    public function setFeatured(int $productId, bool $featured, int $actorId, ?string $ip = null): void
    {
        if ($this->products->findById($productId) === null) {
            throw AdminException::notFound('Product');
        }
        $this->products->update($productId, ['is_featured' => $featured ? 1 : 0]);
        $this->audit->log('product.feature', $actorId, 'product', $productId, ['featured' => $featured], $ip);
    }

    /** Suspend a live product (delegates transition to ModerationService). */
    public function suspend(int $productId, string $reason, int $actorId, ?string $ip = null): void
    {
        $this->moderation->suspend($productId, $reason);
        $this->audit->log('product.suspend', $actorId, 'product', $productId, ['reason' => $reason], $ip);
    }
}
