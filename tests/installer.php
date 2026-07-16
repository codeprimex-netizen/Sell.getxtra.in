<?php

declare(strict_types=1);

/**
 * Offline tests for the advanced installer's pure logic: requirements
 * reporting, app-key generation, .env rendering (with round-trip parsing),
 * admin validation, and the lock lifecycle. Database-backed steps
 * (testDatabase/migrate/seed/createAdmin) require a live MySQL server and are
 * exercised in CI/integration, not here. Run: php tests/installer.php
 */

use App\Config\Env;
use App\Console\Installer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Advanced installer tests ===\n\n";

// Scratch project root so we never touch the real repo files/lock.
$base = sys_get_temp_dir() . '/gx_installer_' . bin2hex(random_bytes(4));
foreach (['', '/storage', '/storage/logs', '/storage/cache', '/storage/tmp', '/storage/metrics'] as $d) {
    @mkdir($base . $d, 0775, true);
}
file_put_contents($base . '/.env.example', implode("\n", [
    '# Example env',
    'APP_NAME="Code.getxtra.in"',
    'APP_ENV=production',
    'APP_URL=https://www.code.getxtra.in  # base url',
    'APP_KEY=',
    'DB_HOST=127.0.0.1                # db host',
    'DB_DATABASE=code_getxtra',
    'DB_USERNAME=root',
    'DB_PASSWORD=',
    'MAIL_DRIVER=log',
]) . "\n");

$installer = new Installer($base);

// 1. Requirements ---------------------------------------------------
$reqs = $installer->requirements();
$check('requirements() returns a non-empty list', $reqs !== []);
$phpCheck = array_values(array_filter($reqs, static fn ($c) => str_starts_with($c['name'], 'PHP >=')))[0] ?? null;
$check('PHP version check present and passing on this runtime', $phpCheck !== null && $phpCheck['ok'] === true);
$check('pdo_mysql listed as required', (bool) array_filter($reqs, static fn ($c) => $c['name'] === 'PHP extension: pdo_mysql' && $c['required']));
$check('requirementsSatisfied() true for the temp project', $installer->requirementsSatisfied($reqs) === true);

// testDatabase failure paths (no live MySQL required).
$check('testDatabase rejects an empty/invalid database name',
    $installer->testDatabase(['host' => '127.0.0.1', 'database' => '', 'username' => 'u', 'password' => 'p'])['ok'] === false);
$unreachable = $installer->testDatabase(['host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => 'p']);
$check('testDatabase fails gracefully when the server is unreachable (no exception)',
    $unreachable['ok'] === false && $unreachable['message'] !== '');

// 2. App key --------------------------------------------------------
$key = $installer->generateAppKey();
$rawOk = str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7), true)) === 32;
$check('generateAppKey() yields a base64: 256-bit key', $rawOk, $key);
$check('two generated keys differ', $installer->generateAppKey() !== $installer->generateAppKey());

// 3. .env rendering -------------------------------------------------
$example = (string) file_get_contents($base . '/.env.example');
$overrides = $installer->buildEnvOverrides([
    'db'  => ['host' => 'db.internal', 'port' => 3307, 'database' => 'shop', 'username' => 'appuser', 'password' => 'p@ss word#1'],
    'app' => ['url' => 'https://www.code.getxtra.in', 'name' => 'Code.getxtra.in', 'env' => 'production', 'debug' => false],
], $key);
$rendered = $installer->renderEnv($example, $overrides);

$check('rendered .env overrides DB_HOST', str_contains($rendered, 'DB_HOST=db.internal'));
$check('rendered .env keeps a documentation comment line', str_contains($rendered, '# Example env'));
$check('rendered .env quotes a value containing spaces/hash', str_contains($rendered, 'DB_PASSWORD="p@ss word#1"'));
$check('rendered .env includes the generated APP_KEY', str_contains($rendered, 'APP_KEY=' . $key));
$check('rendered .env appends per-install secrets', str_contains($rendered, 'PAYMENT_OFFLINE_SECRET=') && str_contains($rendered, 'METRICS_TOKEN='));

// 3b. Round-trip: write + parse the rendered env back through Env.
$envFile = $base . '/.env';
file_put_contents($envFile, $rendered);
Env::load($envFile);
$check('round-trip: APP_URL parsed intact', Env::get('APP_URL') === 'https://www.code.getxtra.in', (string) Env::get('APP_URL'));
$check('round-trip: DB_HOST parsed intact', Env::get('DB_HOST') === 'db.internal', (string) Env::get('DB_HOST'));
$check('round-trip: quoted password with space+hash preserved', Env::get('DB_PASSWORD') === 'p@ss word#1', (string) Env::get('DB_PASSWORD'));
$check('round-trip: APP_KEY preserved', Env::get('APP_KEY') === $key);

// 4. Admin validation ----------------------------------------------
$threw = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable) {
        return true;
    }
};
$check('assertAdmin rejects empty name', $threw(static fn () => $installer->assertAdmin(['name' => '', 'email' => 'a@b.com', 'password' => 'longenough1'])));
$check('assertAdmin rejects invalid email', $threw(static fn () => $installer->assertAdmin(['name' => 'A', 'email' => 'not-an-email', 'password' => 'longenough1'])));
$check('assertAdmin rejects short password', $threw(static fn () => $installer->assertAdmin(['name' => 'A', 'email' => 'a@b.com', 'password' => 'short'])));
$check('assertAdmin accepts valid input', !$threw(static fn () => $installer->assertAdmin(['name' => 'Admin', 'email' => 'admin@code.getxtra.in', 'password' => 'a-strong-password'])));

// 5. Lock lifecycle -------------------------------------------------
$check('isInstalled() false before locking', $installer->isInstalled() === false);
$installer->lock(['admin_email' => 'admin@code.getxtra.in', 'app_url' => 'https://www.code.getxtra.in']);
$check('isInstalled() true after lock()', $installer->isInstalled() === true);
$lockData = json_decode((string) file_get_contents($installer->lockFile()), true);
$check('lock file is valid JSON with metadata', is_array($lockData) && ($lockData['version'] ?? '') === '1.0.0' && ($lockData['admin_email'] ?? '') === 'admin@code.getxtra.in');
$check('run() refuses when already installed (no --force)', $threw(static fn () => $installer->run(['db' => [], 'app' => [], 'admin' => []])));

// Cleanup.
@unlink($base . '/.env');
@unlink($base . '/.env.example');
@unlink($installer->lockFile());
foreach (array_reverse(['/storage/metrics', '/storage/tmp', '/storage/cache', '/storage/logs', '/storage', '']) as $d) {
    @rmdir($base . $d);
}

echo "\n";
echo $failures === 0 ? "OK — installer logic verified.\n" : "FAILED — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
