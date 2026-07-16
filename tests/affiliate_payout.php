<?php

declare(strict_types=1);

/**
 * Affiliate payout tests (Req 20.2): commission clearing (pending → cleared),
 * wallet available balance, payout request validation + reservation, source
 * isolation from seller payouts, finance disbursement debiting the affiliate
 * ledger, and reject releasing the reservation. In-memory + no DB.
 * Run: php tests/affiliate_payout.php
 */

use App\Application\Affiliate\AffiliatePayoutException;
use App\Application\Affiliate\AffiliatePayoutService;
use App\Application\Affiliate\AffiliateService;
use App\Application\Audit\AuditLogger;
use App\Application\Commerce\LedgerService;
use App\Application\Identity\AccessControl;
use App\Application\Seller\PayoutService;
use App\Application\Seller\SellerProfileService;
use App\Application\Seller\SellerWalletService;
use App\Config\Config;
use App\Support\Security\Crypto;
use Tests\Fakes\InMemoryAffiliateRepository;
use Tests\Fakes\InMemoryAuditLogRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryPayoutRepository;
use Tests\Fakes\InMemoryReferralRepository;
use Tests\Fakes\InMemoryRoleRepository;
use Tests\Fakes\InMemorySellerProfileRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemorySeller.php';
require __DIR__ . '/Fakes/InMemoryAffiliate.php';

Config::boot();
Config::set('affiliate.enabled', true);
Config::set('affiliate.default_rate', 10.0);
Config::set('affiliate.min_payout', 100.0);

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Affiliate payout tests ===\n";

// ── Wiring ─────────────────────────────────────────────────────────
$ledgerRepo = new InMemoryLedgerRepository();
$ledgerSvc = new LedgerService($ledgerRepo);
$affRepo = new InMemoryAffiliateRepository();
$refRepo = new InMemoryReferralRepository();
$payoutRepo = new InMemoryPayoutRepository();
$audit = new AuditLogger(new InMemoryAuditLogRepository());

$affiliates = new AffiliateService($affRepo, $refRepo, $ledgerSvc, $ledgerRepo);
$affPayout = new AffiliatePayoutService($payoutRepo, $ledgerRepo, $ledgerSvc, $affRepo, $refRepo, $audit);

$affUser = 100;
$buyer = 300;
$aff = $affiliates->enroll($affUser);
$vid = str_repeat('a', 32);
$affiliates->recordClick((string) $aff['code'], $vid);
$affiliates->attributeSignup($vid, $buyer);
$commission = $affiliates->attributeConversion($buyer, 5001, 1000.00, 'INR'); // 10% = 100

// ── Pending, pre-clearing ──────────────────────────────────────────
echo "\n-- Before clearing --\n";
$w = $affPayout->wallet($affUser);
$check('commission is pending', abs($w['pending'] - 100.0) < 0.01 && $commission === 100.0);
$check('nothing cleared yet', $w['cleared'] === 0.0);
$check('nothing available yet', $w['available'] === 0.0);

// ── Clearing (refund window elapsed) ───────────────────────────────
echo "\n-- Clearing --\n";
$future = date('Y-m-d H:i:s', time() + 86400);
$cleared = $affPayout->clearDueCommissions($future);
$check('one commission cleared', $cleared === 1);
$w = $affPayout->wallet($affUser);
$check('pending moved to cleared', $w['pending'] === 0.0 && abs($w['cleared'] - 100.0) < 0.01);
$check('now available to withdraw', abs($w['available'] - 100.0) < 0.01);
$check('clearing is idempotent', $affPayout->clearDueCommissions($future) === 0);

// ── Request validation ─────────────────────────────────────────────
echo "\n-- Request validation --\n";
$belowMin = false;
try {
    $affPayout->request($affUser, 50.0);
} catch (AffiliatePayoutException) {
    $belowMin = true;
}
$check('below-minimum request is rejected', $belowMin);

$overBalance = false;
try {
    $affPayout->request($affUser, 500.0);
} catch (AffiliatePayoutException) {
    $overBalance = true;
}
$check('over-available request is rejected', $overBalance);

// ── Request + reservation ──────────────────────────────────────────
echo "\n-- Request --\n";
$payoutId = $affPayout->request($affUser, 100.0);
$check('payout request created', $payoutId > 0);
$check('payout row is tagged source=affiliate', ($payoutRepo->findById($payoutId)['source'] ?? '') === 'affiliate');
$w = $affPayout->wallet($affUser);
$check('funds reserved after request', abs($w['reserved'] - 100.0) < 0.01 && $w['available'] === 0.0);
$check('seller reservation is isolated from affiliate', $payoutRepo->reservedAmount($affUser, 'seller') === 0.0);
$check('affiliate reservation is tracked', abs($payoutRepo->reservedAmount($affUser, 'affiliate') - 100.0) < 0.01);

// ── Finance processing (shared rails) ──────────────────────────────
echo "\n-- Finance processing --\n";
$profiles = new InMemorySellerProfileRepository();
$roles = new InMemoryRoleRepository();
$access = new AccessControl($roles);
$crypto = new Crypto('base64:' . base64_encode(random_bytes(32)));
$sellerSvc = new SellerProfileService($profiles, $roles, $access, $crypto, $audit);
$wallet = new SellerWalletService($ledgerRepo, $payoutRepo);
$finance = new PayoutService($payoutRepo, $sellerSvc, $wallet, $ledgerRepo, $audit);

// Reject first: releases the reservation.
$finance->reject($payoutId, 1, 'test release');
$w = $affPayout->wallet($affUser);
$check('reject releases the reservation', $w['reserved'] === 0.0 && abs($w['available'] - 100.0) < 0.01);
$check('affiliate ledger untouched by reject', abs($w['cleared'] - 100.0) < 0.01);

// Request again and pay it out.
$payoutId2 = $affPayout->request($affUser, 100.0);
$finance->markPaid($payoutId2, 1, 'AFF-REF-1');
$w = $affPayout->wallet($affUser);
$check('paid payout debits the affiliate cleared balance', abs($w['cleared'] - 0.0) < 0.01);
$check('no funds reserved after payment', $w['reserved'] === 0.0);
$check('payout marked paid with gateway ref', ($payoutRepo->findById($payoutId2)['status'] ?? '') === 'paid'
    && ($payoutRepo->findById($payoutId2)['gateway_ref'] ?? '') === 'AFF-REF-1');

echo "\n";
echo $failures === 0 ? "OK — all affiliate payout assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
