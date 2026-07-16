<?php

declare(strict_types=1);

/**
 * HTTP checks for Phase 3 route wiring + access guards, dispatched through
 * the real Kernel. Protected routes redirect (auth/permission) before any
 * database access, so these run without a database.
 */

use App\Bootstrap\App;
use App\Http\Request;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$app = (new App($basePath))->boot();
$kernel = $app->kernel();

$make = static fn (string $method, string $path): Request => new Request($method, $path, [], [], [
    'REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1',
]);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo sprintf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 3 HTTP route/guard tests ===\n";

$redirectsToLogin = static function (Request $r) use ($kernel): bool {
    $res = $kernel->handle($r);
    return $res->status() === 302 && ($res->headers()['Location'] ?? '') === '/login';
};

$check('GET /seller/products requires auth', $redirectsToLogin($make('GET', '/seller/products')));
$check('GET /seller/products/create requires auth', $redirectsToLogin($make('GET', '/seller/products/create')));
$check('GET /admin/moderation requires auth', $redirectsToLogin($make('GET', '/admin/moderation')));
// POST hits global CSRF middleware (419) before the auth guard — still blocked.
$check('POST /seller/products blocked without CSRF (419)', $kernel->handle($make('POST', '/seller/products'))->status() === 419);

// Home still fine (no DB access).
$check('GET / still 200', $kernel->handle($make('GET', '/'))->status() === 200);

// Phase 4 route wiring + guards (no DB access on these paths).
$check('GET /account/wishlist requires auth', $redirectsToLogin($make('GET', '/account/wishlist')));
$check('POST /wishlist/toggle blocked without CSRF (419)', $kernel->handle($make('POST', '/wishlist/toggle'))->status() === 419);
$check('POST /product/1/reviews blocked without CSRF (419)', $kernel->handle($make('POST', '/product/1/reviews'))->status() === 419);
$check('POST /admin/reviews/1/moderate blocked without CSRF (419)', $kernel->handle($make('POST', '/admin/reviews/1/moderate'))->status() === 419);

// Phase 5 route wiring + guards.
$check('GET /checkout requires auth', $redirectsToLogin($make('GET', '/checkout')));
$check('GET /orders requires auth', $redirectsToLogin($make('GET', '/orders')));
$check('GET /account/library requires auth', $redirectsToLogin($make('GET', '/account/library')));
$check('POST /cart/add blocked without CSRF (419)', $kernel->handle($make('POST', '/cart/add'))->status() === 419);
$check('POST /checkout blocked without CSRF (419)', $kernel->handle($make('POST', '/checkout'))->status() === 419);
// Webhook is CSRF-exempt (signature-authenticated) -> not 419; bad signature -> 400.
$check('POST /payments/offline/webhook is CSRF-exempt, rejects bad signature (400)', $kernel->handle($make('POST', '/payments/offline/webhook'))->status() === 400);

// Phase 6: secure downloads require auth; license verify API is public.
$check('GET /downloads/1 requires auth', $redirectsToLogin($make('GET', '/downloads/1')));
$check('GET /download/{token} requires auth', $redirectsToLogin($make('GET', '/download/sometoken')));
$licenseRes = $kernel->handle($make('GET', '/api/v1/licenses/verify'));
$check('GET /api/v1/licenses/verify is public JSON (not a redirect)', $licenseRes->status() !== 302);

// Phase 8: admin console routes require auth (auth middleware runs before mfa/can).
$check('GET /admin requires auth', $redirectsToLogin($make('GET', '/admin')));
$check('GET /admin/users requires auth', $redirectsToLogin($make('GET', '/admin/users')));
$check('GET /admin/coupons requires auth', $redirectsToLogin($make('GET', '/admin/coupons')));
$check('GET /admin/disputes requires auth', $redirectsToLogin($make('GET', '/admin/disputes')));
$check('GET /admin/settings requires auth', $redirectsToLogin($make('GET', '/admin/settings')));
$check('POST /admin/users/1/suspend blocked without CSRF (419)', $kernel->handle($make('POST', '/admin/users/1/suspend'))->status() === 419);

// Phase 7: seller console + finance require auth (auth runs before can/mfa).
$check('GET /seller/dashboard requires auth', $redirectsToLogin($make('GET', '/seller/dashboard')));
$check('GET /seller/payouts requires auth', $redirectsToLogin($make('GET', '/seller/payouts')));
$check('GET /seller/onboard requires auth', $redirectsToLogin($make('GET', '/seller/onboard')));
$check('GET /finance/payouts requires auth', $redirectsToLogin($make('GET', '/finance/payouts')));
$check('GET /finance/kyc requires auth', $redirectsToLogin($make('GET', '/finance/kyc')));
$check('POST /seller/payouts blocked without CSRF (419)', $kernel->handle($make('POST', '/seller/payouts'))->status() === 419);

echo "\n";
echo $failures === 0 ? "All Phase 3 HTTP checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
