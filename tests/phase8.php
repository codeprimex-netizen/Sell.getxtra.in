<?php

declare(strict_types=1);

/**
 * Phase 8 tests: admin user management (suspend/activate/roles), category &
 * coupon admin, dispute workflow (resolve/reject/refund via RefundService),
 * feature flags, settings, and dashboard reporting. In-memory + no DB.
 * Run: php tests/phase8.php
 */

use App\Application\Admin\AdminException;
use App\Application\Admin\AdminReportService;
use App\Application\Admin\AdminUserService;
use App\Application\Admin\CategoryAdminService;
use App\Application\Admin\CouponAdminService;
use App\Application\Audit\AuditLogger;
use App\Application\Commerce\EntitlementService;
use App\Application\Commerce\LedgerService;
use App\Application\Commerce\RefundService;
use App\Application\Identity\AccessControl;
use App\Application\Support\DisputeException;
use App\Application\Support\DisputeService;
use App\Infrastructure\Payment\OfflineGateway;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use Tests\Fakes\InMemoryAdminUserRepository;
use Tests\Fakes\InMemoryAuditLogRepository;
use Tests\Fakes\InMemoryCategoryRepository;
use Tests\Fakes\InMemoryCouponRepository;
use Tests\Fakes\InMemoryDisputeRepository;
use Tests\Fakes\InMemoryEntitlementRepository;
use Tests\Fakes\InMemoryFeatureFlagRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryPaymentRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryRefundRepository;
use Tests\Fakes\InMemoryReportRepository;
use Tests\Fakes\InMemoryRoleRepository;
use Tests\Fakes\InMemorySettingsRepository;
use Tests\Fakes\InMemoryUserRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemoryAdmin.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 8 admin console tests ===\n";

// ── User management ───────────────────────────────────────────────
$identity = new InMemoryUserRepository();
$roles = new InMemoryRoleRepository();
$audit = new InMemoryAuditLogRepository();
$auditLogger = new AuditLogger($audit);
$access = new AccessControl($roles);
$adminUsers = new InMemoryAdminUserRepository($identity->rows);
$userService = new AdminUserService($adminUsers, $identity, $roles, $access, $auditLogger);

$uid = $identity->create(['name' => 'Test User', 'email' => 'tu@example.com', 'password_hash' => 'x', 'status' => 'active']);

$userService->suspend($uid, 1, '127.0.0.1');
$check('suspend sets status', $identity->findById($uid)['status'] === 'suspended');
$check('suspend audited', $audit->countAction('user.suspend') === 1);
$userService->activate($uid, 1);
$check('activate restores status', $identity->findById($uid)['status'] === 'active');

$userService->assignRole($uid, 'seller', 1);
$check('role assigned', in_array('seller', $roles->rolesForUser($uid), true));
$check('assign role audited', $audit->countAction('user.assign_role') === 1);
$check('RBAC reflects new role (cache cleared)', $access->hasRole($uid, 'seller'));

$badRole = false;
try {
    $userService->assignRole($uid, 'wizard', 1);
} catch (AdminException $e) {
    $badRole = $e->errorCode === 'validation';
}
$check('invalid role rejected', $badRole);
$userService->removeRole($uid, 'seller', 1);
$check('role removed', !$access->hasRole($uid, 'seller'));

$missing = false;
try {
    $userService->suspend(9999, 1);
} catch (AdminException $e) {
    $missing = $e->errorCode === 'not_found';
}
$check('suspend unknown user -> not found', $missing);

// ── Category admin ────────────────────────────────────────────────
$catRepo = new InMemoryCategoryRepository();
$catRepo->rows = []; // start empty
$categories = new CategoryAdminService($catRepo);
$c1 = $categories->create('Cloud Tools');
$c2 = $categories->create('Cloud Tools'); // duplicate name -> unique slug
$check('category created', $catRepo->findById($c1) !== null);
$check('duplicate name yields unique slug', $catRepo->findById($c1)['slug'] !== $catRepo->findById($c2)['slug']);
$categories->toggleActive($c1, false);
$check('category toggled inactive', (int) $catRepo->findById($c1)['is_active'] === 0);
$emptyName = false;
try {
    $categories->create('  ');
} catch (AdminException $e) {
    $emptyName = true;
}
$check('empty category name rejected', $emptyName);
$categories->delete($c2);
$check('category deleted', $catRepo->findById($c2) === null);

// ── Coupon admin ──────────────────────────────────────────────────
$couponRepo = new InMemoryCouponRepository();
$coupons = new CouponAdminService($couponRepo);
$coupons->create(['code' => 'save20', 'type' => 'percent', 'value' => 20, 'min_order' => 100]);
$check('coupon created + code uppercased', $couponRepo->findByCode('SAVE20') !== null);
$dupCode = false;
try {
    $coupons->create(['code' => 'SAVE20', 'type' => 'fixed', 'value' => 5]);
} catch (AdminException $e) {
    $dupCode = true;
}
$check('duplicate coupon code rejected', $dupCode);
$badPct = false;
try {
    $coupons->create(['code' => 'HUGE', 'type' => 'percent', 'value' => 150]);
} catch (AdminException $e) {
    $badPct = true;
}
$check('percent > 100 rejected', $badPct);
$couponId = (int) $couponRepo->findByCode('SAVE20')['id'];
$coupons->setActive($couponId, false);
$check('coupon deactivated', (int) $couponRepo->findByCode('SAVE20')['is_active'] === 0);

// ── Feature flags + settings ──────────────────────────────────────
$flags = new InMemoryFeatureFlagRepository();
$flags->setEnabled('affiliate_program', true, 50);
$check('feature flag enabled', $flags->isEnabled('affiliate_program'));
$flags->setEnabled('affiliate_program', false);
$check('feature flag disabled', !$flags->isEnabled('affiliate_program'));

$settings = new InMemorySettingsRepository();
$settings->set('support_email', 'help@sell.getxtra.in');
$check('setting stored + retrieved', $settings->get('support_email') === 'help@sell.getxtra.in');

// ── Reporting ─────────────────────────────────────────────────────
$disputesRepo = new InMemoryDisputeRepository();
$reports = new AdminReportService(new InMemoryReportRepository(), $disputesRepo);
$dash = $reports->dashboard();
$check('dashboard overview present', ($dash['overview']['gmv'] ?? 0) > 0);
$check('dashboard top sellers present', count($dash['top_sellers']) === 1);

// ── Dispute workflow (with refund via RefundService) ──────────────
$products = new InMemoryProductRepository();
$p1 = $products->create(['seller_id' => 10, 'title' => 'Pro Kit', 'slug' => 'pro-kit', 'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 1000, 'currency' => 'INR']);

$orders = new InMemoryOrderRepository();
$payments = new InMemoryPaymentRepository();
$refundsRepo = new InMemoryRefundRepository();
$entitlements = new InMemoryEntitlementRepository();
$ledger = new LedgerService(new InMemoryLedgerRepository());
$entitlementService = new EntitlementService($entitlements, $products);
$gateways = new PaymentGatewayRegistry();
$gateways->register(new OfflineGateway('secret'));
$refundService = new RefundService($orders, $payments, $refundsRepo, $ledger, $entitlementService, $gateways);

$orderId = $orders->create([
    'buyer_id' => 900, 'order_number' => 'ORD-DISP', 'currency' => 'INR',
    'subtotal' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000, 'coupon_id' => null,
    'status' => 'paid', 'idempotency_key' => 'k-disp',
], [['product_id' => $p1, 'seller_id' => 10, 'license_tier_id' => null, 'title_snapshot' => 'Pro Kit', 'unit_price' => 1000, 'commission' => 200, 'seller_earning' => 800]]);
$itemId = (int) $orders->items($orderId)[0]['id'];
$payments->create(['order_id' => $orderId, 'gateway' => 'offline', 'gateway_ref' => 'ref', 'amount' => 1000, 'currency' => 'INR', 'status' => 'captured']);
$entId = $entitlements->create(['order_item_id' => $itemId, 'buyer_id' => 900, 'product_id' => $p1, 'license_key' => 'DK', 'status' => 'active', 'download_count' => 0]);

$disputes = new DisputeService($disputesRepo, $refundService, $auditLogger);

// Resolve path.
$d1 = $disputes->open($orderId, 900, 'Item not as described');
$check('dispute opened', $disputesRepo->findById($d1)['status'] === 'open');
$disputes->resolve($d1, 'Explained usage to buyer', 1);
$check('dispute resolved', $disputesRepo->findById($d1)['status'] === 'resolved');
$reResolve = false;
try {
    $disputes->reject($d1, 'x', 1); // resolved is terminal
} catch (DisputeException $e) {
    $reResolve = $e->errorCode === 'invalid_transition';
}
$check('terminal dispute cannot transition', $reResolve);

// Refund path.
$d2 = $disputes->open($orderId, 900, 'Broken download');
$disputes->refund($d2, 1000.00, 'Full refund issued', 1);
$check('dispute refund sets refunded', $disputesRepo->findById($d2)['status'] === 'refunded');
$check('refund updated order to refunded', $orders->findById($orderId)['status'] === 'refunded');
$check('refund revoked entitlement', $entitlements->findById($entId)['status'] === 'revoked');
$check('dispute refund audited', $audit->countAction('dispute.refund') === 1);
$check('open dispute count reflects workflow', $disputesRepo->openCount() === 0);

echo "\n";
echo $failures === 0 ? "All Phase 8 checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
