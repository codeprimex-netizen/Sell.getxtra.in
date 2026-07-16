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

echo "\n";
echo $failures === 0 ? "All Phase 3 HTTP checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
