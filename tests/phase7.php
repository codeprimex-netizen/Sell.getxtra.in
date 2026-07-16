<?php

declare(strict_types=1);

/**
 * Phase 7 tests: seller onboarding + KYC, wallet balances over the ledger,
 * payout request/reserve/pay/reject, balance clearing after the refund
 * window, and dashboard conversion. In-memory + no DB.
 * Run: php tests/phase7.php
 */

use App\Application\Audit\AuditLogger;
use App\Application\Commerce\LedgerService;
use App\Application\Identity\AccessControl;
use App\Application\Seller\PayoutService;
use App\Application\Seller\SellerDashboardService;
use App\Application\Seller\SellerException;
use App\Application\Seller\SellerProfileService;
use App\Application\Seller\SellerWalletService;
use App\Jobs\ClearSellerBalance;
use App\Support\Security\Crypto;
use Tests\Fakes\InMemoryAuditLogRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryPayoutRepository;
use Tests\Fakes\InMemoryRoleRepository;
use Tests\Fakes\InMemorySellerProfileRepository;
use Tests\Fakes\InMemorySellerStatsRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemorySeller.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 7 seller console tests ===\n";

// ── Wiring ────────────────────────────────────────────────────────
$profiles = new InMemorySellerProfileRepository();
$roles = new InMemoryRoleRepository();
$payouts = new InMemoryPayoutRepository();
$audit = new AuditLogger(new InMemoryAuditLogRepository());
$access = new AccessControl($roles);
$crypto = new Crypto('base64:' . base64_encode(random_bytes(32)));

$sellerService = new SellerProfileService($profiles, $roles, $access, $crypto, $audit);

$ledgerRepo = new InMemoryLedgerRepository();
$ledger = new LedgerService($ledgerRepo);
$wallet = new SellerWalletService($ledgerRepo, $payouts);

$sellerId = 10;

// ── Onboarding + KYC ──────────────────────────────────────────────
$sellerService->becomeSeller($sellerId, 'Cool Store');
$check('become seller creates profile', $profiles->find($sellerId) !== null);
$check('become seller grants role', in_array('seller', $roles->rolesForUser($sellerId), true));
$check('new seller not verified', !$sellerService->isVerified($sellerId));

$sellerService->submitKyc($sellerId, 'PAN-ABC');
$check('KYC submitted -> pending', $profiles->find($sellerId)['kyc_status'] === 'pending');
$reSubmit = false;
try {
    $sellerService->submitKyc($sellerId, 'again');
} catch (SellerException $e) {
    $reSubmit = $e->errorCode === 'invalid_state';
}
$check('cannot resubmit pending KYC', $reSubmit);

$sellerService->setPayoutMethod($sellerId, 'upi', 'seller@upi');
$check('payout details stored encrypted', $crypto->decrypt((string) $profiles->find($sellerId)['payout_details_enc']) === 'seller@upi');

$check('appears in pending KYC queue', count($sellerService->pendingKyc()) === 1);
$sellerService->verifyKyc($sellerId, 1);
$check('KYC verified', $sellerService->isVerified($sellerId));

// ── Ledger-backed wallet ──────────────────────────────────────────
// Simulate two sales crediting seller pending earnings.
$ledger->recordSale(101, [['seller_id' => $sellerId, 'commission' => 200, 'seller_earning' => 800]], 'INR');
$ledger->recordSale(102, [['seller_id' => $sellerId, 'commission' => 100, 'seller_earning' => 400]], 'INR');
$w = $wallet->wallet($sellerId, 'INR');
$check('wallet pending reflects earnings', abs($w['pending'] - 1200.0) < 0.01);
$check('wallet cleared starts at zero', abs($w['cleared']) < 0.01);
$check('nothing available before clearing', abs($w['available']) < 0.01);

// ── Clearing job (pending -> cleared) ─────────────────────────────
$orders = new InMemoryOrderRepository();
$oldDate = date('Y-m-d H:i:s', time() - (30 * 86400));
$o1 = $orders->create(['buyer_id' => 5, 'order_number' => 'O1', 'currency' => 'INR', 'subtotal' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000, 'coupon_id' => null, 'status' => 'paid', 'idempotency_key' => 'a', 'created_at' => $oldDate],
    [['product_id' => 1, 'seller_id' => $sellerId, 'license_tier_id' => null, 'title_snapshot' => 'X', 'unit_price' => 1000, 'commission' => 200, 'seller_earning' => 800]]);
// Record its sale on the ledger so there is pending to clear.
$ledger->recordSale($o1, $orders->items($o1), 'INR');

$job = new ClearSellerBalance($orders, $ledger, 14);
$clearedCount = $job->run();
$check('clearing job cleared 1 order', $clearedCount === 1);
$after = $wallet->wallet($sellerId, 'INR');
$check('cleared balance increased by earning', abs($after['cleared'] - 800.0) < 0.01);
$check('clearing job is idempotent', $job->run() === 0);

// ── Payouts ───────────────────────────────────────────────────────
$belowMin = false;
try {
    $payoutService = new PayoutService($payouts, $sellerService, $wallet, $ledgerRepo, $audit);
    $payoutService->request($sellerId, 50, 'INR');
} catch (SellerException $e) {
    $belowMin = $e->errorCode === 'below_minimum';
}
$check('payout below minimum rejected', $belowMin);

$payoutService = new PayoutService($payouts, $sellerService, $wallet, $ledgerRepo, $audit);
$tooMuch = false;
try {
    $payoutService->request($sellerId, 5000, 'INR'); // only 800 cleared available
} catch (SellerException $e) {
    $tooMuch = $e->errorCode === 'insufficient_balance';
}
$check('payout over available rejected', $tooMuch);

$payoutId = $payoutService->request($sellerId, 500, 'INR', 'upi');
$check('payout requested', $payouts->findById($payoutId)['status'] === 'requested');
$check('requested amount is reserved', abs($wallet->wallet($sellerId, 'INR')['reserved'] - 500.0) < 0.01);
$check('available reduced by reservation', abs($wallet->wallet($sellerId, 'INR')['available'] - 300.0) < 0.01);

// KYC required for payouts.
$unverified = new InMemorySellerProfileRepository();
$unverified->create(99, 'New');
$svc2 = new SellerProfileService($unverified, $roles, $access, $crypto, $audit);
$payoutService2 = new PayoutService($payouts, $svc2, new SellerWalletService($ledgerRepo, $payouts), $ledgerRepo, $audit);
$kycReq = false;
try {
    $payoutService2->request(99, 200, 'INR');
} catch (SellerException $e) {
    $kycReq = $e->errorCode === 'kyc_required';
}
$check('payout requires KYC', $kycReq);

// Finance marks paid -> debits cleared ledger.
$payoutService->markPaid($payoutId, 1, 'TXN123');
$check('payout marked paid', $payouts->findById($payoutId)['status'] === 'paid');
$paidWallet = $wallet->wallet($sellerId, 'INR');
$check('paid payout debits cleared balance', abs($paidWallet['cleared'] - 300.0) < 0.01);
$check('no reservation after paid', abs($paidWallet['reserved']) < 0.01);

$doublePayFail = false;
try {
    $payoutService->markPaid($payoutId, 1);
} catch (SellerException $e) {
    $doublePayFail = $e->errorCode === 'invalid_state';
}
$check('cannot pay an already-paid payout', $doublePayFail);

// Reject flow releases reservation.
$p2 = $payoutService->request($sellerId, 200, 'INR');
$check('second payout reserves', abs($wallet->wallet($sellerId, 'INR')['reserved'] - 200.0) < 0.01);
$payoutService->reject($p2, 1, 'Invalid bank details');
$check('rejected payout releases reservation', abs($wallet->wallet($sellerId, 'INR')['reserved']) < 0.01);

// ── Dashboard ─────────────────────────────────────────────────────
$dashboard = new SellerDashboardService(new InMemorySellerStatsRepository(), $wallet);
$data = $dashboard->forSeller($sellerId, 'INR');
$check('dashboard conversion computed', abs($data['conversion'] - 5.0) < 0.01); // 5 units / 100 views
$check('dashboard includes wallet', isset($data['wallet']['available']));
$check('dashboard includes top products', count($data['top_products']) === 1);

echo "\n";
echo $failures === 0 ? "All Phase 7 checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
