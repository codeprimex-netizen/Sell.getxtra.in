<?php

declare(strict_types=1);

/**
 * Operational guard: every environment variable the app reads via Config must
 * be documented in .env.example, and the template must be well-formed. Keeps
 * config and its documentation from drifting. Run: php tests/env.php
 */

$root = dirname(__DIR__);
$configSrc = (string) file_get_contents($root . '/src/Config/Config.php');
$envExample = (string) file_get_contents($root . '/.env.example');

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== .env.example coverage guard ===\n\n";

// 1. Extract every Env::get('KEY', ...) referenced in Config.
preg_match_all("/Env::get\\('([A-Z0-9_]+)'/", $configSrc, $m);
$configKeys = array_values(array_unique($m[1]));
sort($configKeys);
$check('config references at least one env key', $configKeys !== []);

// 2. Extract keys documented in .env.example (KEY=... lines, ignoring comments).
$documented = [];
foreach (preg_split('/\R/', $envExample) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (preg_match('/^([A-Z0-9_]+)\s*=/', $line, $mm)) {
        $documented[$mm[1]] = true;
    }
}
$check('.env.example defines KEY=value entries', $documented !== []);

// 3. Every config key must be documented.
$missing = array_values(array_filter($configKeys, static fn ($k) => !isset($documented[$k])));
$check('every config env key is documented in .env.example', $missing === [], implode(', ', $missing));

// 4. No duplicate keys in .env.example.
preg_match_all('/^([A-Z0-9_]+)\s*=/m', $envExample, $dm);
$dupes = array_values(array_filter(array_count_values($dm[1]), static fn ($n) => $n > 1));
$dupeKeys = array_keys(array_filter(array_count_values($dm[1]), static fn ($n) => $n > 1));
$check('no duplicate keys in .env.example', $dupes === [], implode(', ', $dupeKeys));

// 5. .env.example must not contain obvious real secrets (empty or placeholder).
$leaky = [];
foreach (preg_split('/\R/', $envExample) ?: [] as $line) {
    // Capture the value up to any inline comment/whitespace; empty = fine.
    if (preg_match('/^(RAZORPAY_KEY_SECRET|STRIPE_SECRET|S3_SECRET|DB_PASSWORD|APP_KEY)\s*=\s*([^#\s]+)/', trim($line), $mm)) {
        // Allow obvious dev placeholders only.
        if (!in_array(strtolower($mm[2]), ['secret', 'changeme'], true)) {
            $leaky[] = $mm[1];
        }
    }
}
$check('no real-looking secrets committed in .env.example', $leaky === [], implode(', ', $leaky));

// 6. Env parser regression: inline comments must be stripped from values so a
//    freshly-copied .env (whose lines carry `# ...` documentation) yields clean
//    values, while a '#' that is part of a value (e.g. a token) is preserved.
require_once $root . '/vendor/autoload.php';
$tmp = tempnam(sys_get_temp_dir(), 'envtest');
file_put_contents($tmp, implode("\n", [
    'APP_ENV=local  # local | staging | production',
    'APP_NAME="Sell.getxtra.in"  # brand name',
    'METRICS_TOKEN=                      # optional bearer token',
    "SESSION_COOKIE='sell_session'  # cookie",
    'API_TOKEN=abc#123def',
    'APP_URL=https://sell.getxtra.in',
]) . "\n");
\App\Config\Env::load($tmp);
@unlink($tmp);
$check('inline comment stripped from unquoted value', \App\Config\Env::get('APP_ENV') === 'local', (string) \App\Config\Env::get('APP_ENV'));
$check('inline comment ignored after quoted value', \App\Config\Env::get('APP_NAME') === 'Sell.getxtra.in', (string) \App\Config\Env::get('APP_NAME'));
$check('comment-only value resolves to empty (default applies)', \App\Config\Env::get('METRICS_TOKEN', 'DEFAULT') === 'DEFAULT');
$check('single-quoted value unwrapped, trailing comment ignored', \App\Config\Env::get('SESSION_COOKIE') === 'sell_session', (string) \App\Config\Env::get('SESSION_COOKIE'));
$check("'#' inside an unquoted value is preserved", \App\Config\Env::get('API_TOKEN') === 'abc#123def', (string) \App\Config\Env::get('API_TOKEN'));
$check('plain URL value parsed intact', \App\Config\Env::get('APP_URL') === 'https://sell.getxtra.in', (string) \App\Config\Env::get('APP_URL'));

echo "\nConfig keys: " . count($configKeys) . " | Documented: " . count($documented) . "\n";
echo $failures === 0 ? "OK — env template is complete and clean.\n" : "FAILED — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
