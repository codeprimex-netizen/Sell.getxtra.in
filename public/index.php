<?php

declare(strict_types=1);

/**
 * Front controller — the single entry point for all HTTP traffic.
 *
 * Nginx/Apache rewrite every non-file request here; the App bootstraps
 * services and the Kernel resolves the route through the middleware pipeline.
 */

use App\Bootstrap\App;
use App\Http\Request;

$basePath = dirname(__DIR__);

require $basePath . '/bootstrap/autoload.php';

// First run: if the app is not installed yet, send visitors straight to the
// installer (skipping infrastructure probes). The lock file is written once
// installation completes, after which this redirect never fires again.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (
    !is_file($basePath . '/storage/installed.lock')
    && is_file(__DIR__ . '/install.php')
    && !in_array($requestPath, ['/healthz', '/readyz', '/metrics'], true)
    && !str_starts_with($requestPath, '/install')
) {
    header('Location: /install.php');
    exit;
}

$app = (new App($basePath))->boot();

$request = Request::fromGlobals();
$response = $app->kernel()->handle($request);
$response->send();
