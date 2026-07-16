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
                    'auth'     => Authenticate::class,
                    'guest'    => RedirectIfAuthenticated::class,
                    'can'      => Authorize::class,
                    'mfa'      => EnsureTwoFactor::class,
                    'throttle' => RateLimit::class,
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
