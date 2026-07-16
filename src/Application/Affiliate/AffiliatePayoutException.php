<?php

declare(strict_types=1);

namespace App\Application\Affiliate;

use RuntimeException;

/**
 * Raised when an affiliate payout request is invalid (below minimum or over
 * the available balance). See {@see AffiliatePayoutService}.
 */
final class AffiliatePayoutException extends RuntimeException
{
}
