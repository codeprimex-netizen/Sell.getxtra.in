<?php

declare(strict_types=1);

/**
 * Phase 11 tests: secrets providers, consent, GDPR data export + right-to-
 * erasure + retention purge, security-event logging, the strict CSP nonce,
 * and the global rate limiter. In-memory + no DB. Run: php tests/phase11.php
 */

use App\Application\Audit\AuditLogger;
use App\Application\Privacy\ConsentService;
use App\Application\Privacy\DataPrivacyService;
use App\Application\Security\SecurityEventService;
use App\Config\Config;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleGlobal;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Cache\RateLimiter;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Queue\ArrayQueueDriver;
use App\Infrastructure\Queue\Dispatcher;
use App\Infrastructure\Security\Secrets\ChainSecretProvider;
use App\Infrastructure\Security\Secrets\EnvSecretProvider;
use App\Infrastructure\Security\Secrets\FileSecretProvider;
use App\Infrastructure\Security\Secrets\SecretsManager;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use Tests\Fakes\InMemoryAuditLogRepository;
use Tests\Fakes\InMemoryConsentRepository;
use Tests\Fakes\InMemoryDataRequestRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryUserRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemoryPrivacy.php';

Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 11 security hardening & compliance tests ===\n";

// ── Secrets providers ──────────────────────────────────────────────
echo "\n-- Secrets --\n";
putenv('TEST_SECRET_ONE=env-value');
$_ENV['TEST_SECRET_ONE'] = 'env-value';
$env = new EnvSecretProvider();
$check('env provider reads a set var', $env->get('TEST_SECRET_ONE') === 'env-value');
$check('env provider has() is true for a set var', $env->has('TEST_SECRET_ONE'));
$check('env provider returns default for missing', $env->get('TEST_MISSING', 'fallback') === 'fallback');

$tmpSecrets = tempnam(sys_get_temp_dir(), 'sec');
file_put_contents($tmpSecrets, json_encode(['DB_PASSWORD' => 's3cr3t', 'API_SIGNING_KEY' => 'abc']));
$file = new FileSecretProvider($tmpSecrets);
$check('file provider reads JSON secret', $file->get('DB_PASSWORD') === 's3cr3t');
$check('file provider has() works', $file->has('API_SIGNING_KEY') && !$file->has('NOPE'));

$chain = new ChainSecretProvider($file, $env);
$check('chain prefers the file provider', $chain->get('DB_PASSWORD') === 's3cr3t');
$check('chain falls back to env', $chain->get('TEST_SECRET_ONE') === 'env-value');

$manager = new SecretsManager($file);
$check('manager require returns a present secret', $manager->require('DB_PASSWORD') === 's3cr3t');
$threw = false;
try {
    $manager->require('ABSENT');
} catch (\RuntimeException) {
    $threw = true;
}
$check('manager require throws for a missing secret', $threw);
@unlink($tmpSecrets);

// ── Consent ────────────────────────────────────────────────────────
echo "\n-- Consent --\n";
$consentRepo = new InMemoryConsentRepository();
$consent = new ConsentService($consentRepo);

$consent->grant(7, ConsentService::MARKETING_EMAIL, '1.2.3.4');
$check('grant sets consent', $consent->has(7, ConsentService::MARKETING_EMAIL));
$consent->withdraw(7, ConsentService::MARKETING_EMAIL);
$check('withdraw clears consent', !$consent->has(7, ConsentService::MARKETING_EMAIL));
$consent->apply(7, ConsentService::COOKIES, true);
$check('apply(true) grants', $consent->has(7, ConsentService::COOKIES));
$check('all() lists a user consents', count($consent->all(7)) === 2);
$threw = false;
try {
    $consent->grant(7, 'unknown_type');
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check('unknown consent type is rejected', $threw);

// ── Data export / erasure ──────────────────────────────────────────
echo "\n-- GDPR data rights --\n";
$users = new InMemoryUserRepository();
$uid = $users->create([
    'name' => 'Asha Verma', 'email' => 'asha@example.com', 'phone' => '+91-99999',
    'password_hash' => 'HASHVALUE', 'two_factor_secret' => 'SECRET', 'status' => 'active',
]);
$orders = new InMemoryOrderRepository();
$orders->create(
    ['order_number' => 'ORD-P1', 'buyer_id' => $uid, 'currency' => 'INR', 'subtotal' => 100.0,
     'discount' => 0.0, 'tax' => 18.0, 'total' => 118.0, 'status' => 'paid'],
    [['product_id' => 1, 'title_snapshot' => 'Thing', 'unit_price' => 100.0, 'commission' => 20.0, 'seller_earning' => 80.0, 'seller_id' => 2]],
);
$reqRepo = new InMemoryDataRequestRepository();
$driver = new ArrayQueueDriver();

$tmpRoot = sys_get_temp_dir() . '/getxtra_priv_' . uniqid();
$storage = new StorageManager();
$storage->register('private', new LocalStorage($tmpRoot . '/private', '', false));

$privacy = new DataPrivacyService($reqRepo, $consentRepo, $users, $orders, $storage, new Dispatcher($driver));

// Export request
$exportReq = $privacy->requestExport($uid);
$check('export request is created pending', ($exportReq['status'] ?? '') === 'pending' && !empty($exportReq['token']));
$check('export request enqueues a job', $driver->size('privacy') === 1);
$job = $driver->pop('privacy');
$check('queued job is privacy.export', $job !== null && $job->name === 'privacy.export');
if ($job !== null) {
    $driver->ack($job); // clear the lease so later assertions see a clean queue
}
$check('user now has a pending export', $reqRepo->hasPending($uid, 'export'));

// De-duplication: a second request does not create a new row.
$privacy->requestExport($uid);
$check('duplicate export request is de-duplicated', count($reqRepo->forUser($uid)) === 1);

// Fulfil export
$ok = $privacy->fulfillExport((int) $exportReq['id']);
$check('fulfillExport succeeds', $ok === true);
$exportJson = $privacy->getExportByToken((string) $exportReq['token'], $uid);
$check('export is downloadable by token', is_string($exportJson) && $exportJson !== '');
$decoded = json_decode((string) $exportJson, true);
$check('export contains the user record', ($decoded['user']['email'] ?? '') === 'asha@example.com');
$check('export contains orders', isset($decoded['orders'][0]['order_number']));
$check('export omits password hash', !isset($decoded['user']['password_hash']) && !isset($decoded['user']['two_factor_secret']));
$check('wrong user cannot download the export', $privacy->getExportByToken((string) $exportReq['token'], 999) === null);

// Erasure request + fulfilment
$erasureReq = $privacy->requestErasure($uid);
$check('erasure request enqueues a job', $driver->pop('privacy')?->name === 'privacy.erasure');
$privacy->fulfillErasure((int) $erasureReq['id']);
$erased = $users->findById($uid);
$check('erasure anonymizes the name', ($erased['name'] ?? '') === 'Deleted User');
$check('erasure rewrites the email', ($erased['email'] ?? '') === 'deleted+' . $uid . '@deleted.invalid');
$check('erasure nulls the phone', array_key_exists('phone', (array) $erased) && $erased['phone'] === null);
$check('erasure withdraws all consent', !$consent->has($uid, ConsentService::COOKIES));

// Retention purge
$reqRepo->rows[(int) $exportReq['id']]['completed_at'] = date('Y-m-d H:i:s', time() - 30 * 86400);
$exportKey = (string) $reqRepo->rows[(int) $exportReq['id']]['download_key'];
$check('export artifact exists before purge', $storage->private()->exists($exportKey));
$purged = $privacy->purgeExpiredExports(7);
$check('retention purges the expired export', $purged === 1);
$check('purged artifact is deleted', !$storage->private()->exists($exportKey));
$check('download key is cleared after purge', $reqRepo->rows[(int) $exportReq['id']]['download_key'] === null);

// ── Security events ────────────────────────────────────────────────
echo "\n-- Security events --\n";
$auditRepo = new InMemoryAuditLogRepository();
$logger = new Logger($tmpRoot . '/sec.log', 'debug');
$security = new SecurityEventService(new AuditLogger($auditRepo), $logger);

$security->privilegeChanged(1, 7, 'admin', 'granted', '10.0.0.1');
$check('privilege change is audited', $auditRepo->countAction('security.privilege_change') === 1);
$security->suspiciousLogin(7, 'new_device', '10.0.0.1');
$check('suspicious login is audited', $auditRepo->countAction('security.suspicious_login') === 1);
$security->massDownload(7, 120, 5, '10.0.0.1');
$row = end($auditRepo->rows);
$check('mass download records the count', $auditRepo->countAction('security.mass_download') === 1 && ($row['after']['count'] ?? 0) === 120);

// ── Strict CSP nonce ───────────────────────────────────────────────
echo "\n-- CSP / security headers --\n";
$sh = new SecurityHeaders();
$req = new Request('GET', '/', [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '127.0.0.1']);
$capturedNonce = null;
$res = $sh->handle($req, static function (Request $r) use (&$capturedNonce): Response {
    $capturedNonce = $r->attribute('csp_nonce');
    return Response::html('ok');
});
$csp = $res->headers()['Content-Security-Policy'] ?? '';
$check('a CSP nonce is attached to the request', is_string($capturedNonce) && $capturedNonce !== '');
$check('CSP script-src uses the nonce', str_contains($csp, "script-src 'self' 'nonce-{$capturedNonce}'"));
$check('CSP script-src has no unsafe-inline', !str_contains($csp, "script-src 'self' 'unsafe-inline'"));
$check('CSP sets object-src none', str_contains($csp, "object-src 'none'"));
$check('HSTS header is present', str_contains($res->headers()['Strict-Transport-Security'] ?? '', 'max-age='));
$check('nonce differs per request', ($sh->handle($req, static fn (Request $r): Response => Response::html('x'))->headers()['Content-Security-Policy'] ?? '') !== $csp);

// ── Global rate limit ──────────────────────────────────────────────
echo "\n-- Global rate limit --\n";
Config::set('security.rate_limit_enabled', true);
Config::set('security.global_rate_limit', 2);
$limiter = new RateLimiter($tmpRoot . '/rl');
$throttle = new ThrottleGlobal($limiter);
$next = static fn (Request $r): Response => Response::html('ok');
$s1 = $throttle->handle($req, $next)->status();
$s2 = $throttle->handle($req, $next)->status();
$s3 = $throttle->handle($req, $next)->status();
$check('first two requests pass', $s1 === 200 && $s2 === 200);
$check('third request over the limit is 429', $s3 === 429);

Config::set('security.rate_limit_enabled', false);
$check('disabling the limiter lets requests through',
    (new ThrottleGlobal($limiter))->handle($req, $next)->status() === 200);

// ── Summary ────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "OK — all Phase 11 assertions passed.\n";
    exit(0);
}
echo "FAILED — {$failures} assertion(s) failed.\n";
exit(1);
