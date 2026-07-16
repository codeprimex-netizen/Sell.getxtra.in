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
use App\Application\Api\ApiKeyService;
use App\Application\Api\WebhookService;
use App\Domain\Api\ApiKeyRepositoryInterface;
use App\Domain\Api\WebhookSubscriptionRepositoryInterface;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Middleware\ApiScope;
use App\Http\Middleware\AuthenticateApiKey;
use App\Infrastructure\Persistence\PdoApiKeyRepository;
use App\Infrastructure\Persistence\PdoWebhookSubscriptionRepository;
use App\Application\Observability\AlertService;
use App\Http\Middleware\CollectMetrics;
use App\Http\Middleware\TraceRequest;
use App\Infrastructure\Observability\Health\CacheHealthCheck;
use App\Infrastructure\Observability\Health\DatabaseHealthCheck;
use App\Infrastructure\Observability\Health\HealthChecker;
use App\Infrastructure\Observability\Health\QueueHealthCheck;
use App\Infrastructure\Observability\Health\SearchHealthCheck;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;
use App\Infrastructure\Observability\Tracing\LogSpanExporter;
use App\Infrastructure\Observability\Tracing\NullSpanExporter;
use App\Infrastructure\Observability\Tracing\SpanExporter;
use App\Infrastructure\Observability\Tracing\Tracer;

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
        $this->registerObservabilityServices();
        $this->registerIdentityServices();
        $this->registerCatalogServices();
        $this->registerCommerceServices();
        $this->registerAdminServices();
        $this->registerSellerServices();
        $this->registerPrivacyServices();
        $this->registerQueueServices();
        $this->registerApiServices();
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
            return new Logger(
                $path,
                (string) Config::get('log.level', 'info'),
                null,
                (string) Config::get('app.name', 'code.getxtra.in'),
                (string) Config::get('app.env', 'production'),
                (bool) Config::get('log.stream', false),
            );
        });

        $c->singleton(ConnectionManager::class, static fn (): ConnectionManager => new ConnectionManager());

        $c->singleton(RateLimiter::class, static fn (): RateLimiter =>
            new RateLimiter($basePath . '/storage/cache/ratelimit'));

        // Shared cache (Req 16.1): Redis in production, file offline/dev. Falls
        // back to file when ext-redis is unavailable so the app still boots.
        $c->singleton(\App\Infrastructure\Cache\CacheInterface::class, static function () use ($basePath): \App\Infrastructure\Cache\CacheInterface {
            $driver = (string) Config::get('cache.driver', 'file');
            if ($driver === 'array') {
                return new \App\Infrastructure\Cache\ArrayCache();
            }
            if ($driver === 'redis' && class_exists(\Redis::class)) {
                try {
                    $redis = new \Redis();
                    $redis->connect((string) Config::get('redis.host', '127.0.0.1'), (int) Config::get('redis.port', 6379));
                    $pw = Config::get('redis.password');
                    if (is_string($pw) && $pw !== '') {
                        $redis->auth($pw);
                    }
                    return new \App\Infrastructure\Cache\RedisCache($redis, (string) Config::get('cache.prefix', 'gx:'));
                } catch (\Throwable) {
                    // fall through to file cache
                }
            }
            return new \App\Infrastructure\Cache\FileCache($basePath . '/storage/cache/data');
        });

        // Asset URLs with CDN + fingerprinting (Req 16.2).
        $c->singleton(\App\Infrastructure\Assets\AssetManager::class, static function () use ($basePath): \App\Infrastructure\Assets\AssetManager {
            return new \App\Infrastructure\Assets\AssetManager(
                $basePath . '/public',
                (string) Config::get('storage.cdn_url', ''),
                (string) Config::get('assets.manifest', ''),
            );
        });

        // Localization (Req 20.4): translator + locale-aware formatter.
        $c->singleton(\App\Infrastructure\I18n\Translator::class, static fn (): \App\Infrastructure\I18n\Translator =>
            new \App\Infrastructure\I18n\Translator(
                $basePath . '/resources/lang',
                (string) Config::get('app.locale', 'en'),
                (string) Config::get('app.fallback_locale', 'en'),
                (array) Config::get('app.supported_locales', ['en']),
            ));
        $c->singleton(\App\Infrastructure\I18n\LocaleFormatter::class, static fn (): \App\Infrastructure\I18n\LocaleFormatter =>
            new \App\Infrastructure\I18n\LocaleFormatter((string) Config::get('app.locale', 'en')));

        // Privacy-aware analytics (Req 20 / 16.3).
        $c->singleton(\App\Application\Analytics\AnalyticsService::class, static fn (Container $c) =>
            new \App\Application\Analytics\AnalyticsService(
                $c->get(MetricsRegistry::class),
                $c->get(Logger::class),
                (string) Config::get('analytics.ga4_id', ''),
            ));

        // SEO sitemap generator (Req 20.3).
        $c->singleton(\App\Application\Seo\SitemapGenerator::class, static fn (): \App\Application\Seo\SitemapGenerator =>
            new \App\Application\Seo\SitemapGenerator((string) Config::get('app.url', 'https://www.code.getxtra.in')));

        // Session store: cache-backed (stateless tier) when configured, else native.
        $c->singleton(SessionStore::class, static function (Container $c): SessionStore {
            if (in_array((string) Config::get('session.driver', 'file'), ['cache', 'redis'], true)) {
                return new \App\Http\Session\CacheSessionStore(
                    $c->get(\App\Infrastructure\Cache\CacheInterface::class),
                    'gx_session',
                    (int) Config::get('session.lifetime', 120),
                    (bool) Config::get('session.secure', true),
                );
            }
            return new NativeSessionStore();
        });

        // Secrets provider (Req 14.6): file/Vault-backed in production, env in dev.
        $c->singleton(\App\Infrastructure\Security\Secrets\SecretsManager::class, static function (): \App\Infrastructure\Security\Secrets\SecretsManager {
            $env = new \App\Infrastructure\Security\Secrets\EnvSecretProvider();
            $driver = (string) Config::get('secrets.driver', 'env');
            $path = (string) Config::get('secrets.path', '');

            $provider = match ($driver) {
                'file'  => new \App\Infrastructure\Security\Secrets\FileSecretProvider($path),
                'chain' => new \App\Infrastructure\Security\Secrets\ChainSecretProvider(
                    new \App\Infrastructure\Security\Secrets\FileSecretProvider($path),
                    $env,
                ),
                default => $env,
            };

            return new \App\Infrastructure\Security\Secrets\SecretsManager($provider);
        });
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
        $c->singleton(CategoryRepositoryInterface::class, static function (Container $c) use ($conn) {
            $repo = new PdoCategoryRepository($conn($c));
            if (!(bool) Config::get('cache.enabled', true)) {
                return $repo;
            }
            // Read-through cache for hot, rarely-changing category data (Req 16.1).
            return new \App\Infrastructure\Persistence\CachedCategoryRepository(
                $repo,
                $c->get(\App\Infrastructure\Cache\CacheInterface::class),
            );
        });
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
        // PurchaseCheckerInterface is bound to the entitlement-backed checker in
        // registerCommerceServices() (Req 7.2) — no null placeholder needed.

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

        // Affiliate / referral program (Req 20.2). AffiliateService autowires
        // from these + LedgerService; PaymentService gets it as an optional dep.
        $c->singleton(\App\Domain\Affiliate\AffiliateRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoAffiliateRepository($conn($c)));
        $c->singleton(\App\Domain\Affiliate\ReferralRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoReferralRepository($conn($c)));

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
     * Bind the observability stack (Phase 12): metrics registry, tracer +
     * span exporter, alerting, and the readiness health checks. HTTP metrics/
     * trace middleware and the queue worker autowire the registry/tracer.
     */
    private function registerObservabilityServices(): void
    {
        $c = $this->container;
        $basePath = $this->basePath;

        $c->singleton(MetricsRegistry::class, static function () use ($basePath): MetricsRegistry {
            $path = (string) Config::get('metrics.path', 'storage/metrics/metrics.json');
            if (!str_starts_with($path, '/')) {
                $path = $basePath . '/' . $path;
            }
            $registry = new MetricsRegistry($path);
            $registry->describe('http_requests_total', 'counter', 'Total HTTP requests by method and status.');
            $registry->describe('http_requests_errors_total', 'counter', 'HTTP 5xx responses by method.');
            $registry->describe('http_request_duration_seconds', 'histogram', 'HTTP request latency in seconds.');
            $registry->describe('jobs_processed_total', 'counter', 'Queue jobs processed successfully.');
            $registry->describe('jobs_dead_lettered_total', 'counter', 'Queue jobs dead-lettered after retries.');
            $registry->describe('job_duration_seconds', 'histogram', 'Queue job processing time in seconds.');
            $registry->describe('alerts_fired_total', 'counter', 'Operational alerts fired by the application.');
            $registry->describe('queue_depth', 'gauge', 'Current depth of the default job queue.');
            return $registry;
        });

        $c->singleton(SpanExporter::class, static function (Container $c): SpanExporter {
            return (bool) Config::get('observability.tracing_enabled', false)
                ? new LogSpanExporter($c->get(Logger::class))
                : new NullSpanExporter();
        });

        $c->singleton(Tracer::class, static fn (Container $c) => new Tracer(
            $c->get(SpanExporter::class),
            (bool) Config::get('observability.tracing_enabled', false),
        ));

        $c->singleton(AlertService::class, static fn (Container $c) =>
            new AlertService($c->get(Logger::class), $c->get(MetricsRegistry::class)));

        $c->singleton(HealthChecker::class, static function (Container $c) use ($basePath): HealthChecker {
            $checker = new HealthChecker();
            $checker->register(new DatabaseHealthCheck($c->get(ConnectionManager::class)), true);
            $checker->register(new CacheHealthCheck($basePath . '/storage/cache/ratelimit'), true);
            $checker->register(new QueueHealthCheck($c->get(QueueDriver::class)), true);
            $checker->register(new SearchHealthCheck($c->get(SearchIndex::class)), false);
            return $checker;
        });
    }

    /**
     * Bind GDPR/DPDP repositories (Phase 11). Consent and data-privacy
     * application services autowire from these.
     */
    private function registerPrivacyServices(): void
    {
        $c = $this->container;
        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        $c->singleton(\App\Domain\Privacy\ConsentRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoConsentRepository($conn($c)));
        $c->singleton(\App\Domain\Privacy\DataRequestRepositoryInterface::class,
            static fn (Container $c) => new \App\Infrastructure\Persistence\PdoDataRequestRepository($conn($c)));
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

        // Transactional mail port (Req 13.1): SMTP in production, disk log in dev.
        $c->singleton(Mailer::class, static function () use ($basePath): Mailer {
            if ((string) Config::get('mail.driver', 'log') === 'smtp') {
                $encryption = (string) Config::get('mail.encryption', 'tls');
                $mime = new \App\Infrastructure\Mail\MimeMessage(
                    (string) Config::get('mail.from_address', 'no-reply@code.getxtra.in'),
                    (string) Config::get('mail.from_name', 'Code.getxtra.in'),
                );
                return new \App\Infrastructure\Mail\SmtpMailer(
                    new \App\Infrastructure\Mail\Smtp\StreamSmtpConnection(
                        (string) Config::get('mail.host', '127.0.0.1'),
                        (int) Config::get('mail.port', 587),
                        $encryption,
                    ),
                    $mime,
                    (string) Config::get('mail.from_address', 'no-reply@code.getxtra.in'),
                    $encryption,
                    (string) Config::get('mail.username', ''),
                    (string) Config::get('mail.password', ''),
                );
            }

            $path = (string) Config::get('mail.log_path', 'storage/logs/mail.log');
            if (!str_starts_with($path, '/')) {
                $path = $basePath . '/' . $path;
            }
            return new LogMailer($path);
        });

        // Invoice document renderer (Req 8.4): real PDF in production, HTML in dev.
        $c->singleton(\App\Infrastructure\Invoice\InvoiceRenderer::class, static function (): \App\Infrastructure\Invoice\InvoiceRenderer {
            return (string) Config::get('invoice.format', 'pdf') === 'html'
                ? new \App\Infrastructure\Invoice\HtmlInvoiceRenderer()
                : new \App\Infrastructure\Invoice\PdfInvoiceRenderer();
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
            $registry->register('privacy.export', static fn () => $c->get(\App\Jobs\Handlers\ProcessDataExportHandler::class));
            $registry->register('privacy.erasure', static fn () => $c->get(\App\Jobs\Handlers\ProcessErasureHandler::class));
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
            static fn (Container $c) => new Dispatcher($c->get(QueueDriver::class), $c->get(Tracer::class)));

        $c->singleton(Worker::class, static fn (Container $c) => new Worker(
            $c->get(QueueDriver::class),
            $c->get(JobRegistry::class),
            $c->get(Logger::class),
            3,
            10,
            $c->get(Tracer::class),
            $c->get(MetricsRegistry::class),
            $c->get(AlertService::class),
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

                // Clear affiliate commissions past the same refund window (Req 20.2).
                $c->get(\App\Application\Affiliate\AffiliatePayoutService::class)->clearDueCommissions($cutoff);
            });

            // Purge expired data-export artifacts (Req 14.8 retention).
            $scheduler->register('privacy.retention', 1440, static function () use ($c): void {
                $ttl = (int) Config::get('privacy.export_ttl_days', 7);
                $c->get(\App\Application\Privacy\DataPrivacyService::class)->purgeExpiredExports($ttl);
            });

            return $scheduler;
        });
    }

    /**
     * Bind the public API layer (Phase 10): API-key + webhook repositories and
     * services, and the OpenAPI controller (which needs the base path). The
     * apikey/scope middleware and API controllers autowire from these.
     */
    private function registerApiServices(): void
    {
        $c = $this->container;
        $basePath = $this->basePath;
        $conn = static fn (Container $c): ConnectionManager => $c->get(ConnectionManager::class);

        $c->singleton(ApiKeyRepositoryInterface::class,
            static fn (Container $c) => new PdoApiKeyRepository($conn($c)));
        $c->singleton(WebhookSubscriptionRepositoryInterface::class,
            static fn (Container $c) => new PdoWebhookSubscriptionRepository($conn($c)));

        $c->singleton(ApiKeyService::class,
            static fn (Container $c) => new ApiKeyService($c->get(ApiKeyRepositoryInterface::class)));
        $c->singleton(WebhookService::class, static fn (Container $c) => new WebhookService(
            $c->get(WebhookSubscriptionRepositoryInterface::class),
            $c->get(Dispatcher::class),
        ));

        $c->singleton(OpenApiController::class,
            static fn (): OpenApiController => new OpenApiController($basePath));
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
                    TraceRequest::class,
                    CollectMetrics::class,
                    SecurityHeaders::class,
                    \App\Http\Middleware\ThrottleGlobal::class,
                    StartSession::class,
                    \App\Http\Middleware\Localize::class,
                    VerifyCsrf::class,
                ],
                aliases: [
                    'auth'            => Authenticate::class,
                    'guest'           => RedirectIfAuthenticated::class,
                    'can'             => Authorize::class,
                    'mfa'             => EnsureTwoFactor::class,
                    'throttle'        => RateLimit::class,
                    'seller.verified' => \App\Http\Middleware\SellerVerified::class,
                    'apikey'          => AuthenticateApiKey::class,
                    'scope'           => ApiScope::class,
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
