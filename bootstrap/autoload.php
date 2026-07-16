<?php

declare(strict_types=1);

/**
 * Application bootstrap autoloader.
 *
 * Prefers Composer's optimized autoloader when it is present. On hosts WITHOUT
 * Composer (typical basic shared / cPanel hosting) it transparently falls back
 * to a minimal PSR-4 autoloader. This project has NO third-party runtime
 * dependencies, so the fallback is fully sufficient — the site and installer
 * run correctly even if `composer install` was never executed.
 *
 * Tests may define APP_BASE_PATH before including this file to point the
 * loader at a different project root.
 */

$appBasePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);

// Under a non-CLI SAPI (e.g. the web installer running migrations/seeders via
// php-fpm) the CLI stream constants do not exist. Some console-oriented classes
// (MigrationRunner, Seeder) write progress with fwrite(STDOUT, ...); without
// these constants that is a fatal "Undefined constant STDOUT". Point them at a
// discarded in-memory stream so such writes are harmless and never leak into
// the HTML response. In CLI, PHP already defines them, so this is skipped.
if (PHP_SAPI !== 'cli') {
    if (!defined('STDOUT')) {
        define('STDOUT', fopen('php://temp', 'wb'));
    }
    if (!defined('STDERR')) {
        define('STDERR', fopen('php://temp', 'wb'));
    }
    if (!defined('STDIN')) {
        define('STDIN', fopen('php://temp', 'rb'));
    }
}

$composerAutoload = $appBasePath . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
    return;
}

// ── Composer-free fallback ─────────────────────────────────────────
spl_autoload_register(static function (string $class) use ($appBasePath): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $appBasePath . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Composer normally loads this via its "files" autoload; do it manually here.
$helpers = $appBasePath . '/src/Support/helpers.php';
if (is_file($helpers)) {
    require $helpers;
}
