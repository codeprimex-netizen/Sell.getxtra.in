<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\Config;
use App\Config\Env;
use App\Domain\Identity\AuthTokenRepositoryInterface;
use App\Domain\Identity\LoginAttemptRepositoryInterface;
use App\Domain\Identity\RoleRepositoryInterface;
use App\Domain\Identity\SessionRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;
use App\Http\Kernel;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\Authorize;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrf;
use App\Http\Router;
use App\Http\Session\NativeSessionStore;
use App\Http\Session\SessionStore;
use App\Http\View;
use App\Infrastructure\Cache\RateLimiter;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Persistence\ConnectionManager;
use App\Infrastructure\Persistence\PdoAuthTokenRepository;
use App\Infrastructure\Persistence\PdoLoginAttemptRepository;
use App\Infrastructure\Persistence\PdoRoleRepository;
use App\Infrastructure\Persistence\PdoSessionRepository;
use App\Infrastructure\Persistence\PdoUserRepository;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Domain\Catalog\LicenseTierRepositoryInterface;
use App\Domain\Catalog\ProductFileRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;
use App\Infrastructure\Persistence\PdoCategoryRepository;
use App\Infrastructure\Persistence\PdoLicenseTierRepository;
use App\Infrastructure\Persistence\PdoProductFileRepository;
use App\Infrastructure\Persistence\PdoProductRepository;
use App\Infrastructure\Persistence\PdoProductVersionRepository;
use App\Infrastructure\Persistence\PdoTagRepository;
use App\Infrastructure\Queue\QueueInterface;
use App\Infrastructure\Queue\SyncQueue;
use App\Infrastructure\Security\AntivirusScanner;
use App\Infrastructure\Security\SignatureScanner;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use App\Domain\Commerce\PurchaseCheckerInterface;
use App\Domain\Review\ReviewRepositoryInterface;
use App\Domain\Review\WishlistRepositoryInterface;
use App\Infrastructure\Commerce\NullPurchaseChecker;
use App\Infrastructure\Persistence\PdoReviewRepository;
use App\Infrastructure\Persistence\PdoWishlistRepository;
use App\Infrastructure\Search\MeilisearchIndex;
use App\Infrastructure\Search\NullSearchIndex;
use App\Infrastructure\Search\SearchIndex;
use App\Domain\Commerce\CartRepositoryInterface;
use App\Domain\Commerce\CouponRepositoryInterface;
use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Commerce\PaymentRepositoryInterface;
use App\Domain\Commerce\RefundRepositoryInterface;
use App\Domain\Commerce\WebhookEventRepositoryInterface;
use App\Infrastructure\Commerce\EntitlementPurchaseChecker;
use App\Infrastructure\Payment\OfflineGateway;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use App\Infrastructure\Payment\RazorpayGateway;
use App\Infrastructure\Payment\StripeGateway;
use App\Infrastructure\Persistence\PdoCartRepository;
use App\Infrastructure\Persistence\PdoCouponRepository;
use App\Infrastructure\Persistence\PdoEntitlementRepository;
use App\Infrastructure\Persistence\PdoLedgerRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoPaymentRepository;
use App\Infrastructure\Persistence\PdoRefundRepository;
use App\Infrastructure\Persistence\PdoWebhookEventRepository;
use App\Domain\Audit\AuditLogRepositoryInterface;
use App\Infrastructure\Persistence\PdoAuditLogRepository;
use App\Application\Commerce\LedgerService;
use App\Domain\Admin\SettingsRepositoryInterface;
use App\Domain\Notification\NotificationPreferenceRepositoryInterface;
use App\Domain\Notification\NotificationRepositoryInterface;
use App\Infrastructure\Mail\LogMailer;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Persistence\PdoNotificationPreferenceRepository;
use App\Infrastructure\Persistence\PdoNotificationRepository;
use App\Infrastructure\Queue\DatabaseQueueDriver;
use App\Infrastructure\Queue\Dispatcher;
use App\Infrastructure\Queue\JobRegistry;
use App\Infrastructure\Queue\QueueDriver;
use App\Infrastructure\Queue\SyncQueueDriver;
use App\Infrastructure\Queue\Worker;
use App\Infrastructure\Scheduler\Scheduler;
use App\Jobs\Handlers\DispatchWebhookHandler;
use App\Jobs\Handlers\GenerateInvoiceHandler;
use App\Jobs\Handlers\SendEmailHandler;
use App\Jobs\Handlers\SendNotificationHandler;

/**
 * Application bootstrapper.
 *
 * Loads env + config, builds the DI container with core + identity service
 * bindings, registers routes, and produces a ready-to-run HTTP Kernel.
 */
final class App
{
    private Container $container;

    public function __construct(private string $basePath)
    {
    }

    public function boot(): self
    {
        Env::load($this->basePath . '/.env');
        Config::boot();

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
        View::setViewPath($this->basePath . '/resources/views');

        $this->container = Container::getInstance();
        $this->registerCoreServices();
        $this->registerIdentityServices();
        $this->registerCatalogServices();
        $this->registerCommerceServices();
        $this->registerAdminServices();
        $this->registerSellerServices();
        $this->registerQueueServices();
        $this->registerHttp();
        $this->registerRoutes();

        return $this;
    }

    private function registerCoreServices(): void
    {
        $c = $this->container;
        $basePath = $this->basePath;

        $c->singleton(Logger::class, static function () use ($basePath): Logger {
            $path = (string) Config::get('log.path', 'storage/logs/app.log');
            if (!str_starts_with($path, '/')) {
                $path = $basePath . '/' . $path;
            }
            return new Logger($path, (string) Config::get('log.level', 'info'));
        });

        $c->singleton(ConnectionManager::class, static fn (): ConnectionManager => new ConnectionManager());

        $c->singleton(RateLimiter::class, static fn (): RateLimiter =>
            new RateLimiter($basePath . '/storage/cache/ratelimit'));

        $c->singleton(SessionStore::class, static fn (): SessionStore => new NativeSessionStore());
    }

    /**
     * Bind identity repository interfaces to their PDO implementations. The
     * concrete services and middleware autowire from these bindings.
     */
    private function registerIdentityServices(): void
    {
        $c = $this->container;

        $c->singleton(UserRepositoryInterface::class, static fn (Container $c) =>
            new PdoUserRepository($c->get(ConnectionManager::class)));

        $c->singleton(RoleRepositoryInterface::class, static fn (Container $c) =>
            new PdoRoleRepository($c->get(ConnectionManager::class)));

        $c->singleton(AuthTokenRepositoryInterface::class, static fn (Container $c) =>
            new PdoAuthTokenRepository($c->get(ConnectionManager::class)));

        $c->singleton(LoginAttemptRepositoryInterface::class, static fn (Container $c) =>
            new PdoLoginAttemptRepository($c->get(ConnectionManager::class)));

        $c->singleton(SessionRepositoryInterface::class, static fn (Container $c) =>
            new PdoSessionRepository($c->get(ConnectionManager::class)));
    }

    /**
     * Bind catalog repositories, storage disks, the antivirus scanner, and
     * the job queue. Catalog application services autowire from these.
     */
    private function registerCatalogServices(): void
    {
        $c = $this->container;
        $basePath = $this->basePath;

        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        $c->singleton(ProductRepositoryInterface::class, static fn (Container $c) => new PdoProductRepository($conn($c)));
        $c->singleton(CategoryRepositoryInterface::class, static fn (Container $c) => new PdoCategoryRepository($conn($c)));
        $c->singleton(TagRepositoryInterface::class, static fn (Container $c) => new PdoTagRepository($conn($c)));
        $c->singleton(LicenseTierRepositoryInterface::class, static fn (Container $c) => new PdoLicenseTierRepository($conn($c)));
        $c->singleton(ProductVersionRepositoryInterface::class, static fn (Container $c) => new PdoProductVersionRepository($conn($c)));
        $c->singleton(ProductFileRepositoryInterface::class, static fn (Container $c) => new PdoProductFileRepository($conn($c)));

        // Storage disks: public media (CDN/web-served) + private deliverables.
        $c->singleton(StorageManager::class, static function () use ($basePath): StorageManager {
            $manager = new StorageManager();
            $cdn = (string) Config::get('storage.cdn_url', '');
            $manager->register('public', new LocalStorage(
                root: $basePath . '/public/storage',
                baseUrl: $cdn !== '' ? $cdn : '/storage',
                public: true,
            ));
            $manager->register('private', new LocalStorage(
                root: $basePath . '/storage/uploads/private',
                baseUrl: '',
                public: false,
            ));
            return $manager;
        });

        $c->singleton(AntivirusScanner::class, static fn (): AntivirusScanner => new SignatureScanner());

        $c->singleton(QueueInterface::class, static fn (Container $c): QueueInterface =>
            new SyncQueue($c->get(Logger::class)));

        // Reviews & wishlist (Phase 4).
        $c->singleton(ReviewRepositoryInterface::class, static fn (Container $c) => new PdoReviewRepository($conn($c)));
        $c->singleton(WishlistRepositoryInterface::class, static fn (Container $c) => new PdoWishlistRepository($conn($c)));
        $c->singleton(PurchaseCheckerInterface::class, static fn (): PurchaseCheckerInterface => new NullPurchaseChecker());

        // Search engine: use Meilisearch when configured, else the MySQL
        // FULLTEXT fallback via NullSearchIndex (Req 6.1 / 6.4).
        $c->singleton(SearchIndex::class, static function (): SearchIndex {
            $driver = (string) Config::get('search.driver', 'mysql');
            $host = (string) Config::get('search.host', '');
            if ($driver === 'meilisearch' && $host !== '') {
                return new MeilisearchIndex($host, (string) Config::get('search.key', ''));
            }
            return new NullSearchIndex();
        });
    }

    /**
     * Bind commerce repositories, payment gateways, and the entitlement-backed
     * purchase checker (Phase 5). Application services autowire from these.
     */
    private function registerCommerceServices(): void
    {
        $c = $this->container;
        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        $c->singleton(CartRepositoryInterface::class, static fn (Container $c) => new PdoCartRepository($conn($c)));
        $c->singleton(CouponRepositoryInterface::class, static fn (Container $c) => new PdoCouponRepository($conn($c)));
        $c->singleton(OrderRepositoryInterface::class, static fn (Container $c) => new PdoOrderRepository($conn($c)));
        $c->singleton(PaymentRepositoryInterface::class, static fn (Container $c) => new PdoPaymentRepository($conn($c)));
        $c->singleton(WebhookEventRepositoryInterface::class, static fn (Container $c) => new PdoWebhookEventRepository($conn($c)));
        $c->singleton(EntitlementRepositoryInterface::class, static fn (Container $c) => new PdoEntitlementRepository($conn($c)));
        $c->singleton(LedgerRepositoryInterface::class, static fn (Container $c) => new PdoLedgerRepository($conn($c)));
        $c->singleton(RefundRepositoryInterface::class, static fn (Container $c) => new PdoRefundRepository($conn($c)));

        // Payment gateways: offline (dev) always available; real gateways when configured.
        $c->singleton(PaymentGatewayRegistry::class, static function (): PaymentGatewayRegistry {
            $registry = new PaymentGatewayRegistry();
            $registry->register(new OfflineGateway((string) Config::get('commerce.offline_secret', 'offline-dev-secret')));

            if (Env::get('RAZORPAY_KEY_ID')) {
                $registry->register(new RazorpayGateway(
                    (string) Env::get('RAZORPAY_KEY_ID', ''),
                    (string) Env::get('RAZORPAY_KEY_SECRET', ''),
                    (string) Env::get('RAZORPAY_WEBHOOK_SECRET', ''),
                ));
            }
            if (Env::get('STRIPE_SECRET')) {
                $registry->register(new StripeGateway(
                    (string) Env::get('STRIPE_SECRET', ''),
                    (string) Env::get('STRIPE_WEBHOOK_SECRET', ''),
                ));
            }
            return $registry;
        });

        // Now that entitlements exist, use the real purchase checker (Req 7.2).
        $c->singleton(PurchaseCheckerInterface::class, static fn (Container $c) =>
            new EntitlementPurchaseChecker($c->get(EntitlementRepositoryInterface::class)));

        // Audit trail (Req 15.5) — used by secure downloads and back-office.
        $c->singleton(AuditLogRepositoryInterface::class, static fn (Container $c) =>
            new PdoAuditLogRepository($conn($c)));
    }

    /**
     * Bind back-office repositories (Phase 8). Admin application services
     * autowire from these plus the identity/commerce bindings above.
     */
    private function registerAdminServices(): void
    {
        $c = $this->container;
        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        $c->singleton(\App\Domain\Support\DisputeRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoDisputeRepository($conn($c)));
        $c->singleton(\App\Domain\Admin\AdminUserRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoAdminUserRepository($conn($c)));
        $c->singleton(\App\Domain\Admin\SettingsRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoSettingsRepository($conn($c)));
        $c->singleton(\App\Domain\Admin\FeatureFlagRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoFeatureFlagRepository($conn($c)));
        $c->singleton(\App\Domain\Admin\ReportRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoReportRepository($conn($c)));
    }

    /**
     * Bind seller/payout repositories (Phase 7). Seller application services
     * autowire from these plus the commerce/ledger bindings above.
     */
    private function registerSellerServices(): void
    {
        $c = $this->container;
        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        $c->singleton(\App\Domain\Seller\SellerProfileRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoSellerProfileRepository($conn($c)));
        $c->singleton(\App\Domain\Seller\PayoutRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoPayoutRepository($conn($c)));
        $c->singleton(\App\Domain\Seller\SellerStatsRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoSellerStatsRepository($conn($c)));
    }

    /**
     * Bind the async pipeline (Phase 9): mailer, notification repositories,
     * the job registry + queue driver + dispatcher/worker, and the scheduler
     * with its recurring tasks. Application services (NotificationService,
     * InvoiceService, handlers) autowire from these bindings.
     */
    private function registerQueueServices(): void
    {
        $c = $this->container;
        $basePath = $this->basePath;
        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        // Transactional mail port — dev logs to disk so the pipeline runs offline.
        $c->singleton(Mailer::class, static function () use ($basePath): Mailer {
            $path = (string) Config::get('mail.log_path', 'storage/logs/mail.log');
            if (!str_starts_with($path, '/')) {
                $path = $basePath . '/' . $path;
            }
            return new LogMailer($path);
        });

        // Notification persistence (Req 13).
        $c->singleton(NotificationRepositoryInterface::class,
            static fn (Container $c) => new PdoNotificationRepository($conn($c)));
        $c->singleton(NotificationPreferenceRepositoryInterface::class,
            static fn (Container $c) => new PdoNotificationPreferenceRepository($conn($c)));

        // Job registry: map serialized job names to container-resolved handlers.
        $c->singleton(JobRegistry::class, static function (Container $c): JobRegistry {
            $registry = new JobRegistry();
            $registry->register('email.send', static fn () => $c->get(SendEmailHandler::class));
            $registry->register('notification.push', static fn () => $c->get(SendNotificationHandler::class));
            $registry->register('invoice.generate', static fn () => $c->get(GenerateInvoiceHandler::class));
            $registry->register('webhook.dispatch', static fn () => $c->get(DispatchWebhookHandler::class));
            return $registry;
        });

        // Queue driver: durable database queue in production, inline sync in dev.
        $c->singleton(QueueDriver::class, static function (Container $c): QueueDriver {
            $driver = (string) Config::get('queue.driver', 'sync');
            if ($driver === 'database') {
                return new DatabaseQueueDriver($c->get(ConnectionManager::class));
            }
            return new SyncQueueDriver($c->get(JobRegistry::class), $c->get(Logger::class));
        });

        $c->singleton(Dispatcher::class,
            static fn (Container $c) => new Dispatcher($c->get(QueueDriver::class)));

        $c->singleton(Worker::class, static fn (Container $c) => new Worker(
            $c->get(QueueDriver::class),
            $c->get(JobRegistry::class),
            $c->get(Logger::class),
        ));

        // Scheduler (Req 18.3): recurring maintenance tasks. An external cron
        // calls `bin/console schedule:run` every minute.
        $c->singleton(Scheduler::class, static function (Container $c): Scheduler {
            $scheduler = new Scheduler($c->get(SettingsRepositoryInterface::class));

            // Clear seller earnings once the refund window has elapsed (Req 11.4).
            $scheduler->register('clear_balances', 1440, static function () use ($c): void {
                $orders = $c->get(OrderRepositoryInterface::class);
                $ledger = $c->get(LedgerService::class);
                $days = (int) Config::get('commerce.refund_window_days', 14);
                $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
                foreach ($orders->paidOrdersBefore($cutoff, 200) as $order) {
                    $items = $orders->items((int) $order['id']);
                    $ledger->clearEarning((int) $order['id'], $items, (string) $order['currency']);
                }
            });

            return $scheduler;
        });
    }

    private function registerHttp(): void
    {
        $c = $this->container;

        $c->singleton(Router::class, static fn (Container $c): Router => new Router($c));

        $c->singleton(Kernel::class, static function (Container $c): Kernel {
            return new Kernel(
                container: $c,
                router: $c->get(Router::class),
                logger: $c->get(Logger::class),
                globalMiddleware: [
                    RequestId::class,
                    SecurityHeaders::class,
                    StartSession::class,
                    VerifyCsrf::class,
                ],
                aliases: [
                    'auth'            => Authenticate::class,
                    'guest'           => RedirectIfAuthenticated::class,
                    'can'             => Authorize::class,
                    'mfa'             => EnsureTwoFactor::class,
                    'throttle'        => RateLimit::class,
                    'seller.verified' => \App\Http\Middleware\SellerVerified::class,
                ],
            );
        });
    }

    private function registerRoutes(): void
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $registrar = require $this->basePath . '/src/Config/routes.php';
        $registrar($router);
    }

    public function kernel(): Kernel
    {
        return $this->container->get(Kernel::class);
    }

    public function container(): Container
    {
        return $this->container;
    }
}
