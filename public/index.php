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

require $basePath . '/vendor/autoload.php';

$app = (new App($basePath))->boot();

$request = Request::fromGlobals();
$response = $app->kernel()->handle($request);
$response->send();
