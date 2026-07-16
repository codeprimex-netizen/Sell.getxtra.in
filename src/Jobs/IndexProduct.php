<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;
use App\Infrastructure\Queue\Job;
use App\Infrastructure\Search\SearchIndex;

/**
 * Keeps the search engine in sync with a product's state (Req 6.1). When the
 * product is approved it is upserted as a search document; otherwise it is
 * removed from the index. Dispatched on approve/suspend/archive.
 */
final class IndexProduct implements Job
{
    public function __construct(
        private int $productId,
        private ProductRepositoryInterface $products,
        private TagRepositoryInterface $tags,
        private SearchIndex $index,
    ) {
    }

    public function queue(): string
    {
        return 'search';
    }

    public function handle(): void
    {
        if (!$this->index->available()) {
            return;
        }

        $product = $this->products->findById($this->productId);
        if ($product === null || ($product['status'] ?? '') !== 'approved') {
            $this->index->delete($this->productId);
            return;
        }

        $tagNames = $this->tags->namesFor($this->products->tagIds($this->productId));

        $this->index->upsert([
            'id'           => (int) $product['id'],
            'title'        => (string) $product['title'],
            'slug'         => (string) $product['slug'],
            'short_desc'   => (string) ($product['short_desc'] ?? ''),
            'description'  => strip_tags((string) ($product['description'] ?? '')),
            'category_id'  => (int) ($product['category_id'] ?? 0),
            'tags'         => $tagNames,
            'difficulty'   => (string) ($product['difficulty'] ?? ''),
            'base_price'   => (float) $product['base_price'],
            'avg_rating'   => (float) $product['avg_rating'],
            'sales_count'  => (int) $product['sales_count'],
            'thumbnail_url' => (string) ($product['thumbnail_url'] ?? ''),
            'status'       => (string) $product['status'],
            'published_at' => (string) ($product['published_at'] ?? ''),
        ]);
    }
}
