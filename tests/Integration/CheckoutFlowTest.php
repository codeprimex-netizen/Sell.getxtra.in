<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Commerce\CheckoutService;
use App\Application\Commerce\CommissionPolicy;
use App\Application\Commerce\CouponService;
use App\Application\Commerce\EntitlementService;
use App\Application\Commerce\LedgerService;
use App\Application\Commerce\PaymentService;
use App\Application\Commerce\PricingService;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Payment\OfflineGateway;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryCartRepository;
use Tests\Fakes\InMemoryCouponRepository;
use Tests\Fakes\InMemoryEntitlementRepository;
use Tests\Fakes\InMemoryLedgerRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemoryPaymentRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryWebhookEventRepository;

/**
 * Integration test across the commerce services (Req 24.2): checkout ->
 * signed webhook -> entitlements + double-entry ledger, using in-memory
 * adapters that stand in for the DB/cache/gateway sandboxes.
 */
final class CheckoutFlowTest extends TestCase
{
    public function testCheckoutThenPaidWebhookGrantsEntitlementAndPostsLedger(): void
    {
        $products = new InMemoryProductRepository();
        $pid = $products->create([
            'seller_id' => 10, 'title' => 'Kit', 'slug' => 'kit', 'status' => 'approved',
            'scan_status' => 'clean', 'base_price' => 1000, 'currency' => 'INR', 'sales_count' => 0,
        ]);

        $carts = new InMemoryCartRepository();
        $orders = new InMemoryOrderRepository();
        $paymentsRepo = new InMemoryPaymentRepository();
        $couponRepo = new InMemoryCouponRepository();
        $gateways = new PaymentGatewayRegistry();
        $gateways->register(new OfflineGateway('offline-dev-secret'));

        $buyerId = 900;
        $cartId = $carts->createForUser($buyerId, 'INR');
        $carts->addItem($cartId, $pid, null, 1000.0);
        $carts->setLineMeta($cartId, $pid, [
            'product_id' => $pid, 'seller_id' => 10, 'title' => 'Kit', 'unit_price' => 1000.0,
            'status' => 'approved', 'scan_status' => 'clean', 'currency' => 'INR', 'license_tier_id' => null,
        ]);

        $checkout = new CheckoutService(
            $carts, $products, $orders, $paymentsRepo,
            new CouponService($couponRepo), new PricingService(18), new CommissionPolicy(20), $gateways,
        );
        $order = $checkout->checkout($buyerId, $cartId, null, 'it-idem-1', 'offline')['order'];

        $this->assertSame('pending', $order['status']);
        $this->assertSame(118000, (int) round((float) $order['total'] * 100)); // 1000 + 18%

        // Idempotency: the same key must not create a second order.
        $again = $checkout->checkout($buyerId, $cartId, null, 'it-idem-1', 'offline')['order'];
        $this->assertSame((int) $order['id'], (int) $again['id']);
        $this->assertCount(1, $orders->orders);

        $entitlements = new InMemoryEntitlementRepository();
        $ledgerRepo = new InMemoryLedgerRepository();
        $payments = new PaymentService(
            $gateways, new InMemoryWebhookEventRepository(), $orders, $paymentsRepo, $couponRepo,
            new EntitlementService($entitlements, $products), new LedgerService($ledgerRepo),
            new Logger('/tmp/getxtra_it.log', 'error'),
        );

        $offline = new OfflineGateway('offline-dev-secret');
        $body = json_encode([
            'event_id' => 'it-evt-1', 'order_number' => $order['order_number'],
            'gateway_ref' => 'off_it_1', 'status' => 'paid',
        ]) ?: '{}';

        $this->assertTrue($payments->handleWebhook('offline', $body, $offline->signBody($body)));
        $this->assertSame('paid', $orders->findById((int) $order['id'])['status']);
        $this->assertCount(1, $entitlements->forBuyer($buyerId));

        // Bad signature is rejected.
        $this->assertFalse($payments->handleWebhook('offline', $body, 'wrong-sig'));

        // Ledger: 20% commission to platform, 80% pending to the seller.
        $platform = $ledgerRepo->account('platform', null, 'INR');
        $seller = $ledgerRepo->account('seller', 10, 'INR');
        $this->assertEqualsWithDelta(200.0, $ledgerRepo->balances($platform)['balance'], 0.01);
        $this->assertEqualsWithDelta(800.0, $ledgerRepo->balances($seller)['pending'], 0.01);
    }
}
