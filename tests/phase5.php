<?php

declare(strict_types=1);

/**
 * Phase 5 tests: Money, pricing, coupons, and the full commerce flow
 * (checkout -> idempotent order -> signed webhook -> entitlements + ledger),
 * webhook idempotency, failed payments, and refunds. In-memory + no DB.
 * Run: php tests/phase5.php
 */

use App\Application\Commerce\CheckoutService;
use App\Application\Commerce\CommerceException;
use App\Application\Commerce\CommissionPolicy;
use App\Application\Commerce\CouponService;
use App\Application\Commerce\EntitlementService;
use App\Application\Commerce\LedgerService;
use App\Application\Commerce\PaymentService;
use App\Application\Commerce\PricingService;
use App\Application\Commerce\RefundService;
use App\Domain\Commerce\Money;
use App\Infrastructure\Commerce\EntitlementPurchaseChecker;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Payment\OfflineGateway;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use App\Infrastructure\Payment\StripeGateway;
use Tests\Fakes\InMemoryCartRepository;
use Tests\Fakes\InMemoryCouponRepository;
use Tests\Fakes\InMemoryEntitlementRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryPaymentRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryRefundRepository;
use Tests\Fakes\InMemoryWebhookEventRepository;

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

echo "=== Phase 5 commerce tests ===\n";

// ── Money ─────────────────────────────────────────────────────────
$a = Money::fromDecimal(499.99, 'INR');
$check('Money fromDecimal precision', $a->minor === 49999);
$check('Money add', $a->add(Money::fromDecimal(0.01))->minor === 50000);
$check('Money percentage (18% of 1000)', Money::fromDecimal(1000)->percentage(18)->minor === 18000);
$check('Money subtract + clamp', Money::fromDecimal(100)->subtract(Money::fromDecimal(150))->clampNonNegative()->isZero());
$check('Money format', Money::fromDecimal(1770)->format() === '₹1,770.00');

// ── Pricing ───────────────────────────────────────────────────────
$pricing = new PricingService(18);
$items = [['unit_price' => 1000.0], ['unit_price' => 500.0]];
$totals = $pricing->price($items, 'INR');
$check('pricing subtotal', $totals['subtotal']->minor === 150000);
$check('pricing tax 18%', $totals['tax']->minor === 27000);
$check('pricing total = subtotal + tax', $totals['total']->minor === 177000);
$withDiscount = $pricing->price($items, 'INR', Money::fromDecimal(150));
$check('pricing applies discount before tax', $withDiscount['total']->minor === 159300); // (1500-150)*1.18

// ── Coupons ───────────────────────────────────────────────────────
$couponRepo = new InMemoryCouponRepository();
$couponRepo->byCode['WELCOME10'] = ['id' => 1, 'code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'scope' => 'all', 'scope_ref' => null, 'min_order' => 100, 'max_uses' => 1000, 'used_count' => 0, 'per_user_limit' => null, 'starts_at' => null, 'expires_at' => null, 'is_active' => 1];
$couponRepo->byCode['BIGMIN'] = ['id' => 2, 'code' => 'BIGMIN', 'type' => 'fixed', 'value' => 50, 'scope' => 'all', 'scope_ref' => null, 'min_order' => 5000, 'max_uses' => null, 'used_count' => 0, 'per_user_limit' => null, 'starts_at' => null, 'expires_at' => null, 'is_active' => 1];
$coupons = new CouponService($couponRepo);
$applied = $coupons->apply('WELCOME10', Money::fromDecimal(1500), 1, $items);
$check('percent coupon discount (10% of 1500)', $applied['discount']->minor === 15000);

$minFail = false;
try {
    $coupons->apply('BIGMIN', Money::fromDecimal(1500), 1, $items);
} catch (CommerceException $e) {
    $minFail = $e->errorCode === 'invalid_coupon';
}
$check('coupon min_order enforced', $minFail);

$badCode = false;
try {
    $coupons->apply('NOPE', Money::fromDecimal(1500), 1, $items);
} catch (CommerceException $e) {
    $badCode = true;
}
$check('unknown coupon rejected', $badCode);

// ── Checkout flow ─────────────────────────────────────────────────
$products = new InMemoryProductRepository();
$p1 = $products->create(['seller_id' => 10, 'title' => 'Pro Kit', 'slug' => 'pro-kit', 'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 1000, 'currency' => 'INR', 'sales_count' => 0]);
$p2 = $products->create(['seller_id' => 11, 'title' => 'Lite Kit', 'slug' => 'lite-kit', 'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 500, 'currency' => 'INR', 'sales_count' => 0]);

$carts = new InMemoryCartRepository();
$orders = new InMemoryOrderRepository();
$paymentsRepo = new InMemoryPaymentRepository();
$gateways = new PaymentGatewayRegistry();
$gateways->register(new OfflineGateway('offline-dev-secret'));

$buyerId = 900;
$cartId = $carts->createForUser($buyerId, 'INR');
foreach ([[$p1, 10, 'Pro Kit', 1000.0], [$p2, 11, 'Lite Kit', 500.0]] as [$pid, $sid, $title, $price]) {
    $carts->addItem($cartId, $pid, null, $price);
    $carts->setLineMeta($cartId, $pid, [
        'product_id' => $pid, 'seller_id' => $sid, 'title' => $title, 'unit_price' => $price,
        'status' => 'approved', 'scan_status' => 'clean', 'currency' => 'INR', 'license_tier_id' => null,
    ]);
}

$checkout = new CheckoutService(
    $carts, $products, $orders, $paymentsRepo,
    $coupons, $pricing, new CommissionPolicy(20), $gateways,
);

$result = $checkout->checkout($buyerId, $cartId, null, 'idem-key-1', 'offline');
$order = $result['order'];
$check('checkout creates pending order', $order['status'] === 'pending');
$check('order total = 1770 (1500 + 18% tax)', (int) round((float) $order['total'] * 100) === 177000);
$check('payment record created', $paymentsRepo->findByOrder((int) $order['id']) !== null);
$check('cart cleared after checkout', $carts->count($cartId) === 0);

// Idempotency: same key returns the same order, no duplicate.
$again = $checkout->checkout($buyerId, $cartId, null, 'idem-key-1', 'offline');
$check('checkout idempotent on repeated key', (int) $again['order']['id'] === (int) $order['id'] && count($orders->orders) === 1);

// ── Webhook -> paid -> entitlements + ledger ──────────────────────
$entitlementsRepo = new InMemoryEntitlementRepository();
$ledgerRepo = new InMemoryLedgerRepository();
$webhookEvents = new InMemoryWebhookEventRepository();
$logger = new Logger('/tmp/getxtra_p5.log', 'error');

$entitlementService = new EntitlementService($entitlementsRepo, $products);
$ledgerService = new LedgerService($ledgerRepo);
$paymentService = new PaymentService(
    $gateways, $webhookEvents, $orders, $paymentsRepo, $couponRepo,
    $entitlementService, $ledgerService, $logger,
);

$offline = new OfflineGateway('offline-dev-secret');
$makeBody = static fn (string $num, string $status): string => json_encode([
    'event_id' => 'evt-' . $num . '-' . $status, 'order_number' => $num, 'gateway_ref' => 'off_ref_1', 'status' => $status,
]) ?: '{}';

$body = $makeBody((string) $order['order_number'], 'paid');
$ok = $paymentService->handleWebhook('offline', $body, $offline->signBody($body));
$check('webhook accepted (valid signature)', $ok === true);
$check('order marked paid', $orders->findById((int) $order['id'])['status'] === 'paid');
$check('entitlements granted (one per item)', count($entitlementsRepo->forBuyer($buyerId)) === 2);
$check('product sales incremented', (int) $products->findById($p1)['sales_count'] === 1);

$platformAcct = $ledgerRepo->account('platform', null, 'INR');
$seller10 = $ledgerRepo->account('seller', 10, 'INR');
$seller11 = $ledgerRepo->account('seller', 11, 'INR');
$check('platform commission credited (20% of 1500 = 300)', abs($ledgerRepo->balances($platformAcct)['balance'] - 300.0) < 0.01);
$check('seller 10 earning pending (800)', abs($ledgerRepo->balances($seller10)['pending'] - 800.0) < 0.01);
$check('seller 11 earning pending (400)', abs($ledgerRepo->balances($seller11)['pending'] - 400.0) < 0.01);

$purchaseChecker = new EntitlementPurchaseChecker($entitlementsRepo);
$check('purchase checker confirms ownership', $purchaseChecker->hasPurchased($buyerId, $p1));

// Bad signature rejected.
$check('webhook rejects bad signature', $paymentService->handleWebhook('offline', $body, 'wrong-sig') === false);

// Idempotent: same event again -> no double entitlements.
$paymentService->handleWebhook('offline', $body, $offline->signBody($body));
$check('duplicate webhook does not double-grant', count($entitlementsRepo->forBuyer($buyerId)) === 2);

// New event id but order already paid -> pending guard blocks reprocessing.
$body2 = $makeBody((string) $order['order_number'], 'paid');
$body2 = str_replace('evt-', 'evt2-', $body2);
$paymentService->handleWebhook('offline', $body2, $offline->signBody($body2));
$check('paid-order guard prevents reprocessing', count($entitlementsRepo->forBuyer($buyerId)) === 2 && abs($ledgerRepo->balances($platformAcct)['balance'] - 300.0) < 0.01);

// ── Failed payment (no entitlement) ───────────────────────────────
$cart2 = $carts->createForUser(901, 'INR');
$carts->addItem($cart2, $p1, null, 1000.0);
$carts->setLineMeta($cart2, $p1, ['product_id' => $p1, 'seller_id' => 10, 'title' => 'Pro Kit', 'unit_price' => 1000.0, 'status' => 'approved', 'scan_status' => 'clean', 'currency' => 'INR', 'license_tier_id' => null]);
$order2 = $checkout->checkout(901, $cart2, null, 'idem-key-2', 'offline')['order'];
$fbody = $makeBody((string) $order2['order_number'], 'failed');
$paymentService->handleWebhook('offline', $fbody, $offline->signBody($fbody));
$check('failed payment marks order failed', $orders->findById((int) $order2['id'])['status'] === 'failed');
$check('failed payment grants no entitlement', count($entitlementsRepo->forBuyer(901)) === 0);

// ── Stripe signature scheme ───────────────────────────────────────
$stripe = new StripeGateway('sk_test', 'whsec_test');
$stripeBody = '{"id":"evt_1","type":"checkout.session.completed","data":{"object":{"id":"cs_1","client_reference_id":"ORD-1"}}}';
$ts = (string) time();
$sig = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $stripeBody, 'whsec_test');
$check('stripe verifies valid signature', $stripe->verifyWebhookSignature($stripeBody, $sig));
$check('stripe rejects tampered signature', !$stripe->verifyWebhookSignature($stripeBody, 't=' . $ts . ',v1=deadbeef'));

// ── Refunds ───────────────────────────────────────────────────────
$refundRepo = new InMemoryRefundRepository();
$refundService = new RefundService($orders, $paymentsRepo, $refundRepo, $ledgerService, $entitlementService, $gateways);

// Full refund of the first order (1770) -> refunded + entitlements revoked + ledger reversed.
$refundService->refund((int) $order['id'], 1770.00, 'Customer request');
$check('full refund sets order refunded', $orders->findById((int) $order['id'])['status'] === 'refunded');
$check('full refund revokes ownership', !$purchaseChecker->hasPurchased($buyerId, $p1));
$check('full refund reverses platform commission', abs($ledgerRepo->balances($platformAcct)['balance']) < 0.01);
$check('full refund reverses seller pending', abs($ledgerRepo->balances($seller10)['pending']) < 0.01);

// Over-refund is rejected.
$overRefund = false;
try {
    $refundService->refund((int) $order['id'], 100.0);
} catch (CommerceException $e) {
    $overRefund = true; // already fully refunded / not refundable
}
$check('over-refund rejected', $overRefund);

// Partial refund on a fresh paid order.
$cart3 = $carts->createForUser(902, 'INR');
$carts->addItem($cart3, $p1, null, 1000.0);
$carts->setLineMeta($cart3, $p1, ['product_id' => $p1, 'seller_id' => 10, 'title' => 'Pro Kit', 'unit_price' => 1000.0, 'status' => 'approved', 'scan_status' => 'clean', 'currency' => 'INR', 'license_tier_id' => null]);
$order3 = $checkout->checkout(902, $cart3, null, 'idem-key-3', 'offline')['order']; // total 1180
$b3 = $makeBody((string) $order3['order_number'], 'paid');
$paymentService->handleWebhook('offline', $b3, $offline->signBody($b3));
$refundService->refund((int) $order3['id'], 590.00, 'Partial');
$check('partial refund sets partially_refunded', $orders->findById((int) $order3['id'])['status'] === 'partially_refunded');
$check('partial refund keeps entitlement active', $purchaseChecker->hasPurchased(902, $p1));

echo "\n";
echo $failures === 0 ? "All Phase 5 checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
