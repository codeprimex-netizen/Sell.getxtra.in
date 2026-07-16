<?php

declare(strict_types=1);

namespace App\Http;

use App\Bootstrap\Container;
use Closure;
use RuntimeException;

/**
 * Custom router with parameter support ({id}, {slug}) and per-route
 * middleware. Handlers may be closures or [Controller::class, 'method'].
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:array<int,string>, handler:mixed, middleware:array<int,string>}> */
    private array $routes = [];

    public function __construct(private Container $container)
    {
    }

    /** @param array<int,string> $middleware */
    public function get(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function post(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function put(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function delete(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function add(string $method, string $pattern, mixed $handler, array $middleware = []): void
    {
        $normalized = '/' . trim($pattern, '/');
        $params = [];

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $normalized
        );

        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $normalized,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Match the request to a route. Returns null on no match.
     *
     * @return array{handler:mixed, params:array<string,string>, middleware:array<int,string>}|null
     */
    public function match(Request $request): ?array
    {
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches);
                $params = array_combine($route['params'], $matches) ?: [];
                return [
                    'handler'    => $route['handler'],
                    'params'     => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

        return null;
    }

    /**
     * Invoke a matched handler with resolved parameters.
     *
     * @param array<string,string> $params
     */
    public function dispatch(mixed $handler, Request $request, array $params): Response
    {
        foreach ($params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        if ($handler instanceof Closure) {
            $result = $handler($request, ...array_values($params));
            return $this->toResponse($result);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $result = $controller->{$method}($request, ...array_values($params));
            return $this->toResponse($result);
        }

        throw new RuntimeException('Invalid route handler.');
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
