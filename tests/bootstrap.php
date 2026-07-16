<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap (Req 24.1). Loads the autoloader, boots config, and makes
 * the in-memory test doubles available to the PHPUnit suites.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Shared in-memory adapters (also used by the offline script suites).
foreach (glob(__DIR__ . '/Fakes/*.php') ?: [] as $fake) {
    require_once $fake;
}

App\Config\Config::boot();
