<?php

declare(strict_types=1);

/**
 * Account dashboard aggregation tests: purchases summary, library + wishlist
 * counts, seller earnings card (sellers only), and affiliate stats — with
 * graceful, per-rail defaults. In-memory + no DB. Run: php tests/dashboard.php
 */

use App\Application\Account\DashboardService;
use App\Application\Affiliate\AffiliateService;
use App\Application\Commerce\LedgerService;
use App\Application\Identity\AccessControl;
use App\Application\Seller\SellerWalletService;
use App\Config\Config;
use App\Domain\Commerce\Money;
use Tests\Fakes\InMemoryAffiliateRepository;
use Tests\Fakes\InMemoryEntitlementRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryPayoutRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryReferralRepository;
use Tests\Fakes\InMemoryRoleRepository;
use Tests\Fakes\InMemoryWishlistRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemorySeller.php';
require __DIR__ . '/Fakes/InMemoryAffiliate.php';

Config::boot();
Config::set('affiliate.enabled', true);

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Account dashboard tests ===\n";

// ── Wiring ─────────────────────────────────────────────────────────
$orders = new InMemoryOrderRepository();
$entitlements = new InMemoryEntitlementRepository();
$wishlist = new InMemoryWishlistRepository();
$products = new InMemoryProductRepository();
$ledgerRepo = new InMemoryLedgerRepository();
$payouts = new InMemoryPayoutRepository();
$roles = new InMemoryRoleRepository();
$affRepo = new InMemoryAffiliateRepository();
$refRepo = new InMemoryReferralRepository();

$wallet = new SellerWalletService($ledgerRepo, $payouts);
$affiliates = new AffiliateService($affRepo, $refRepo, new LedgerService($ledgerRepo), $ledgerRepo);
$access = new AccessControl($roles);

$dash = new DashboardService($orders, $entitlements, $wishlist, $products, $wallet, $affiliates, $access);

// ── Buyer data ─────────────────────────────────────────────────────
$buyer = 700;
$orders->create(['order_number' => 'ORD-1', 'buyer_id' => $buyer, 'currency' => 'INR', 'subtotal' => 1000.0, 'total' => 1000.0, 'status' => 'paid'], []);
$orders->create(['order_number' => 'ORD-2', 'buyer_id' => $buyer, 'currency' => 'INR', 'subtotal' => 500.0, 'total' => 500.0, 'status' => 'pending'], []);
$entitlements->create(['buyer_id' => $buyer, 'product_id' => 1, 'order_id' => 1, 'license_key' => 'LIC-1']);
$wishlist->add($buyer, 11);
$wishlist->add($buyer, 22);

echo "\n-- Buyer summary --\n";
$s = $dash->summary($buyer);
$check('orders total counted', $s['orders']['total'] === 2);
$check('only paid orders count toward spent', abs($s['orders']['spent'] - 1000.0) < 0.01);
$check('recent orders included', count($s['orders']['recent']) === 2);
$check('library count', ($s['library']['count'] ?? null) === 1);
$check('wishlist count', ($s['wishlist'] ?? null) === 2);
$check('non-seller has no seller card', $s['seller'] === null);
$check('not enrolled as affiliate', ($s['affiliate']['enrolled'] ?? true) === false);

// ── Seller card ────────────────────────────────────────────────────
echo "\n-- Seller summary --\n";
$sellerId = 800;
$roles->rolePermissions['seller'] = ['product.create', 'payout.request'];
$roles->assignRoleByName($sellerId, 'seller');
$products->create(['seller_id' => $sellerId, 'title' => 'A', 'slug' => 'a', 'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 100, 'currency' => 'INR']);
$products->create(['seller_id' => $sellerId, 'title' => 'B', 'slug' => 'b', 'status' => 'draft', 'scan_status' => 'pending', 'base_price' => 200, 'currency' => 'INR']);
$acct = $ledgerRepo->account('seller', $sellerId, 'INR');
$ledgerRepo->post($acct, 'credit', 'cleared', Money::fromDecimal(500.0, 'INR'), 'seed', null, 'seed earnings');

$ss = $dash->summary($sellerId);
$check('seller card present for a seller', $ss['seller'] !== null);
$check('seller product count', ($ss['seller']['products'] ?? null) === 2);
$check('seller available earnings', abs(($ss['seller']['available'] ?? 0) - 500.0) < 0.01);
$check('seller cleared earnings', abs(($ss['seller']['cleared'] ?? 0) - 500.0) < 0.01);

// ── Affiliate card ─────────────────────────────────────────────────
echo "\n-- Affiliate summary --\n";
$affiliates->enroll($buyer);
$a = $dash->summary($buyer);
$check('affiliate enrolled reflected', ($a['affiliate']['enrolled'] ?? false) === true);
$check('affiliate code exposed', isset($a['affiliate']['code']));

echo "\n";
echo $failures === 0 ? "OK — all dashboard assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
