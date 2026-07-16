<?php

declare(strict_types=1);

/**
 * HTTP-level checks for the auth routes, dispatched through the real Kernel
 * (global middleware included) without a live server or database. Verifies
 * routing, session/CSRF middleware, guest/auth guards, and view rendering.
 */

use App\Bootstrap\App;
use App\Http\Request;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$app = (new App($basePath))->boot();
$kernel = $app->kernel();

$make = static function (string $method, string $path, array $body = [], array $server = []): Request {
    return new Request($method, $path, [], $body, array_merge([
        'REQUEST_METHOD' => $method,
        'REQUEST_URI'    => $path,
        'REMOTE_ADDR'    => '127.0.0.1',
    ], $server));
};

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 2 HTTP tests ===\n";

// Guest pages render.
$res = $kernel->handle($make('GET', '/login'));
$check('GET /login renders form', $res->status() === 200 && str_contains($res->body(), 'name="_token"'), 'status=' . $res->status());

$res = $kernel->handle($make('GET', '/register'));
$check('GET /register renders form', $res->status() === 200 && str_contains($res->body(), 'Create your account'));

$res = $kernel->handle($make('GET', '/forgot-password'));
$check('GET /forgot-password renders', $res->status() === 200 && str_contains($res->body(), 'Reset your password'));

// Auth guard redirects unauthenticated users.
$res = $kernel->handle($make('GET', '/dashboard'));
$check('GET /dashboard redirects guests to /login', $res->status() === 302 && ($res->headers()['Location'] ?? '') === '/login');

// 2FA challenge without a pending session bounces to /login.
$res = $kernel->handle($make('GET', '/2fa'));
$check('GET /2fa without pending login -> /login', $res->status() === 302 && ($res->headers()['Location'] ?? '') === '/login');

// CSRF: POST without a token is rejected (419).
$res = $kernel->handle($make('POST', '/login', ['email' => 'x@y.z', 'password' => 'secret']));
$check('POST /login without CSRF token -> 419', $res->status() === 419);

// Home + security headers still intact.
$res = $kernel->handle($make('GET', '/'));
$check('GET / still 200 with session middleware', $res->status() === 200);
$check('security headers present', isset($res->headers()['Content-Security-Policy'], $res->headers()['X-Request-Id']));

// API 404 envelope unaffected.
$res = $kernel->handle($make('GET', '/api/v1/nope', [], ['HTTP_ACCEPT' => 'application/json']));
$body = json_decode($res->body(), true);
$check('unknown API route -> JSON 404', $res->status() === 404 && ($body['error']['code'] ?? '') === 'not_found');

echo "\n";
if ($failures === 0) {
    echo "All Phase 2 HTTP checks passed.\n";
    exit(0);
}
echo "{$failures} check(s) failed.\n";
exit(1);
