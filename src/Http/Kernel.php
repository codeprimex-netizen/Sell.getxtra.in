<?php

declare(strict_types=1);

namespace App\Http;

use App\Bootstrap\Container;
use App\Config\Config;
use App\Infrastructure\Observability\Logger;
use Closure;
use Throwable;

/**
 * HTTP kernel: runs the global middleware pipeline, then dispatches the
 * matched route (with its per-route middleware). Central error boundary.
 */
final class Kernel
{
    /** @var array<int, class-string> Global middleware, outermost first. */
    private array $globalMiddleware;

    public function __construct(
        private Container $container,
        private Router $router,
        private Logger $logger,
        array $globalMiddleware = [],
    ) {
        $this->globalMiddleware = $globalMiddleware;
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request);

            $core = function (Request $request) use ($match): Response {
                if ($match === null) {
                    return $this->notFound($request);
                }
                return $this->runRouteMiddleware($request, $match);
            };

            return $this->runPipeline($request, $this->globalMiddleware, $core);
        } catch (Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    /**
     * @param array{handler:mixed, params:array<string,string>, middleware:array<int,string>} $match
     */
    private function runRouteMiddleware(Request $request, array $match): Response
    {
        $core = fn (Request $request): Response =>
            $this->router->dispatch($match['handler'], $request, $match['params']);

        return $this->runPipeline($request, $match['middleware'], $core);
    }

    /**
     * @param array<int, class-string> $middleware
     */
    private function runPipeline(Request $request, array $middleware, Closure $core): Response
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (Closure $next, string $class): Closure {
                return function (Request $request) use ($next, $class): Response {
                    $instance = $this->container->get($class);
                    return $instance->handle($request, $next);
                };
            },
            $core
        );

        return $pipeline($request);
    }

    private function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::apiError('not_found', 'Resource not found.', 404);
        }
        return Response::html($this->errorPage(404, 'Page Not Found'), 404);
    }

    private function handleException(Request $request, Throwable $e): Response
    {
        $this->logger->error('Unhandled exception', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        $debug = (bool) Config::get('app.debug', false);

        if ($request->wantsJson()) {
            $data = $debug ? ['detail' => $e->getMessage()] : [];
            return Response::apiError('server_error', 'An unexpected error occurred.', 500, $data);
        }

        $detail = $debug ? '<pre>' . e($e->getMessage()) . '</pre>' : '';
        return Response::html($this->errorPage(500, 'Server Error', $detail), 500);
    }

    private function errorPage(int $status, string $title, string $detail = ''): string
    {
        return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . "<title>{$status} · {$title}</title>"
            . "<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;"
            . "display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}"
            . ".card{text-align:center;padding:2rem}h1{font-size:4rem;margin:0;color:#38bdf8}"
            . "p{color:#94a3b8}</style></head><body><div class=\"card\">"
            . "<h1>{$status}</h1><p>" . e($title) . "</p>{$detail}</div></body></html>";
    }
}
