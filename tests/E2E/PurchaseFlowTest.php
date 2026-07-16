<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Application\Audit\AuditLogger;
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
use App\Application\Identity\EmailVerificationService;
use App\Application\Identity\RegistrationService;
use App\Domain\Identity\PasswordPolicy;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Payment\OfflineGateway;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use PHPUnit\Framework\TestCase;
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

/**
 * End-to-end core flow (Req 24.2): register -> list + approve -> buy ->
 * pay -> download, wired through the real application services.
 */
final class PurchaseFlowTest extends TestCase
{
    public function testRegisterBuyDownload(): void
    {
        // Register + verify a buyer.
        $users = new InMemoryUserRepository();
        $roles = new InMemoryRoleRepository();
        $authTokens = new InMemoryAuthTokenRepository();
        $verification = new EmailVerificationService($authTokens, $users);
        $registration = new RegistrationService($users, $roles, new PasswordHasher(), new PasswordPolicy(), $verification);
        $reg = $registration->register('Neha', 'neha.e2e@example.com', 'Str0ngPass!x');
        $buyerId = (int) $reg['user_id'];
        $verification->verify($reg['verification_token']);
        $this->assertSame('active', $users->rows[$buyerId]['status']);

        // Seller lists a product; moderator approves it.
        $products = new InMemoryProductRepository();
        $versions = new InMemoryProductVersionRepository();
        $sellerId = 500;
        $pid = $products->create([
            'seller_id' => $sellerId, 'title' => 'Nova', 'slug' => 'nova',
            'status' => 'pending', 'scan_status' => 'clean', 'base_price' => 1000, 'currency' => 'INR', 'sales_count' => 0,
        ]);
        $storageKey = "products/{$pid}/versions/nova-1.0.0.zip";
        $vid = $versions->create([
            'product_id' => $pid, 'version_number' => '1.0.0', 'storage_key' => $storageKey,
            'scan_status' => 'clean', 'is_current' => 1,
        ]);
        $versions->markCurrent($vid, $pid);
        (new ModerationService($products, $versions))->approve($pid);
        $this->assertSame('approved', $products->findById($pid)['status']);

        // Cart + checkout.
        $carts = new InMemoryCartRepository();
        $orders = new InMemoryOrderRepository();
        $paymentsRepo = new InMemoryPaymentRepository();
        $couponRepo = new InMemoryCouponRepository();
        $gateways = new PaymentGatewayRegistry();
        $gateways->register(new OfflineGateway('offline-dev-secret'));

        $cartId = $carts->createForUser($buyerId, 'INR');
        $carts->addItem($cartId, $pid, null, 1000.0);
        $carts->setLineMeta($cartId, $pid, [
            'product_id' => $pid, 'seller_id' => $sellerId, 'title' => 'Nova', 'unit_price' => 1000.0,
            'status' => 'approved', 'scan_status' => 'clean', 'currency' => 'INR', 'license_tier_id' => null,
        ]);

        $checkout = new CheckoutService(
            $carts, $products, $orders, $paymentsRepo,
            new CouponService($couponRepo), new PricingService(18), new CommissionPolicy(20), $gateways,
        );
        $order = $checkout->checkout($buyerId, $cartId, null, 'e2e-phpunit-1', 'offline')['order'];
        $this->assertSame('pending', $order['status']);

        // Pay via signed webhook.
        $entitlements = new InMemoryEntitlementRepository();
        $payments = new PaymentService(
            $gateways, new InMemoryWebhookEventRepository(), $orders, $paymentsRepo, $couponRepo,
            new EntitlementService($entitlements, $products), new LedgerService(new InMemoryLedgerRepository()),
            new Logger('/tmp/getxtra_e2e_phpunit.log', 'error'),
        );
        $offline = new OfflineGateway('offline-dev-secret');
        $body = json_encode([
            'event_id' => 'e2e-phpunit', 'order_number' => $order['order_number'],
            'gateway_ref' => 'off_1', 'status' => 'paid',
        ]) ?: '{}';
        $this->assertTrue($payments->handleWebhook('offline', $body, $offline->signBody($body)));
        $this->assertSame('paid', $orders->findById((int) $order['id'])['status']);

        $ents = $entitlements->forBuyer($buyerId);
        $this->assertCount(1, $ents);

        // Secure download.
        $tmp = sys_get_temp_dir() . '/getxtra_e2e_phpunit_' . uniqid();
        $storage = new StorageManager();
        $private = new LocalStorage($tmp . '/private', '', false);
        $storage->register('private', $private);
        $private->put($storageKey, "PK\x05\x06" . str_repeat("\x00", 18));

        $downloads = new DownloadService(
            new DownloadTokenService('e2e-secret'), $entitlements, $versions, $products, $storage,
            new AuditLogger(new InMemoryAuditLogRepository()),
        );
        $link = $downloads->createLink((int) $ents[0]['id'], $buyerId);
        $token = substr($link, strlen('/download/'));
        $deliverable = $downloads->resolve($token, $buyerId, '10.0.0.1', 'e2e');
        $this->assertSame($storageKey, $deliverable->storageKey);
        $this->assertSame('nova-v1.0.0.zip', $deliverable->filename);
    }
}
