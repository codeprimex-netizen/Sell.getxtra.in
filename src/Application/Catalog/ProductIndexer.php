<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;
use App\Infrastructure\Queue\QueueInterface;
use App\Infrastructure\Search\SearchIndex;
use App\Jobs\IndexProduct;

/**
 * Queues search-index synchronization for a product. Called after moderation
 * transitions (approve/suspend/archive) so the storefront search stays fresh
 * (Req 6.1). The IndexProduct job upserts or removes based on final status.
 */
final class ProductIndexer
{
    public function __construct(
        private QueueInterface $queue,
        private ProductRepositoryInterface $products,
        private TagRepositoryInterface $tags,
        private SearchIndex $index,
    ) {
    }

    public function sync(int $productId): void
    {
        $this->queue->push(new IndexProduct($productId, $this->products, $this->tags, $this->index));
    }
}
