<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Commerce\PurchaseCheckerInterface;
use App\Domain\Review\ReviewRepositoryInterface;
use App\Infrastructure\Queue\QueueInterface;
use App\Jobs\RecalculateProductRating;

/**
 * Review submission, moderation, and seller replies (Req 7.2-7.5).
 *
 * Reviews from verified purchasers are flagged accordingly. After any change
 * to a product's reviews, a RecalculateProductRating job refreshes the
 * denormalized aggregate. Whether unverified reviews are allowed and whether
 * they auto-publish are configurable here.
 */
final class ReviewService
{
    private const REQUIRE_PURCHASE = false; // Req 7.4 (config); relaxes until Phase 5
    private const AUTO_PUBLISH = true;

    public function __construct(
        private ReviewRepositoryInterface $reviews,
        private ProductRepositoryInterface $products,
        private PurchaseCheckerInterface $purchases,
        private QueueInterface $queue,
    ) {
    }

    /**
     * @throws ReviewException
     * @return int new review id
     */
    public function submit(int $productId, int $userId, int $rating, ?string $comment): int
    {
        if ($rating < 1 || $rating > 5) {
            throw ReviewException::invalidRating();
        }

        $product = $this->products->findById($productId);
        if ($product === null || ($product['status'] ?? '') !== 'approved') {
            throw ReviewException::notReviewable();
        }
        if ((int) $product['seller_id'] === $userId) {
            throw ReviewException::ownProduct();
        }
        if ($this->reviews->findByUserAndProduct($userId, $productId) !== null) {
            throw ReviewException::alreadyReviewed();
        }

        $verified = $this->purchases->hasPurchased($userId, $productId);
        if (self::REQUIRE_PURCHASE && !$verified) {
            throw ReviewException::purchaseRequired();
        }

        $reviewId = $this->reviews->create([
            'product_id'  => $productId,
            'user_id'     => $userId,
            'rating'      => $rating,
            'comment'     => $comment !== null ? trim($comment) : null,
            'is_verified' => $verified ? 1 : 0,
            'status'      => self::AUTO_PUBLISH ? 'published' : 'pending',
        ]);

        $this->recalculate($productId);

        return $reviewId;
    }

    /** @throws ReviewException */
    public function moderate(int $reviewId, string $status): void
    {
        if (!in_array($status, ['published', 'rejected', 'pending'], true)) {
            throw new ReviewException('Invalid review status.', 'invalid_status');
        }
        $review = $this->reviews->findById($reviewId);
        if ($review === null) {
            throw new ReviewException('Review not found.', 'not_found');
        }

        $this->reviews->setStatus($reviewId, $status);
        $this->recalculate((int) $review['product_id']);
    }

    /** Seller responds to a review on their own product. @throws ReviewException */
    public function reply(int $reviewId, int $sellerId, string $reply): void
    {
        $review = $this->reviews->findById($reviewId);
        if ($review === null) {
            throw new ReviewException('Review not found.', 'not_found');
        }

        $product = $this->products->findById((int) $review['product_id']);
        if ($product === null || (int) $product['seller_id'] !== $sellerId) {
            throw ReviewException::forbidden();
        }

        $this->reviews->setSellerReply($reviewId, trim($reply));
    }

    public function delete(int $reviewId): void
    {
        $review = $this->reviews->findById($reviewId);
        if ($review === null) {
            return;
        }
        $this->reviews->delete($reviewId);
        $this->recalculate((int) $review['product_id']);
    }

    private function recalculate(int $productId): void
    {
        $this->queue->push(new RecalculateProductRating($productId, $this->reviews, $this->products));
    }
}
