<?php

declare(strict_types=1);

/**
 * Root installer shim so the wizard opens at the MAIN DOMAIN root
 * (https://your-domain/install.php) even when the document root is the
 * project root rather than public/. Delegates to public/install.php, which
 * derives the base path from its own location.
 *
 * Delete this file (and public/install.php) after installation completes.
 */

require __DIR__ . '/public/install.php';
