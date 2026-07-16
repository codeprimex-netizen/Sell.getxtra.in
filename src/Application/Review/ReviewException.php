<?php

declare(strict_types=1);

namespace App\Application\Review;

use RuntimeException;

/**
 * Raised for expected review failures (validation, duplicate, ownership).
 */
final class ReviewException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'review_error')
    {
        parent::__construct($message);
    }

    public static function invalidRating(): self
    {
        return new self('Rating must be between 1 and 5.', 'invalid_rating');
    }

    public static function notReviewable(): self
    {
        return new self('This product cannot be reviewed.', 'not_reviewable');
    }

    public static function alreadyReviewed(): self
    {
        return new self('You have already reviewed this product.', 'already_reviewed');
    }

    public static function ownProduct(): self
    {
        return new self('You cannot review your own product.', 'own_product');
    }

    public static function purchaseRequired(): self
    {
        return new self('Only buyers can review this product.', 'purchase_required');
    }

    public static function forbidden(): self
    {
        return new self('You cannot modify this review.', 'forbidden');
    }
}
