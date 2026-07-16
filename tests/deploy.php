<?php

declare(strict_types=1);

/**
 * Deployment/bootstrap tests: the Composer-optional autoloader (so the app and
 * installer run on hosts without Composer), the root shims + secure .htaccess
 * (so it works AND stays safe when the document root is the project root, e.g.
 * cPanel public_html), and the first-run installer redirect. Run:
 *   php tests/deploy.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

$rrmdir = static function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? $rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
};

echo "=== Deployment / bootstrap tests ===\n\n";

$php = PHP_BINARY;
$boot = $root . '/bootstrap/autoload.php';
$check('bootstrap/autoload.php exists', is_file($boot));

// Scratch project root (no vendor/) to exercise the Composer-free fallback.
$tmp = sys_get_temp_dir() . '/gx_deploy_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/src/Demo', 0775, true);
@mkdir($tmp . '/src/Support', 0775, true);
file_put_contents($tmp . '/src/Demo/Thing.php', "<?php\nnamespace App\\Demo;\nfinal class Thing { public function ping(): string { return 'pong'; } }\n");
file_put_contents($tmp . '/src/Support/helpers.php', "<?php\n// test helpers file\n");

$runner = $tmp . '/run_fallback.php';
file_put_contents(
    $runner,
    "<?php\ndefine('APP_BASE_PATH', " . var_export($tmp, true) . ");\nrequire " . var_export($boot, true) . ";\n"
    . "echo (new \\App\\Demo\\Thing())->ping();\n",
);
$out = [];
$code = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $code);
$check('fallback PSR-4 autoloader loads App\\ classes without Composer', trim(implode("\n", $out)) === 'pong', implode(' ', $out));

// With a vendor/autoload.php present, the bootstrap must prefer it.
@mkdir($tmp . '/vendor', 0775, true);
file_put_contents($tmp . '/vendor/autoload.php', "<?php\necho 'COMPOSER_USED';\n");
$runner2 = $tmp . '/run_composer.php';
file_put_contents(
    $runner2,
    "<?php\ndefine('APP_BASE_PATH', " . var_export($tmp, true) . ");\nrequire " . var_export($boot, true) . ";\n",
);
$out2 = [];
exec(escapeshellarg($php) . ' ' . escapeshellarg($runner2) . ' 2>&1', $out2);
$check('bootstrap prefers Composer autoload when vendor/ exists', str_contains(implode('', $out2), 'COMPOSER_USED'));

$rrmdir($tmp);

// ── Root shims (document-root = project-root support) ──────────────
$rootIndex = (string) @file_get_contents($root . '/index.php');
$check('root index.php shims to public/index.php', str_contains($rootIndex, "require __DIR__ . '/public/index.php'"));
$rootInstall = (string) @file_get_contents($root . '/install.php');
$check('root install.php shims to public/install.php', str_contains($rootInstall, "require __DIR__ . '/public/install.php'"));

// ── Root .htaccess: routing + hardening ────────────────────────────
$ht = (string) @file_get_contents($root . '/.htaccess');
$check('root .htaccess denies .env', str_contains($ht, '^\.env'));
$check('root .htaccess blocks app internals (src/storage/vendor/bootstrap)',
    str_contains($ht, 'bootstrap') && str_contains($ht, 'storage') && str_contains($ht, 'vendor') && str_contains($ht, 'src'));
$check('root .htaccess routes to the front controller', str_contains($ht, 'RewriteRule ^ index.php'));

// ── Front controller wiring ────────────────────────────────────────
$pubIndex = (string) @file_get_contents($root . '/public/index.php');
$check('public/index.php uses the bootstrap autoloader', str_contains($pubIndex, "/bootstrap/autoload.php"));
$check('public/index.php redirects to the installer on first run',
    str_contains($pubIndex, 'installed.lock') && str_contains($pubIndex, "Location: /install.php"));

$pubInstall = (string) @file_get_contents($root . '/public/install.php');
$check('install.php has a friendly fatal handler + PHP version guard',
    str_contains($pubInstall, 'register_shutdown_function') && str_contains($pubInstall, 'PHP_VERSION_ID < 80200'));
$check('install.php uses the bootstrap autoloader', str_contains($pubInstall, "/bootstrap/autoload.php"));
$check('installer carries DB credentials forward as hidden fields',
    str_contains($pubInstall, 'name="db_password"') && str_contains($pubInstall, 'name="db_username"'));
$check('installer finalizes in a single POST (no session-only credentials)',
    str_contains($pubInstall, "\$step === 'install'") && str_contains($pubInstall, "\$_POST['db_password']"));

// ── bin/console ────────────────────────────────────────────────────
$console = (string) @file_get_contents($root . '/bin/console');
$check('bin/console uses the bootstrap autoloader', str_contains($console, "/bootstrap/autoload.php"));

echo "\n";
echo $failures === 0 ? "OK — deployment bootstrap verified.\n" : "FAILED — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
