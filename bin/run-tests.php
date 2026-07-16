#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test-suite runner (Req 23.1). Executes every offline test script under
 * tests/ in a stable order, in isolated subprocesses, and aggregates the
 * result. Exits non-zero if any suite fails, so CI can gate on it.
 *
 * Usage:
 *   php bin/run-tests.php            # run all suites
 *   php bin/run-tests.php phase13    # run a single suite
 */

$root = dirname(__DIR__);
$only = $argv[1] ?? null;

// Deterministic execution order: foundational suites first.
$suites = [
    'smoke',
    'phase2', 'http_auth',
    'phase3', 'http_catalog',
    'phase4', 'phase5', 'phase6', 'phase7', 'phase8',
    'phase9', 'phase10', 'phase11', 'phase12', 'phase13', 'phase16',
    'affiliate', 'affiliate_payout', 'gallery', 'e2e', 'env', 'security', 'security_authz',
];

if ($only !== null) {
    $only = preg_replace('/\.php$/', '', $only);
    $suites = array_values(array_filter($suites, static fn ($s) => $s === $only));
    if ($suites === []) {
        fwrite(STDERR, "Unknown suite: {$only}\n");
        exit(2);
    }
}

$php = PHP_BINARY;
$passed = 0;
$failed = [];
$start = microtime(true);

fwrite(STDOUT, "Running " . count($suites) . " test suite(s)\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n");

foreach ($suites as $suite) {
    $file = $root . '/tests/' . $suite . '.php';
    if (!is_file($file)) {
        fwrite(STDOUT, sprintf("  SKIP  %-16s (not found)\n", $suite));
        continue;
    }

    $t0 = microtime(true);
    $output = [];
    $code = 0;
    exec(sprintf('%s %s 2>&1', escapeshellarg($php), escapeshellarg($file)), $output, $code);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($code === 0) {
        $passed++;
        fwrite(STDOUT, sprintf("  PASS  %-16s %5dms\n", $suite, $ms));
    } else {
        $failed[] = $suite;
        fwrite(STDOUT, sprintf("  FAIL  %-16s %5dms\n", $suite, $ms));
        // Echo the failing suite's output for CI logs.
        foreach ($output as $line) {
            fwrite(STDOUT, '        | ' . $line . "\n");
        }
    }
}

$elapsed = number_format(microtime(true) - $start, 2);
fwrite(STDOUT, str_repeat('=', 60) . "\n");
fwrite(STDOUT, sprintf("%d passed, %d failed in %ss\n", $passed, count($failed), $elapsed));

if ($failed !== []) {
    fwrite(STDERR, "Failed suites: " . implode(', ', $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "All suites passed.\n");
exit(0);
