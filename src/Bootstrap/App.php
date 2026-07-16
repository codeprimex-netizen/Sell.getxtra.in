<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\Config;
use App\Config\Env;
use App\Http\Kernel;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Router;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Persistence\ConnectionManager;

/**
 * Application bootstrapper.
 *
 * Loads env + config, builds the DI container with core service bindings,
 * registers routes, and produces a ready-to-run HTTP Kernel.
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

        $this->container = Container::getInstance();
        $this->registerCoreServices();
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

        $c->singleton(Router::class, static fn (Container $c): Router => new Router($c));

        $c->singleton(Kernel::class, static function (Container $c): Kernel {
            return new Kernel(
                container: $c,
                router: $c->get(Router::class),
                logger: $c->get(Logger::class),
                globalMiddleware: [
                    RequestId::class,
                    SecurityHeaders::class,
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
