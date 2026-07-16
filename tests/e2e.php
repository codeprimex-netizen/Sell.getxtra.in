<?php

declare(strict_types=1);

/**
 * End-to-end core flow (Req 24.2): a real user journey wired through the
 * actual application services with in-memory adapters —
 *   register -> (seller lists + moderator approves) -> add to cart ->
 *   checkout -> pay (signed webhook) -> entitlement -> secure download ->
 *   license verification.
 * Run: php tests/e2e.php
 */

use App\Application\Catalog\ModerationService;
use App\Application\Commerce\CheckoutService;
use App\Application\Commerce\CommissionPolicy;
use App\Application\Commerce\CouponService;
use App\Application\Commerce\EntitlementService;
use App\Application\Commerce\LedgerService;
use App\Application\Commerce\PaymentService;
use App\Application\Commerce\PricingService;
use App\Application\Download\DownloadService;
use App\Application\Download\DownloadTokenService;
use App\Application\Download\LicenseService;
use App\Application\Audit\AuditLogger;
use App\Application\Identity\EmailVerificationService;
use App\Application\Identity\RegistrationService;
use App\Domain\Identity\PasswordPolicy;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Payment\OfflineGateway;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use Tests\Fakes\InMemoryAuditLogRepository;
use Tests\Fakes\InMemoryAuthTokenRepository;
use Tests\Fakes\InMemoryCartRepository;
use Tests\Fakes\InMemoryCouponRepository;
use Tests\Fakes\InMemoryEntitlementRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryPaymentRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryProductVersionRepository;
use Tests\Fakes\InMemoryRoleRepository;
use Tests\Fakes\InMemoryUserRepository;
use Tests\Fakes\InMemoryWebhookEventRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
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

echo "=== E2E core purchase flow ===\n";

// ── Shared infra ───────────────────────────────────────────────────
$users = new InMemoryUserRepository();
$roles = new InMemoryRoleRepository();
$authTokens = new InMemoryAuthTokenRepository();
$products = new InMemoryProductRepository();
$versions = new InMemoryProductVersionRepository();
$logger = new Logger('/tmp/getxtra_e2e.log', 'error');

// ── 1. Register a buyer (real identity service) ────────────────────
echo "\n-- 1. Registration --\n";
$verification = new EmailVerificationService($authTokens, $users);
$registration = new RegistrationService($users, $roles, new PasswordHasher(), new PasswordPolicy(), $verification);
$reg = $registration->register('Neha Buyer', 'neha@example.com', 'Str0ngPass!x');
$buyerId = (int) $reg['user_id'];
$check('buyer account created', isset($users->rows[$buyerId]));
$check('buyer starts pending verification', ($users->rows[$buyerId]['status'] ?? '') === 'pending');
$verification->verify($reg['verification_token']);
$check('email verification activates the buyer', ($users->rows[$buyerId]['status'] ?? '') === 'active');
$check('buyer holds the buyer role', in_array('buyer', $roles->rolesForUser($buyerId), true));

// ── 2. Seller lists a product; moderator approves it ───────────────
echo "\n-- 2. Listing + moderation --\n";
$sellerId = 500;
$productId = $products->create([
    'seller_id' => $sellerId, 'title' => 'Nova Admin Template', 'slug' => 'nova-admin-template',
    'status' => 'pending', 'scan_status' => 'clean', 'base_price' => 1499.00, 'currency' => 'INR', 'sales_count' => 0,
]);
$storageKey = "products/{$productId}/versions/nova-1.0.0.zip";
$versionId = $versions->create([
    'product_id' => $productId, 'version_number' => '1.0.0',
    'storage_key' => $storageKey, 'scan_status' => 'clean', 'is_current' => 1,
]);
$versions->markCurrent($versionId, $productId);

$moderation = new ModerationService($products, $versions);
$moderation->approve($productId);
$check('product moves to approved after moderation', $products->findById($productId)['status'] === 'approved');

// ── 3. Add to cart ─────────────────────────────────────────────────
echo "\n-- 3. Cart --\n";
$carts = new InMemoryCartRepository();
$cartId = $carts->createForUser($buyerId, 'INR');
$carts->addItem($cartId, $productId, null, 1499.00);
$carts->setLineMeta($cartId, $productId, [
    'product_id' => $productId, 'seller_id' => $sellerId, 'title' => 'Nova Admin Template',
    'unit_price' => 1499.00, 'status' => 'approved', 'scan_status' => 'clean', 'currency' => 'INR', 'license_tier_id' => null,
]);
$check('cart holds one line', $carts->count($cartId) === 1);

// ── 4. Checkout ────────────────────────────────────────────────────
echo "\n-- 4. Checkout --\n";
$orders = new InMemoryOrderRepository();
$paymentsRepo = new InMemoryPaymentRepository();
$couponRepo = new InMemoryCouponRepository();
$gateways = new PaymentGatewayRegistry();
$gateways->register(new OfflineGateway('offline-dev-secret'));
$pricing = new PricingService(18);
$coupons = new CouponService($couponRepo);

$checkout = new CheckoutService(
    $carts, $products, $orders, $paymentsRepo, $coupons, $pricing, new CommissionPolicy(20), $gateways,
);
$order = $checkout->checkout($buyerId, $cartId, null, 'e2e-idem-1', 'offline')['order'];
$check('order created pending', $order['status'] === 'pending');
$check('total = 1499 + 18% tax = 1768.82', (int) round((float) $order['total'] * 100) === 176882);
$check('cart emptied after checkout', $carts->count($cartId) === 0);

// ── 5. Payment via signed webhook -> entitlement + ledger ──────────
echo "\n-- 5. Payment --\n";
$entitlementsRepo = new InMemoryEntitlementRepository();
$ledgerRepo = new InMemoryLedgerRepository();
$webhookEvents = new InMemoryWebhookEventRepository();
$entitlementService = new EntitlementService($entitlementsRepo, $products);
$ledgerService = new LedgerService($ledgerRepo);
$paymentService = new PaymentService(
    $gateways, $webhookEvents, $orders, $paymentsRepo, $couponRepo,
    $entitlementService, $ledgerService, $logger,
);

$offline = new OfflineGateway('offline-dev-secret');
$body = json_encode([
    'event_id' => 'e2e-evt-1', 'order_number' => $order['order_number'],
    'gateway_ref' => 'off_e2e_1', 'status' => 'paid',
]) ?: '{}';
$accepted = $paymentService->handleWebhook('offline', $body, $offline->signBody($body));
$check('signed webhook accepted', $accepted === true);
$check('order marked paid', $orders->findById((int) $order['id'])['status'] === 'paid');

$ents = $entitlementsRepo->forBuyer($buyerId);
$check('entitlement granted to buyer', count($ents) === 1);
$check('seller earning is pending (80% of 1499 = 1199.20)',
    abs($ledgerRepo->balances($ledgerRepo->account('seller', $sellerId, 'INR'))['pending'] - 1199.20) < 0.01);
$check('platform commission credited (20% = 299.80)',
    abs($ledgerRepo->balances($ledgerRepo->account('platform', null, 'INR'))['balance'] - 299.80) < 0.01);

// ── 6. Secure download + license ───────────────────────────────────
echo "\n-- 6. Secure download --\n";
$tmp = sys_get_temp_dir() . '/getxtra_e2e_' . uniqid();
$storage = new StorageManager();
$private = new LocalStorage($tmp . '/private', '', false);
$storage->register('private', $private);
$private->put($storageKey, "PK\x05\x06" . str_repeat("\x00", 18));

$tokens = new DownloadTokenService('e2e-secret');
$audit = new InMemoryAuditLogRepository();
$downloads = new DownloadService($tokens, $entitlementsRepo, $versions, $products, $storage, new AuditLogger($audit));
$licenses = new LicenseService($entitlementsRepo, $products);

$entId = (int) $ents[0]['id'];
$link = $downloads->createLink($entId, $buyerId);
$check('signed download link minted', str_starts_with($link, '/download/'));
$token = substr($link, strlen('/download/'));
$deliverable = $downloads->resolve($token, $buyerId, '10.0.0.1', 'e2e-req');
$check('download resolves to the deliverable', $deliverable->storageKey === $storageKey);
$check('download filename derived from slug+version', $deliverable->filename === 'nova-admin-template-v1.0.0.zip');
$check('download count incremented', (int) $entitlementsRepo->findById($entId)['download_count'] === 1);

$licenseKey = (string) $ents[0]['license_key'];
$check('issued license verifies as valid', $licenses->verify($licenseKey)['valid'] === true);

// A different user cannot download the buyer's entitlement.
$denied = false;
try {
    $downloads->resolve($token, 999999);
} catch (\Throwable $e) {
    $denied = true;
}
$check('another user is denied the download', $denied);

echo "\n";
echo $failures === 0 ? "E2E flow passed.\n" : "{$failures} E2E check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
