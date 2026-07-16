<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Seller\SellerProfileService;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use Closure;

/**
 * KYC gate (Req 11.1). Blocks selling/payout actions until the seller's
 * profile is KYC-verified, redirecting them to complete onboarding.
 */
final class SellerVerified implements MiddlewareInterface
{
    public function __construct(private SellerProfileService $sellers)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $userId = $request->attribute('auth_user_id');

        if (is_int($userId) && $this->sellers->isVerified($userId)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::apiError('kyc_required', 'KYC verification is required.', 403);
        }

        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->flash('error', 'Please complete KYC verification to continue.');
        }
        return Response::redirect('/seller/profile');
    }
}
