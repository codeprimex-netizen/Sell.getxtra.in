<?php

declare(strict_types=1);

/**
 * Affiliate / referral program tests (Req 20.2): enrolment, click tracking +
 * last-click reattribution, signup attribution with self-referral / double
 * guards, first-purchase conversion with commission posted to the double-entry
 * ledger, funnel stats, and feature-flag gating. In-memory + no DB.
 * Run: php tests/affiliate.php
 */

use App\Application\Affiliate\AffiliateService;
use App\Application\Commerce\LedgerService;
use App\Config\Config;
use Tests\Fakes\InMemoryAffiliateRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryReferralRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemoryAffiliate.php';

Config::boot();
Config::set('affiliate.enabled', true);
Config::set('affiliate.default_rate', 10.0);

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

$make = static function () {
    $aff = new InMemoryAffiliateRepository();
    $ref = new InMemoryReferralRepository();
    $ledgerRepo = new InMemoryLedgerRepository();
    $svc = new AffiliateService($aff, $ref, new LedgerService($ledgerRepo), $ledgerRepo);
    return [$svc, $aff, $ref, $ledgerRepo];
};

echo "=== Affiliate / referral program tests ===\n";

// ── Enrolment ──────────────────────────────────────────────────────
echo "\n-- Enrolment --\n";
[$svc, $aff] = $make();
$a = $svc->enroll(100);
$check('enroll creates an affiliate', $a !== null && isset($a['code']));
$check('code is a 10-char uppercase token', (bool) preg_match('/^[A-F0-9]{10}$/', (string) $a['code']));
$again = $svc->enroll(100);
$check('enroll is idempotent (same code)', $again['code'] === $a['code']);
$check('default commission rate applied', (float) $a['commission_rate'] === 10.0);

// ── Click tracking ─────────────────────────────────────────────────
echo "\n-- Click tracking --\n";
[$svc, $aff, $ref] = $make();
$aRow = $svc->enroll(100);
$code = (string) $aRow['code'];
$check('unknown code is rejected', $svc->recordClick('NOPECODE00', 'v' . str_repeat('0', 31)) === false);
$vid = str_repeat('a', 32);
$check('valid click is recorded', $svc->recordClick($code, $vid) === true);
$check('click increments the counter', (int) $svc->forUser(100)['clicks'] === 1);
$check('a referral row is created (clicked)', $ref->findByVisitor($vid)['status'] === 'clicked');

// last-click reattribution to a different affiliate
$bRow = $svc->enroll(200);
$svc->recordClick((string) $bRow['code'], $vid);
$check('last-click reattributes the visitor', (int) $ref->findByVisitor($vid)['affiliate_id'] === (int) $bRow['id']);

// ── Signup attribution ─────────────────────────────────────────────
echo "\n-- Signup attribution --\n";
[$svc, $aff, $ref] = $make();
$aRow = $svc->enroll(100);
$code = (string) $aRow['code'];

// self-referral guard: affiliate owner registers via own link
$svc->recordClick($code, 'self' . str_repeat('0', 28));
$check('self-referral is not attributed', $svc->attributeSignup('self' . str_repeat('0', 28), 100) === false);

$vid = str_repeat('b', 32);
$svc->recordClick($code, $vid);
$check('signup is attributed', $svc->attributeSignup($vid, 300) === true);
$check('referral is marked signed_up', $ref->findByReferredUser(300)['status'] === 'signed_up');
$check('signup increments the counter', (int) $svc->forUser(100)['signups'] === 1);
$check('a user cannot be referred twice', $svc->attributeSignup($vid, 300) === false);
$check('missing click is not attributed', $svc->attributeSignup(str_repeat('z', 32), 400) === false);

// ── Conversion + commission ledgering ──────────────────────────────
echo "\n-- Conversion --\n";
[$svc, $aff, $ref, $ledgerRepo] = $make();
$aRow = $svc->enroll(100);
$vid = str_repeat('c', 32);
$svc->recordClick((string) $aRow['code'], $vid);
$svc->attributeSignup($vid, 300);

$commission = $svc->attributeConversion(300, 5001, 1000.00, 'INR');
$check('commission = 10% of subtotal', abs($commission - 100.0) < 0.001, (string) $commission);
$check('commission credited to affiliate (pending)',
    abs($ledgerRepo->balances($ledgerRepo->account('affiliate', 100, 'INR'))['pending'] - 100.0) < 0.01);
$check('referral marked converted with the order', $ref->findByReferredUser(300)['status'] === 'converted'
    && (int) $ref->findByReferredUser(300)['order_id'] === 5001);
$check('conversion increments the counter', (int) $svc->forUser(100)['conversions'] === 1);

// idempotent: same order does not double-pay
$check('conversion is one-per-referral (idempotent)', $svc->attributeConversion(300, 5001, 1000.00, 'INR') === 0.0);
$check('no double commission', abs($ledgerRepo->balances($ledgerRepo->account('affiliate', 100, 'INR'))['pending'] - 100.0) < 0.01);

// unattributed user earns nothing
$check('unattributed purchase earns no commission', $svc->attributeConversion(999, 6001, 500.0, 'INR') === 0.0);

// ── Stats ──────────────────────────────────────────────────────────
echo "\n-- Stats --\n";
$stats = $svc->stats(100);
$check('stats report enrolment', ($stats['enrolled'] ?? false) === true);
$check('stats expose the funnel', $stats['clicks'] === 1 && $stats['signups'] === 1 && $stats['conversions'] === 1);
$check('stats expose pending earnings', abs((float) $stats['pending'] - 100.0) < 0.01);
$check('stats for a non-affiliate report not-enrolled', ($svc->stats(777)['enrolled'] ?? true) === false);

// ── Feature flag ───────────────────────────────────────────────────
echo "\n-- Feature flag --\n";
Config::set('affiliate.enabled', false);
[$svc2] = $make();
$check('enroll is a no-op when disabled', $svc2->enroll(500) === null);
$check('click is ignored when disabled', $svc2->recordClick('ANY0000000', str_repeat('d', 32)) === false);
$check('conversion is skipped when disabled', $svc2->attributeConversion(300, 7001, 1000.0, 'INR') === 0.0);
Config::set('affiliate.enabled', true);

echo "\n";
echo $failures === 0 ? "OK — all affiliate assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
