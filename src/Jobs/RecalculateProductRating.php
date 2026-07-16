<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Review\ReviewRepositoryInterface;
use App\Infrastructure\Queue\Job;

/**
 * Recomputes a product's denormalized rating aggregate from its published
 * reviews (Req 7.5). Dispatched whenever a review is created, moderated, or
 * deleted so the stored avg_rating / rating_count stay accurate.
 */
final class RecalculateProductRating implements Job
{
    public function __construct(
        private int $productId,
        private ReviewRepositoryInterface $reviews,
        private ProductRepositoryInterface $products,
    ) {
    }

    public function queue(): string
    {
        return 'ratings';
    }

    public function handle(): void
    {
        $aggregate = $this->reviews->aggregate($this->productId);
        $this->products->updateRating(
            $this->productId,
            (float) $aggregate['avg'],
            (int) $aggregate['count'],
        );
    }
}
