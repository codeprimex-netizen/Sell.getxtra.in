<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Account\DashboardController;
use App\Http\Controllers\Web\Account\NotificationController;
use App\Http\Controllers\Web\Account\AffiliateController;
use App\Http\Controllers\Web\Account\PrivacyController;
use App\Http\Controllers\Web\Account\SessionController;
use App\Http\Controllers\Web\ReferralController;
use App\Http\Controllers\Web\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Web\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Web\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Web\Admin\DisputeController as AdminDisputeController;
use App\Http\Controllers\Web\Admin\ModerationController;
use App\Http\Controllers\Web\Admin\ProductAdminController as AdminProductController;
use App\Http\Controllers\Web\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Web\Admin\UserController as AdminUserController;
use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\CategoryController as ApiCategoryController;
use App\Http\Controllers\Api\V1\LicenseController;
use App\Http\Controllers\Api\V1\MeController as ApiMeController;
use App\Http\Controllers\Api\V1\OpenApiController as ApiOpenApiController;
use App\Http\Controllers\Api\V1\OrderController as ApiOrderController;
use App\Http\Controllers\Api\V1\ProductController as ApiProductController;
use App\Http\Controllers\Api\V1\WebhookController as ApiWebhookController;
use App\Http\Controllers\Web\Account\ApiKeyController as AccountApiKeyController;
use App\Http\Controllers\Web\CartController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\CheckoutController;
use App\Http\Controllers\Web\DownloadController;
use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\MetricsController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PaymentWebhookController;
use App\Http\Controllers\Web\ReviewController;
use App\Http\Controllers\Api\V1\EventController as ApiEventController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\UnsubscribeController;
use App\Http\Controllers\Web\Finance\KycController as FinanceKycController;
use App\Http\Controllers\Web\Finance\PayoutController as FinancePayoutController;
use App\Http\Controllers\Web\Seller\DashboardController as SellerDashboardController;
use App\Http\Controllers\Web\Seller\PayoutController as SellerPayoutController;
use App\Http\Controllers\Web\Seller\ProductController;
use App\Http\Controllers\Web\Seller\ProductVersionController;
use App\Http\Controllers\Web\Seller\ProfileController as SellerProfileController;
use App\Http\Controllers\Web\WishlistController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

/**
 * Route registration. Handlers are [Controller::class, 'method'] or closures.
 * Per-route middleware use aliases: auth, guest, can:<perm>, mfa, throttle:<n,min>.
 * Global middleware (request id, security headers, session, CSRF) are applied
 * by the Kernel to every request.
 */
return static function (Router $router): void {
    // ── Storefront ────────────────────────────────────────────────
    $router->get('/', [HomeController::class, 'index']);

    // ── Health / readiness probes (Req 15.4) ──────────────────────
    $router->get('/healthz', [HealthController::class, 'live']);
    $router->get('/readyz', [HealthController::class, 'ready']);

    // ── Prometheus metrics scrape (Req 15.2) ──────────────────────
    $router->get('/metrics', [MetricsController::class, 'index']);

    // ── SEO: sitemap + robots (Req 20.3) ──────────────────────────
    $router->get('/sitemap.xml', [SeoController::class, 'sitemap']);
    $router->get('/robots.txt', [SeoController::class, 'robots']);

    // ── Guest-only auth (Req 2) ───────────────────────────────────
    $router->get('/register', [RegisterController::class, 'show'], ['guest']);
    $router->post('/register', [RegisterController::class, 'store'], ['guest', 'throttle:10,1']);

    $router->get('/login', [LoginController::class, 'show'], ['guest']);
    $router->post('/login', [LoginController::class, 'store'], ['guest', 'throttle:10,1']);

    $router->get('/forgot-password', [PasswordResetController::class, 'showForgot'], ['guest']);
    $router->post('/forgot-password', [PasswordResetController::class, 'sendResetLink'], ['guest', 'throttle:5,1']);
    $router->get('/reset-password', [PasswordResetController::class, 'showReset'], ['guest']);
    $router->post('/reset-password', [PasswordResetController::class, 'reset'], ['guest', 'throttle:5,1']);

    // ── Email verification (Req 2.3) ──────────────────────────────
    $router->get('/verify-email', [EmailVerificationController::class, 'verify']);

    // ── Two-factor login challenge (pending session, Req 2.4) ─────
    $router->get('/2fa', [TwoFactorController::class, 'challenge']);
    $router->post('/2fa', [TwoFactorController::class, 'verify'], ['throttle:10,1']);

    // ── Authenticated account area ────────────────────────────────
    $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
    $router->post('/logout', [LoginController::class, 'logout'], ['auth']);

    $router->get('/2fa/setup', [TwoFactorController::class, 'setup'], ['auth']);
    $router->post('/2fa/confirm', [TwoFactorController::class, 'confirm'], ['auth']);
    $router->post('/2fa/disable', [TwoFactorController::class, 'disable'], ['auth']);

    $router->get('/account/sessions', [SessionController::class, 'index'], ['auth']);
    $router->post('/account/sessions/revoke', [SessionController::class, 'revoke'], ['auth']);
    $router->post('/account/sessions/revoke-others', [SessionController::class, 'revokeOthers'], ['auth']);

    // ── In-app notifications (Req 13.2) ───────────────────────────
    $router->get('/account/notifications', [NotificationController::class, 'index'], ['auth']);
    $router->post('/account/notifications/read', [NotificationController::class, 'markRead'], ['auth']);
    $router->post('/account/notifications/read-all', [NotificationController::class, 'markAllRead'], ['auth']);

    // One-click email unsubscribe via signed token — public (Req 13.3).
    $router->get('/unsubscribe/{token}', [UnsubscribeController::class, 'unsubscribe']);

    // ── Affiliate / referral program (Req 20.2) ───────────────────
    $router->get('/r/{code}', [ReferralController::class, 'land'], ['throttle:60,1']);
    $router->get('/account/affiliate', [AffiliateController::class, 'index'], ['auth']);
    $router->post('/account/affiliate/enroll', [AffiliateController::class, 'enroll'], ['auth', 'throttle:10,1']);

    // ── Privacy centre: consent, export, erasure (Req 14.8) ───────
    $router->get('/account/privacy', [PrivacyController::class, 'index'], ['auth']);
    $router->post('/account/privacy/consent', [PrivacyController::class, 'updateConsent'], ['auth']);
    $router->post('/account/privacy/export', [PrivacyController::class, 'requestExport'], ['auth', 'throttle:5,1']);
    $router->post('/account/privacy/erasure', [PrivacyController::class, 'requestErasure'], ['auth', 'throttle:5,1']);
    $router->get('/account/privacy/export/{token}', [PrivacyController::class, 'download'], ['auth']);

    // ── Developer: API key management (Req 19.2) ──────────────────
    $router->get('/account/api-keys', [AccountApiKeyController::class, 'index'], ['auth']);
    $router->post('/account/api-keys', [AccountApiKeyController::class, 'store'], ['auth', 'throttle:10,1']);
    $router->post('/account/api-keys/revoke', [AccountApiKeyController::class, 'revoke'], ['auth']);

    // ── Public catalog + search (Req 4 / 6) ───────────────────────
    $router->get('/products', [CatalogController::class, 'index']);
    $router->get('/search', [SearchController::class, 'index']);
    $router->get('/product/{slug}', [CatalogController::class, 'show']);

    // ── Reviews & wishlist (Req 7) ────────────────────────────────
    $router->post('/product/{id}/reviews', [ReviewController::class, 'store'], ['auth', 'throttle:20,1']);
    $router->post('/reviews/{id}/reply', [ReviewController::class, 'reply'], ['auth']);
    $router->post('/admin/reviews/{id}/moderate', [ReviewController::class, 'moderate'], ['auth', 'can:review.moderate']);

    $router->get('/account/wishlist', [WishlistController::class, 'index'], ['auth']);
    $router->post('/wishlist/toggle', [WishlistController::class, 'toggle']);

    // ── Cart & checkout (Req 8/9) ─────────────────────────────────
    $router->get('/cart', [CartController::class, 'index']);
    $router->post('/cart/add', [CartController::class, 'add']);
    $router->post('/cart/remove', [CartController::class, 'remove']);
    $router->get('/checkout', [CheckoutController::class, 'show'], ['auth']);
    $router->post('/checkout', [CheckoutController::class, 'process'], ['auth', 'throttle:20,1']);

    // ── Payment webhooks (signature-authenticated, CSRF-exempt) ───
    $router->post('/payments/{gateway}/webhook', [PaymentWebhookController::class, 'handle']);
    $router->get('/payments/offline/pay/{orderNumber}', [PaymentWebhookController::class, 'offlinePay'], ['auth']);

    // ── Orders & downloads (Req 8/10) ─────────────────────────────
    $router->get('/orders', [OrderController::class, 'index'], ['auth']);
    $router->get('/orders/{id}', [OrderController::class, 'show'], ['auth']);
    $router->get('/account/library', [OrderController::class, 'library'], ['auth']);

    // Secure downloads: mint a signed link, then redeem + stream (Req 10).
    $router->get('/downloads/{entitlement}', [DownloadController::class, 'request'], ['auth', 'throttle:60,1']);
    $router->get('/download/{token}', [DownloadController::class, 'serve'], ['auth']);

    // Public license verification API (Req 10.3).
    $router->get('/api/v1/licenses/verify', [LicenseController::class, 'verify'], ['throttle:60,1']);

    // ── Seller onboarding + console (Req 11) ──────────────────────
    $router->get('/seller/onboard', [SellerProfileController::class, 'onboardForm'], ['auth']);
    $router->post('/seller/onboard', [SellerProfileController::class, 'onboard'], ['auth']);
    $router->get('/seller/dashboard', [SellerDashboardController::class, 'index'], ['auth', 'can:product.create']);
    $router->get('/seller/profile', [SellerProfileController::class, 'show'], ['auth', 'can:product.create']);
    $router->post('/seller/kyc', [SellerProfileController::class, 'submitKyc'], ['auth', 'can:product.create']);
    $router->post('/seller/payout-method', [SellerProfileController::class, 'setPayoutMethod'], ['auth', 'can:product.create']);
    $router->get('/seller/payouts', [SellerPayoutController::class, 'index'], ['auth', 'can:payout.request']);
    $router->post('/seller/payouts', [SellerPayoutController::class, 'request'], ['auth', 'can:payout.request', 'seller.verified', 'throttle:20,1']);

    // ── Seller product management (Req 4 / 5) — selling requires KYC ─
    $router->get('/seller/products', [ProductController::class, 'index'], ['auth', 'can:product.create']);
    $router->get('/seller/products/create', [ProductController::class, 'create'], ['auth', 'can:product.create', 'seller.verified']);
    $router->post('/seller/products', [ProductController::class, 'store'], ['auth', 'can:product.create', 'seller.verified']);
    $router->get('/seller/products/{id}/edit', [ProductController::class, 'edit'], ['auth', 'can:product.update']);
    $router->put('/seller/products/{id}', [ProductController::class, 'update'], ['auth', 'can:product.update', 'seller.verified']);
    $router->post('/seller/products/{id}/versions', [ProductVersionController::class, 'store'], ['auth', 'can:product.update', 'seller.verified']);
    $router->post('/seller/products/{id}/screenshots', [ProductController::class, 'addScreenshot'], ['auth', 'can:product.update']);
    $router->post('/seller/products/{id}/screenshots/{fileId}/delete', [ProductController::class, 'deleteScreenshot'], ['auth', 'can:product.update']);
    $router->post('/seller/products/{id}/submit', [ProductController::class, 'submit'], ['auth', 'can:product.update', 'seller.verified']);
    $router->post('/seller/products/{id}/archive', [ProductController::class, 'archive'], ['auth', 'can:product.update']);

    // ── Finance: payouts + KYC review (Req 11) — requires MFA ─────
    $router->get('/finance/payouts', [FinancePayoutController::class, 'queue'], ['auth', 'mfa', 'can:payout.process']);
    $router->post('/finance/payouts/{id}/pay', [FinancePayoutController::class, 'pay'], ['auth', 'mfa', 'can:payout.process']);
    $router->post('/finance/payouts/{id}/reject', [FinancePayoutController::class, 'reject'], ['auth', 'mfa', 'can:payout.process']);
    $router->get('/finance/kyc', [FinanceKycController::class, 'queue'], ['auth', 'mfa', 'can:kyc.review']);
    $router->post('/finance/kyc/{id}/verify', [FinanceKycController::class, 'verify'], ['auth', 'mfa', 'can:kyc.review']);
    $router->post('/finance/kyc/{id}/reject', [FinanceKycController::class, 'reject'], ['auth', 'mfa', 'can:kyc.review']);

    // ── Admin moderation (Req 12.1) ───────────────────────────────
    $router->get('/admin/moderation', [ModerationController::class, 'queue'], ['auth', 'can:product.approve']);
    $router->post('/admin/moderation/{id}/approve', [ModerationController::class, 'approve'], ['auth', 'can:product.approve']);
    $router->post('/admin/moderation/{id}/reject', [ModerationController::class, 'reject'], ['auth', 'can:product.approve']);

    // ── Admin console (Req 12) — back-office requires MFA (Req 3.4) ─
    $router->get('/admin', [AdminDashboardController::class, 'index'], ['auth', 'mfa', 'can:report.view']);

    $router->get('/admin/users', [AdminUserController::class, 'index'], ['auth', 'mfa', 'can:user.view']);
    $router->post('/admin/users/{id}/suspend', [AdminUserController::class, 'suspend'], ['auth', 'mfa', 'can:user.suspend']);
    $router->post('/admin/users/{id}/activate', [AdminUserController::class, 'activate'], ['auth', 'mfa', 'can:user.suspend']);
    $router->post('/admin/users/{id}/roles/assign', [AdminUserController::class, 'assignRole'], ['auth', 'mfa', 'can:user.assign_role']);
    $router->post('/admin/users/{id}/roles/remove', [AdminUserController::class, 'removeRole'], ['auth', 'mfa', 'can:user.assign_role']);

    $router->get('/admin/categories', [AdminCategoryController::class, 'index'], ['auth', 'mfa', 'can:category.manage']);
    $router->post('/admin/categories', [AdminCategoryController::class, 'store'], ['auth', 'mfa', 'can:category.manage']);
    $router->post('/admin/categories/{id}/toggle', [AdminCategoryController::class, 'toggle'], ['auth', 'mfa', 'can:category.manage']);
    $router->post('/admin/categories/{id}/delete', [AdminCategoryController::class, 'delete'], ['auth', 'mfa', 'can:category.manage']);

    $router->get('/admin/coupons', [AdminCouponController::class, 'index'], ['auth', 'mfa', 'can:coupon.manage']);
    $router->post('/admin/coupons', [AdminCouponController::class, 'store'], ['auth', 'mfa', 'can:coupon.manage']);
    $router->post('/admin/coupons/{id}/toggle', [AdminCouponController::class, 'toggle'], ['auth', 'mfa', 'can:coupon.manage']);

    $router->get('/admin/disputes', [AdminDisputeController::class, 'index'], ['auth', 'mfa', 'can:dispute.handle']);
    $router->post('/admin/disputes/{id}/resolve', [AdminDisputeController::class, 'resolve'], ['auth', 'mfa', 'can:dispute.handle']);
    $router->post('/admin/disputes/{id}/reject', [AdminDisputeController::class, 'reject'], ['auth', 'mfa', 'can:dispute.handle']);
    $router->post('/admin/disputes/{id}/refund', [AdminDisputeController::class, 'refund'], ['auth', 'mfa', 'can:dispute.handle']);

    $router->get('/admin/settings', [AdminSettingsController::class, 'index'], ['auth', 'mfa', 'can:settings.manage']);
    $router->post('/admin/settings/flag', [AdminSettingsController::class, 'toggleFlag'], ['auth', 'mfa', 'can:feature_flag.manage']);
    $router->post('/admin/settings/set', [AdminSettingsController::class, 'setSetting'], ['auth', 'mfa', 'can:settings.manage']);

    $router->post('/admin/products/{id}/feature', [AdminProductController::class, 'feature'], ['auth', 'mfa', 'can:product.feature']);
    $router->post('/admin/products/{id}/suspend', [AdminProductController::class, 'suspend'], ['auth', 'mfa', 'can:product.suspend']);

    // ══ Public REST API v1 (Req 19) ═══════════════════════════════
    // Envelope: { data, meta } on success, { error:{code,message} } on error.

    $router->get('/api/v1/ping', static fn (Request $r): Response =>
        Response::json([
            'data' => ['pong' => true, 'version' => 'v1'],
            'meta' => ['request_id' => $r->attribute('request_id')],
        ]));

    // OpenAPI 3 specification (Req 19.3).
    $router->get('/api/v1/openapi.json', [ApiOpenApiController::class, 'spec']);

    // Public catalog reads (Req 19.1) — IP-throttled.
    $router->get('/api/v1/products', [ApiProductController::class, 'index'], ['throttle:120,1']);
    $router->get('/api/v1/products/{slug}', [ApiProductController::class, 'show'], ['throttle:120,1']);
    $router->get('/api/v1/categories', [ApiCategoryController::class, 'index'], ['throttle:120,1']);
    // Public license verification lives under the Phase 6 downloads block above.

    // Self-hosted analytics beacon (Req 20 / 16.3) — public, CSRF-exempt, throttled.
    $router->post('/api/v1/events', [ApiEventController::class, 'track'], ['throttle:120,1']);

    // Authenticated API — Bearer API key + scopes + per-key rate limit (Req 19.2).
    $router->get('/api/v1/me', [ApiMeController::class, 'show'], ['apikey']);
    $router->get('/api/v1/orders', [ApiOrderController::class, 'index'], ['apikey', 'scope:orders.read']);
    $router->get('/api/v1/orders/{orderNumber}', [ApiOrderController::class, 'show'], ['apikey', 'scope:orders.read']);

    // Outbound webhook subscription management (Req 19.4).
    $router->get('/api/v1/webhooks', [ApiWebhookController::class, 'index'], ['apikey', 'scope:webhooks.manage']);
    $router->post('/api/v1/webhooks', [ApiWebhookController::class, 'store'], ['apikey', 'scope:webhooks.manage']);
    $router->delete('/api/v1/webhooks/{id}', [ApiWebhookController::class, 'destroy'], ['apikey', 'scope:webhooks.manage']);
};
