<?php

declare(strict_types=1);

/**
 * Root front-controller shim.
 *
 * The recommended setup points the web server's document root at the public/
 * directory. On basic shared/cPanel hosting where the document root is the
 * PROJECT ROOT (e.g. files extracted straight into public_html), this shim
 * forwards to the real front controller. public/index.php derives the base
 * path from its own location, so everything resolves correctly either way.
 */

require __DIR__ . '/public/index.php';
