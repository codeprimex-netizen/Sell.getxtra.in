#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Enforce a minimum line-coverage threshold on critical modules (Req 24.1).
 * Parses a Clover report produced by PHPUnit and fails CI if coverage of the
 * critical paths falls below the threshold.
 *
 * Usage:
 *   php bin/coverage-check.php build/coverage/clover.xml 75
 */

$cloverPath = $argv[1] ?? 'build/coverage/clover.xml';
$threshold = (float) ($argv[2] ?? 75);

// Coverage of these paths is gated; the rest is measured but not blocking.
$critical = [
    'src/Domain/Commerce',
    'src/Application/Commerce',
    'src/Application/Privacy',
    'src/Application/Api',
    'src/Application/Identity',
];

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Clover report not found at {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Could not parse clover report.\n");
    exit(2);
}

$covered = 0;
$total = 0;

foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = str_replace('\\', '/', (string) $file['name']);
    $isCritical = false;
    foreach ($critical as $path) {
        if (str_contains($name, $path)) {
            $isCritical = true;
            break;
        }
    }
    if (!$isCritical) {
        continue;
    }
    $metrics = $file->metrics;
    if ($metrics === null) {
        continue;
    }
    $total += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}

if ($total === 0) {
    fwrite(STDERR, "No critical-module statements found in the coverage report.\n");
    exit(2);
}

$pct = round(($covered / $total) * 100, 2);
fwrite(STDOUT, sprintf("Critical-module line coverage: %.2f%% (%d/%d) — threshold %.0f%%\n", $pct, $covered, $total, $threshold));

if ($pct + 1e-9 < $threshold) {
    fwrite(STDERR, "FAIL: coverage below threshold.\n");
    exit(1);
}

fwrite(STDOUT, "OK: coverage meets threshold.\n");
exit(0);
