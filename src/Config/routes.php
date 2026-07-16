<?php

declare(strict_types=1);

use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

/**
 * Route registration. Returns a callable that receives the Router.
 * Handlers may be [Controller::class, 'method'] or closures.
 *
 * Route groups for auth, catalog, cart/checkout, seller, admin, and the
 * versioned API are added in their respective phases.
 */
return static function (Router $router): void {
    // Storefront
    $router->get('/', [HomeController::class, 'index']);

    // Health / readiness probes (Req 15.4)
    $router->get('/healthz', [HealthController::class, 'live']);
    $router->get('/readyz', [HealthController::class, 'ready']);

    // API version banner (surface expands in Phase 10)
    $router->get('/api/v1/ping', static fn (Request $r): Response =>
        Response::json([
            'data' => ['pong' => true, 'version' => 'v1'],
            'meta' => ['request_id' => $r->attribute('request_id')],
        ]));
};
