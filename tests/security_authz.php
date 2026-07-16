<?php

declare(strict_types=1);

/**
 * Dynamic security tests (Req 24.3): XSS output encoding, CSRF enforcement,
 * authentication/authorization gates, API-key auth, injection-as-data, and
 * credential hashing — exercised through the real Kernel, Router, and
 * services. Complements the static SAST guard in tests/security.php.
 * Run: php tests/security_authz.php
 */

use App\Application\Identity\AccessControl;
use App\Bootstrap\App;
use App\Http\Request;
use App\Http\Session\ArraySessionStore;
use App\Http\Session\Session;
use App\Infrastructure\Auth\PasswordHasher;
use Tests\Fakes\InMemoryRoleRepository;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';

$app = (new App($basePath))->boot();
$kernel = $app->kernel();
$router = $app->container()->get(\App\Http\Router::class);

$make = static fn (string $method, string $path): Request => new Request($method, $path, [], [], [
    'REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1',
]);

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Security: XSS / CSRF / authz / injection ===\n";

// ── XSS: context-aware output encoding ─────────────────────────────
echo "\n-- XSS output encoding --\n";
$payload = '<script>alert("xss")</script>';
$escaped = e($payload);
$check('script tags are HTML-escaped', !str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;'));
$check('double quotes are escaped', e('a"b') === 'a&quot;b');
$check('single quotes are escaped', e("a'b") === 'a&#039;b');
$check('CSP forbids inline scripts', (function () use ($kernel, $make): bool {
    $csp = $kernel->handle($make('GET', '/'))->headers()['Content-Security-Policy'] ?? '';
    return str_contains($csp, "script-src 'self' 'nonce-") && !str_contains($csp, "script-src 'self' 'unsafe-inline'");
})());

// ── CSRF enforcement ───────────────────────────────────────────────
echo "\n-- CSRF --\n";
$check('state-changing POST without token is blocked (419)', $kernel->handle($make('POST', '/cart/add'))->status() === 419);
$check('login POST without token is blocked (419)', $kernel->handle($make('POST', '/login'))->status() === 419);

$session = new Session(new ArraySessionStore());
$session->start();
$token = $session->csrfToken();
$check('a CSRF token is issued per session', is_string($token) && strlen($token) >= 32);
$check('valid CSRF token verifies', $session->verifyCsrf($token) === true);
$check('forged CSRF token is rejected', $session->verifyCsrf('forged-token') === false);
$check('missing CSRF token is rejected', $session->verifyCsrf(null) === false);
// Webhooks are signature-authenticated, so CSRF-exempt (not 419) — verified by
// rejecting a bad signature with 400 instead.
$check('signature-authed webhook is CSRF-exempt', $kernel->handle($make('POST', '/payments/offline/webhook'))->status() === 400);

// ── Authentication gates ───────────────────────────────────────────
echo "\n-- Authentication --\n";
$redirectsToLogin = static function (string $path) use ($kernel, $make): bool {
    $r = $kernel->handle($make('GET', $path));
    return $r->status() === 302 && ($r->headers()['Location'] ?? '') === '/login';
};
$check('account area requires auth', $redirectsToLogin('/dashboard'));
$check('admin console requires auth', $redirectsToLogin('/admin'));
$check('finance area requires auth', $redirectsToLogin('/finance/payouts'));
$check('privacy centre requires auth', $redirectsToLogin('/account/privacy'));
$check('API without a key is 401 (not a browser redirect)', $kernel->handle($make('GET', '/api/v1/orders'))->status() === 401);

// ── Authorization (RBAC) ───────────────────────────────────────────
echo "\n-- Authorization (RBAC) --\n";
$roles = new InMemoryRoleRepository();
$access = new AccessControl($roles);
$buyer = 1;
$admin = 2;
$root = 3;
$roles->assignRoleByName($buyer, 'buyer');
$roles->assignRoleByName($admin, 'admin');
$roles->assignRoleByName($root, 'super_admin');

$check('buyer cannot approve products', !$access->can($buyer, 'product.approve'));
$check('buyer can view own orders', $access->can($buyer, 'order.view'));
$check('admin can approve products', $access->can($admin, 'product.approve'));
$check('admin cannot exceed granted scope', !$access->can($admin, 'ledger.adjust'));
$check('super_admin wildcard grants everything', $access->can($root, 'anything.at.all'));
$check('privilege is not inherited across users', !$access->can($buyer, 'user.suspend'));

// ── Injection handled as data ──────────────────────────────────────
echo "\n-- SQL injection treated as data --\n";
$inject = "nova' OR '1'='1";
$matched = $router->match($make('GET', '/product/' . rawurlencode($inject)));
$check('injection in a path param routes to the handler (opaque data)', $matched !== null);
$check('injection payload is captured verbatim as a bound parameter',
    rawurldecode((string) ($matched['params']['slug'] ?? '')) === $inject,
    (string) ($matched['params']['slug'] ?? 'null'));
// Static guarantee: repositories only use prepared statements (see tests/security.php).

// ── Credential hashing ─────────────────────────────────────────────
echo "\n-- Password hashing --\n";
$hasher = new PasswordHasher();
$hash = $hasher->hash('Str0ngPass!x');
$check('password is not stored in plaintext', $hash !== 'Str0ngPass!x');
$check('a strong one-way algorithm is used (bcrypt/argon)', (bool) preg_match('/^\$(2y|argon2)/i', $hash));
$check('correct password verifies', $hasher->verify('Str0ngPass!x', $hash));
$check('wrong password is rejected', !$hasher->verify('wrong', $hash));

echo "\n";
echo $failures === 0 ? "All security checks passed.\n" : "{$failures} security check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
