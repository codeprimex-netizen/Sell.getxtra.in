<?php

declare(strict_types=1);

/**
 * Non-blocking bootstrap smoke test. Boots the app and dispatches synthetic
 * requests through the Kernel (no live server needed). Prints a summary and
 * exits non-zero on any failure.
 */

use App\Bootstrap\App;
use App\Http\Request;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$app = (new App($basePath))->boot();
$kernel = $app->kernel();

/** Build a synthetic request. */
$make = static function (string $method, string $path, array $server = []): Request {
    return new Request($method, $path, [], [], array_merge([
        'REQUEST_METHOD' => $method,
        'REQUEST_URI'    => $path,
        'REMOTE_ADDR'    => '127.0.0.1',
    ], $server));
};

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    $status = $ok ? 'PASS' : 'FAIL';
    echo sprintf("  [%s] %s%s\n", $status, $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Bootstrap smoke test ===\n";

// 1. Home page.
$res = $kernel->handle($make('GET', '/'));
$check('GET / returns 200 HTML', $res->status() === 200 && str_contains($res->body(), 'Sell.getxtra.in'), 'status=' . $res->status());
$check('GET / has X-Request-Id header', isset($res->headers()['X-Request-Id']));
$check('GET / has CSP header', isset($res->headers()['Content-Security-Policy']));

// 2. Liveness probe (no DB needed).
$res = $kernel->handle($make('GET', '/healthz'));
$body = json_decode($res->body(), true);
$check('GET /healthz returns 200 JSON', $res->status() === 200 && ($body['status'] ?? '') === 'ok');

// 3. API ping.
$res = $kernel->handle($make('GET', '/api/v1/ping', ['HTTP_ACCEPT' => 'application/json']));
$body = json_decode($res->body(), true);
$check('GET /api/v1/ping returns pong', ($body['data']['pong'] ?? false) === true);

// 4. HTML 404.
$res = $kernel->handle($make('GET', '/no-such-page'));
$check('Unknown web route returns 404 HTML', $res->status() === 404 && str_contains($res->body(), '404'));

// 5. JSON 404 for API namespace.
$res = $kernel->handle($make('GET', '/api/v1/missing', ['HTTP_ACCEPT' => 'application/json']));
$body = json_decode($res->body(), true);
$check('Unknown API route returns 404 JSON error', $res->status() === 404 && ($body['error']['code'] ?? '') === 'not_found');

// 6. Helpers.
$check('slugify() works', slugify('Hello World! Pro') === 'hello-world-pro');
$check('money() works', money(1999.5, 'INR') === '₹1,999.50');
$check('e() escapes', e('<b>&"</b>') === '&lt;b&gt;&amp;&quot;&lt;/b&gt;');

// 7. Container autowiring resolves controllers.
$check(
    'Container resolves HomeController',
    app(\App\Http\Controllers\Web\HomeController::class) instanceof \App\Http\Controllers\Web\HomeController
);

echo "\n";
if ($failures === 0) {
    echo "All smoke checks passed.\n";
    exit(0);
}
echo "{$failures} check(s) failed.\n";
exit(1);
