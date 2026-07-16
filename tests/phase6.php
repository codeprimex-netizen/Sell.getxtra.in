<?php

declare(strict_types=1);

/**
 * Phase 6 tests: signed download tokens, secure download authorization
 * (ownership / revoked / expired / limit / missing file), download-count
 * increment + audit, and license verification. In-memory + temp storage.
 * Run: php tests/phase6.php
 */

use App\Application\Audit\AuditLogger;
use App\Application\Download\DownloadException;
use App\Application\Download\DownloadService;
use App\Application\Download\DownloadTokenService;
use App\Application\Download\LicenseService;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use Tests\Fakes\InMemoryAuditLogRepository;
use Tests\Fakes\InMemoryEntitlementRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryProductVersionRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 6 secure download tests ===\n";

// ── Token service ─────────────────────────────────────────────────
$tokens = new DownloadTokenService('unit-test-secret');
$tok = $tokens->issue(42, 7, 300);
$claims = $tokens->verify($tok);
$check('token roundtrip returns claims', $claims !== null && $claims['entitlement_id'] === 42 && $claims['buyer_id'] === 7);
$check('tampered token rejected', $tokens->verify($tok . 'x') === null);
$check('malformed token rejected', $tokens->verify('garbage') === null);
$check('expired token rejected', $tokens->verify($tokens->issue(1, 1, -10)) === null);
$forged = new DownloadTokenService('different-secret');
$check('token signed with another key rejected', $forged->verify($tok) === null);

// ── Wiring ────────────────────────────────────────────────────────
$products = new InMemoryProductRepository();
$versions = new InMemoryProductVersionRepository();
$entitlements = new InMemoryEntitlementRepository();
$audit = new InMemoryAuditLogRepository();

$tmp = sys_get_temp_dir() . '/getxtra_dl_' . uniqid();
$storage = new StorageManager();
$private = new LocalStorage($tmp . '/private', '', false);
$storage->register('private', $private);

$downloads = new DownloadService($tokens, $entitlements, $versions, $products, $storage, new AuditLogger($audit));
$licenses = new LicenseService($entitlements, $products);

$buyerId = 700;
$p1 = $products->create(['seller_id' => 1, 'title' => 'Pro Kit', 'slug' => 'pro-kit', 'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 999, 'currency' => 'INR']);
$key = 'products/' . $p1 . '/versions/deliverable.zip';
$private->put($key, "PK\x05\x06" . str_repeat("\x00", 18));
$versions->create(['product_id' => $p1, 'version_number' => '1.0.0', 'storage_key' => $key, 'scan_status' => 'clean', 'is_current' => 1]);

$entId = $entitlements->create(['buyer_id' => $buyerId, 'product_id' => $p1, 'license_key' => 'AAAA-BBBB-CCCC-DDDD', 'status' => 'active', 'download_count' => 0, 'max_downloads' => null, 'expires_at' => null]);

// ── createLink ────────────────────────────────────────────────────
$link = $downloads->createLink($entId, $buyerId);
$check('createLink returns /download/ path', str_starts_with($link, '/download/'));

$notOwned = false;
try {
    $downloads->createLink($entId, 999);
} catch (DownloadException $e) {
    $notOwned = $e->errorCode === 'forbidden';
}
$check('createLink denies non-owner', $notOwned);

// ── resolve (happy path) ──────────────────────────────────────────
$token = substr($link, strlen('/download/'));
$deliverable = $downloads->resolve($token, $buyerId, '10.0.0.1', 'req-1');
$check('resolve returns deliverable', $deliverable->storageKey === $key);
$check('deliverable filename derived from slug+version', $deliverable->filename === 'pro-kit-v1.0.0.zip');
$check('download count incremented', (int) $entitlements->findById($entId)['download_count'] === 1);
$check('serve is audited', $audit->countAction('download.serve') === 1);

$downloads->resolve($token, $buyerId);
$check('second download increments again', (int) $entitlements->findById($entId)['download_count'] === 2);

// ── resolve denials ───────────────────────────────────────────────
$mismatch = false;
try {
    $downloads->resolve($token, 999); // token buyer=700, current user=999
} catch (DownloadException $e) {
    $mismatch = $e->errorCode === 'forbidden';
}
$check('resolve denies buyer/session mismatch', $mismatch);

$invalid = false;
try {
    $downloads->resolve('not-a-real-token', $buyerId);
} catch (DownloadException $e) {
    $invalid = $e->errorCode === 'invalid_token';
}
$check('resolve rejects invalid token', $invalid);
$check('denials are audited', $audit->countAction('download.denied') >= 1);

// Revoked entitlement.
$entitlements->revoke($entId);
$revoked = false;
try {
    $downloads->resolve($token, $buyerId);
} catch (DownloadException $e) {
    $revoked = $e->errorCode === 'revoked';
}
$check('revoked entitlement blocks download', $revoked);

// Download limit reached.
$limited = $entitlements->create(['buyer_id' => $buyerId, 'product_id' => $p1, 'license_key' => 'LIM-KEY', 'status' => 'active', 'download_count' => 3, 'max_downloads' => 3, 'expires_at' => null]);
// Mint the token directly to exercise resolve()'s own limit enforcement
// (createLink pre-authorizes and would reject earlier — defense in depth).
$limitTok = $tokens->issue($limited, $buyerId);
$limitHit = false;
try {
    $downloads->resolve($limitTok, $buyerId);
} catch (DownloadException $e) {
    $limitHit = $e->errorCode === 'limit_reached';
}
$check('download limit enforced', $limitHit);

// Expired entitlement.
$expired = $entitlements->create(['buyer_id' => $buyerId, 'product_id' => $p1, 'license_key' => 'EXP-KEY', 'status' => 'active', 'download_count' => 0, 'max_downloads' => null, 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
$expTok = $tokens->issue($expired, $buyerId);
$expHit = false;
try {
    $downloads->resolve($expTok, $buyerId);
} catch (DownloadException $e) {
    $expHit = $e->errorCode === 'expired';
}
$check('expired entitlement blocks download', $expHit);

// Missing file (product with no clean version).
$p2 = $products->create(['seller_id' => 1, 'title' => 'No File', 'slug' => 'no-file', 'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 10, 'currency' => 'INR']);
$entNoFile = $entitlements->create(['buyer_id' => $buyerId, 'product_id' => $p2, 'license_key' => 'NF-KEY', 'status' => 'active', 'download_count' => 0, 'max_downloads' => null, 'expires_at' => null]);
$nfTok = substr($downloads->createLink($entNoFile, $buyerId), strlen('/download/'));
$nfHit = false;
try {
    $downloads->resolve($nfTok, $buyerId);
} catch (DownloadException $e) {
    $nfHit = $e->errorCode === 'unavailable';
}
$check('missing deliverable yields unavailable', $nfHit);

// ── License verification ──────────────────────────────────────────
$check('license verify: active key valid', $licenses->verify('LIM-KEY')['valid'] === true);
$check('license verify: unknown key not found', $licenses->verify('NOPE')['status'] === 'not_found');
$check('license verify: empty invalid', $licenses->verify('')['status'] === 'invalid');
$entitlements->revoke($limited);
$check('license verify: revoked key not valid', $licenses->verify('LIM-KEY')['valid'] === false);

echo "\n";
echo $failures === 0 ? "All Phase 6 checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
