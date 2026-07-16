<?php

declare(strict_types=1);

/**
 * Static application security review (Req 14.1 / 14.9) — a lightweight SAST
 * guard run in CI. Fails the build on risky patterns: raw superglobal access,
 * code-execution sinks, weak password hashing, un-prepared persistence, and
 * missing baseline security controls. No DB, no bootstrap.
 * Run: php tests/security.php
 */

$root = dirname(__DIR__);
$srcDir = $root . '/src';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

/** @return array<int,string> absolute paths of every PHP file under $dir */
$phpFiles = static function (string $dir): array {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
};

$srcFiles = $phpFiles($srcDir);
$rel = static fn (string $p): string => str_replace($root . '/', '', $p);

echo "=== Security static-analysis guard (" . count($srcFiles) . " source files) ===\n";

// ── 1. Superglobal access is confined to the HTTP request adapter ──
echo "\n-- Input handling --\n";
$superglobalAllowlist = [
    'src/Http/Request.php',
    'src/Http/UploadedFile.php',
    'src/Http/Session/CacheSessionStore.php', // HTTP session adapter: reads its own cookie
];
$superglobalOffenders = [];
foreach ($srcFiles as $file) {
    if (in_array($rel($file), $superglobalAllowlist, true)) {
        continue;
    }
    if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|FILES)\b/', (string) file_get_contents($file))) {
        $superglobalOffenders[] = $rel($file);
    }
}
$check('superglobals accessed only via Request adapter', $superglobalOffenders === [], implode(', ', $superglobalOffenders));

// ── 2. No code-execution sinks ─────────────────────────────────────
echo "\n-- Dangerous sinks --\n";
$sinkOffenders = [];
foreach ($srcFiles as $file) {
    if (preg_match('/\b(eval|create_function|proc_open|passthru|shell_exec)\s*\(/', (string) file_get_contents($file))) {
        $sinkOffenders[] = $rel($file);
    }
}
$check('no eval/create_function/shell exec sinks', $sinkOffenders === [], implode(', ', $sinkOffenders));

// ── 3. No weak hashing of passwords/tokens ─────────────────────────
echo "\n-- Password hashing --\n";
$weakPwOffenders = [];
foreach ($srcFiles as $file) {
    foreach (file($file) ?: [] as $n => $line) {
        if (preg_match('/(md5|sha1)\s*\(/i', $line) && preg_match('/pass(word|wd)?|secret_hash|credential/i', $line)) {
            $weakPwOffenders[] = $rel($file) . ':' . ($n + 1);
        }
    }
}
$check('no md5/sha1 used for passwords/credentials', $weakPwOffenders === [], implode(', ', $weakPwOffenders));

$usesPasswordHash = false;
foreach ($srcFiles as $file) {
    if (str_contains((string) file_get_contents($file), 'password_hash(')) {
        $usesPasswordHash = true;
        break;
    }
}
$check('password hashing uses password_hash()', $usesPasswordHash);

// ── 4. Persistence uses prepared statements ────────────────────────
echo "\n-- Data access --\n";
$repoFiles = array_values(array_filter($srcFiles, static fn ($f) =>
    str_contains($f, '/Infrastructure/Persistence/Pdo') && str_ends_with($f, 'Repository.php')));
$unprepared = [];
foreach ($repoFiles as $file) {
    $src = (string) file_get_contents($file);
    // A repo either prepares statements itself or inherits via the base Repository.
    if (!str_contains($src, 'prepare(') && !str_contains($src, 'extends Repository')) {
        $unprepared[] = $rel($file);
    }
}
$check('all PDO repositories use prepared statements', count($repoFiles) > 0 && $unprepared === [], implode(', ', $unprepared));

// No SQL keyword directly concatenated with a variable ( "... " . $var ) in repos.
$concatSql = [];
foreach ($repoFiles as $file) {
    foreach (file($file) ?: [] as $n => $line) {
        if (preg_match('/(SELECT|INSERT|UPDATE|DELETE|WHERE|VALUES)\b.*"\s*\.\s*\$/i', $line)) {
            $concatSql[] = $rel($file) . ':' . ($n + 1);
        }
    }
}
$check('no string-concatenated SQL in repositories', $concatSql === [], implode(', ', $concatSql));

// ── 5. Baseline HTTP security controls are present ─────────────────
echo "\n-- Baseline controls --\n";
$headers = (string) file_get_contents($srcDir . '/Http/Middleware/SecurityHeaders.php');
$check('CSP is set', str_contains($headers, 'Content-Security-Policy'));
$check('CSP does not allow unsafe-inline scripts', !str_contains($headers, "script-src 'self' 'unsafe-inline'"));
$check('HSTS is set', str_contains($headers, 'Strict-Transport-Security'));
$check('X-Content-Type-Options is set', str_contains($headers, 'X-Content-Type-Options'));
$check('frame protection is set', str_contains($headers, 'X-Frame-Options') || str_contains($headers, 'frame-ancestors'));

$sessionStore = (string) file_get_contents($srcDir . '/Http/Session/NativeSessionStore.php');
$check('session cookie is HttpOnly', (bool) preg_match("/'httponly'\s*=>\s*true/", $sessionStore));
$check('session cookie sets SameSite', str_contains($sessionStore, "'samesite'"));
$check('session cookie honours secure flag', str_contains($sessionStore, "'secure'"));

$csrf = (string) file_get_contents($srcDir . '/Http/Middleware/VerifyCsrf.php');
$check('CSRF protection enforces a token', str_contains($csrf, 'verifyCsrf'));
// Only signed-webhook and API (key/HMAC authenticated) prefixes may be CSRF-exempt.
preg_match_all("/'(\/[a-z\/]+)'/", (string) (strstr($csrf, 'EXEMPT_PREFIXES') ?: ''), $m);
$exempt = $m[1] ?? [];
$allowedExempt = ['/payments/', '/api/'];
$badExempt = array_diff($exempt, $allowedExempt);
$check('only expected prefixes are CSRF-exempt', $badExempt === [], implode(', ', $badExempt));

// ── Summary ────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "OK — security static analysis passed.\n";
    exit(0);
}
echo "FAILED — {$failures} security check(s) failed.\n";
exit(1);
