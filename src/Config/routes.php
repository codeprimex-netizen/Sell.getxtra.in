<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Account\DashboardController;
use App\Http\Controllers\Web\Account\SessionController;
use App\Http\Controllers\Web\Admin\ModerationController;
use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\LicenseController;
use App\Http\Controllers\Web\CartController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\CheckoutController;
use App\Http\Controllers\Web\DownloadController;
use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PaymentWebhookController;
use App\Http\Controllers\Web\ReviewController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\Seller\ProductController;
use App\Http\Controllers\Web\Seller\ProductVersionController;
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

    // ── Seller product management (Req 4 / 5) ─────────────────────
    $router->get('/seller/products', [ProductController::class, 'index'], ['auth', 'can:product.create']);
    $router->get('/seller/products/create', [ProductController::class, 'create'], ['auth', 'can:product.create']);
    $router->post('/seller/products', [ProductController::class, 'store'], ['auth', 'can:product.create']);
    $router->get('/seller/products/{id}/edit', [ProductController::class, 'edit'], ['auth', 'can:product.update']);
    $router->put('/seller/products/{id}', [ProductController::class, 'update'], ['auth', 'can:product.update']);
    $router->post('/seller/products/{id}/versions', [ProductVersionController::class, 'store'], ['auth', 'can:product.update']);
    $router->post('/seller/products/{id}/submit', [ProductController::class, 'submit'], ['auth', 'can:product.update']);
    $router->post('/seller/products/{id}/archive', [ProductController::class, 'archive'], ['auth', 'can:product.update']);

    // ── Admin moderation (Req 12.1) ───────────────────────────────
    $router->get('/admin/moderation', [ModerationController::class, 'queue'], ['auth', 'can:product.approve']);
    $router->post('/admin/moderation/{id}/approve', [ModerationController::class, 'approve'], ['auth', 'can:product.approve']);
    $router->post('/admin/moderation/{id}/reject', [ModerationController::class, 'reject'], ['auth', 'can:product.approve']);

    // ── API version banner (surface expands in Phase 10) ──────────
    $router->get('/api/v1/ping', static fn (Request $r): Response =>
        Response::json([
            'data' => ['pong' => true, 'version' => 'v1'],
            'meta' => ['request_id' => $r->attribute('request_id')],
        ]));
};
