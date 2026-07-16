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
$check('POST /seller/products/1/screenshots blocked without CSRF (419)', $kernel->handle($make('POST', '/seller/products/1/screenshots'))->status() === 419);
$check('POST /seller/products/1/screenshots/2/delete blocked without CSRF (419)', $kernel->handle($make('POST', '/seller/products/1/screenshots/2/delete'))->status() === 419);

// Storefront home renders through the layout and degrades gracefully w/o a DB.
$homeRes = $kernel->handle($make('GET', '/'));
$check('GET / renders the storefront home (200, degraded-safe)', $homeRes->status() === 200);
$check('home uses the shared layout + storefront content',
    str_contains($homeRes->body(), '<html') && str_contains($homeRes->body(), 'Featured products')
    && str_contains($homeRes->body(), 'href="/products"'));

// Phase 4 route wiring + guards (no DB access on these paths).
$check('GET /account/wishlist requires auth', $redirectsToLogin($make('GET', '/account/wishlist')));
$check('POST /wishlist/toggle blocked without CSRF (419)', $kernel->handle($make('POST', '/wishlist/toggle'))->status() === 419);
$check('POST /product/1/reviews blocked without CSRF (419)', $kernel->handle($make('POST', '/product/1/reviews'))->status() === 419);
$check('POST /admin/reviews/1/moderate blocked without CSRF (419)', $kernel->handle($make('POST', '/admin/reviews/1/moderate'))->status() === 419);

// Phase 5 route wiring + guards.
$check('GET /checkout requires auth', $redirectsToLogin($make('GET', '/checkout')));
$check('GET /orders requires auth', $redirectsToLogin($make('GET', '/orders')));
$check('GET /account/library requires auth', $redirectsToLogin($make('GET', '/account/library')));
$check('GET /orders/1/invoice requires auth', $redirectsToLogin($make('GET', '/orders/1/invoice')));
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

// Phase 9: notification centre requires auth; unsubscribe is public.
$check('GET /account/notifications requires auth', $redirectsToLogin($make('GET', '/account/notifications')));
$check('POST /account/notifications/read blocked without CSRF (419)', $kernel->handle($make('POST', '/account/notifications/read'))->status() === 419);
$unsub = $kernel->handle($make('GET', '/unsubscribe/sometoken'));
$check('GET /unsubscribe/{token} is public (not a login redirect)',
    !($unsub->status() === 302 && ($unsub->headers()['Location'] ?? '') === '/login'));

// Phase 10: public API is open; API-key endpoints reject anonymous callers with 401.
$check('GET /api/v1/openapi.json serves the spec (200)', $kernel->handle($make('GET', '/api/v1/openapi.json'))->status() === 200);
$check('GET /api/v1/products is public (not 401)', $kernel->handle($make('GET', '/api/v1/products'))->status() !== 401);
$check('GET /api/v1/me without key is 401', $kernel->handle($make('GET', '/api/v1/me'))->status() === 401);
$check('GET /api/v1/orders without key is 401', $kernel->handle($make('GET', '/api/v1/orders'))->status() === 401);
$check('GET /api/v1/webhooks without key is 401', $kernel->handle($make('GET', '/api/v1/webhooks'))->status() === 401);
// /api/ is CSRF-exempt, so an anonymous POST reaches the apikey guard -> 401 (not 419).
$check('POST /api/v1/webhooks without key is 401 (CSRF-exempt)', $kernel->handle($make('POST', '/api/v1/webhooks'))->status() === 401);
$check('GET /account/api-keys requires auth', $redirectsToLogin($make('GET', '/account/api-keys')));

// Phase 11: privacy centre requires auth; hardened response headers on public pages.
$check('GET /account/privacy requires auth', $redirectsToLogin($make('GET', '/account/privacy')));
$check('POST /account/privacy/export blocked without CSRF (419)', $kernel->handle($make('POST', '/account/privacy/export'))->status() === 419);
$check('GET /account/privacy/export/{token} requires auth', $redirectsToLogin($make('GET', '/account/privacy/export/sometoken')));

$home = $kernel->handle($make('GET', '/'));
$csp = $home->headers()['Content-Security-Policy'] ?? '';
$check('home response sets a nonce-based CSP', str_contains($csp, "script-src 'self' 'nonce-") && str_contains($csp, "object-src 'none'"));
$check('home response sets HSTS', str_contains($home->headers()['Strict-Transport-Security'] ?? '', 'max-age='));
$check('home response echoes a request id', ($home->headers()['X-Request-Id'] ?? '') !== '');
$check('home response echoes a traceparent', str_starts_with($home->headers()['traceparent'] ?? '', '00-'));

// Phase 12: observability endpoints.
$live = $kernel->handle($make('GET', '/healthz'));
$check('GET /healthz is 200 (liveness)', $live->status() === 200 && str_contains($live->body(), '"status":"ok"'));
$ready = $kernel->handle($make('GET', '/readyz'));
$check('GET /readyz reports dependency checks', str_contains($ready->body(), 'database') && in_array($ready->status(), [200, 503], true));
$metricsRes = $kernel->handle($make('GET', '/metrics'));
$check('GET /metrics returns Prometheus text', $metricsRes->status() === 200
    && str_contains($metricsRes->headers()['Content-Type'] ?? '', 'text/plain')
    && str_contains($metricsRes->body(), 'queue_depth'));

// Phase 16: SEO, localization, analytics beacon.
$robots = $kernel->handle($make('GET', '/robots.txt'));
$check('GET /robots.txt is 200 text with a Sitemap directive',
    $robots->status() === 200 && str_contains($robots->body(), 'Sitemap:'));
$check('GET /sitemap.xml route is wired (not 404)', $kernel->handle($make('GET', '/sitemap.xml'))->status() !== 404);
$check('layout renders default locale', str_contains($kernel->handle($make('GET', '/login'))->body(), 'lang="en"'));
$loginHi = new Request('GET', '/login', ['lang' => 'hi'], [], [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/login?lang=hi', 'REMOTE_ADDR' => '127.0.0.1',
]);
$check('layout honours ?lang=hi (localization end-to-end)', str_contains($kernel->handle($loginHi)->body(), 'lang="hi"'));
$evt = $kernel->handle($make('POST', '/api/v1/events'));
$check('POST /api/v1/events is CSRF-exempt and validates (422)', $evt->status() === 422);

// Affiliate / referral program (Req 20.2).
$check('GET /r/{code} referral landing is routed (not 404)', $kernel->handle($make('GET', '/r/ABCDEF0123'))->status() !== 404);
$check('GET /account/affiliate requires auth', $redirectsToLogin($make('GET', '/account/affiliate')));
$check('POST /account/affiliate/enroll blocked without CSRF (419)', $kernel->handle($make('POST', '/account/affiliate/enroll'))->status() === 419);
$check('POST /account/affiliate/payout blocked without CSRF (419)', $kernel->handle($make('POST', '/account/affiliate/payout'))->status() === 419);

echo "\n";
echo $failures === 0 ? "All Phase 3 HTTP checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
