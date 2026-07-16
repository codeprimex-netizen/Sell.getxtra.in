<?php

declare(strict_types=1);

/**
 * Web-based installation wizard for Code.getxtra.in.
 *
 * A self-contained front controller (it does NOT boot the full application,
 * because a fresh box has no .env yet) that walks the operator through:
 *   welcome → requirements → database → configuration → install → done
 *
 * All heavy lifting is delegated to App\Console\Installer, which is shared
 * with the CLI installer (`php bin/console install`). Once installation
 * completes a lock file is written and this script refuses to run again;
 * delete public/install.php afterwards for defence in depth.
 */

use App\Console\Installer;

// The installer runs BEFORE the app is configured, so surface any problem
// clearly (missing extension, wrong PHP version, unwritable path, DB error)
// instead of a blank "HTTP 500". install.php is deleted after setup.
error_reporting(E_ALL);
@ini_set('display_errors', '1');

$basePath = dirname(__DIR__);

// Turn fatal errors into a readable page rather than a white 500.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Installer error</title>'
        . '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:660px;margin:3rem auto;padding:1.5rem;'
        . 'background:#3b0d0d;color:#fecaca;border:1px solid #7f1d1d;border-radius:12px">'
        . '<h2 style="margin:0 0 .5rem">Installer error</h2>'
        . '<p style="color:#fca5a5">A fatal error occurred. Fix the issue below and reload this page.</p>'
        . '<pre style="white-space:pre-wrap;color:#fee2e2;background:#1f0808;padding:.8rem;border-radius:8px">'
        . htmlspecialchars((string) $error['message'], ENT_QUOTES, 'UTF-8') . '</pre></div>';
});

// Hard requirement: PHP 8.2+. Give a precise, actionable message on old PHP.
if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>PHP 8.2+ required</title>'
        . '<div style="font-family:system-ui;max-width:660px;margin:3rem auto;padding:1.5rem;'
        . 'background:#1f2937;color:#e2e8f0;border:1px solid #334155;border-radius:12px">'
        . '<h2>PHP 8.2 or newer required</h2>'
        . '<p>This server is running PHP ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>In cPanel open <b>MultiPHP Manager</b>, select your domain, and switch the PHP version to <b>8.2</b> (8.3 recommended), then reload this page.</p>'
        . '</div>';
    exit;
}

// Guard against a missing autoloader/source tree with a clear message.
if (!is_file($basePath . '/bootstrap/autoload.php') || !is_dir($basePath . '/src')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Incomplete upload</title>'
        . '<div style="font-family:system-ui;max-width:660px;margin:3rem auto;padding:1.5rem;'
        . 'background:#1f2937;color:#e2e8f0;border:1px solid #334155;border-radius:12px">'
        . '<h2>Application files are incomplete</h2>'
        . '<p>The <code>src/</code> and <code>bootstrap/</code> folders were not found next to <code>public/</code>. '
        . 'Re-upload the full project (keeping its folder structure) and reload this page.</p></div>';
    exit;
}

require $basePath . '/bootstrap/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$installer = new Installer($basePath);

/* ── Tiny view helpers ─────────────────────────────────────────── */

function esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function install_token(): string
{
    if (empty($_SESSION['install_token'])) {
        $_SESSION['install_token'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['install_token'];
}

function install_state(): array
{
    return (array) ($_SESSION['install'] ?? []);
}

/**
 * @param array<string, mixed> $data
 */
function install_store(array $data): void
{
    $_SESSION['install'] = array_merge(install_state(), $data);
}

function render(string $title, string $body, int $activeStep): void
{
    $steps = ['Welcome', 'Requirements', 'Database', 'Configuration', 'Finish'];
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . esc($title) . ' · Code.getxtra.in Installer</title>';
    echo '<style>'
        . ':root{color-scheme:dark}*{box-sizing:border-box}'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#0f172a;color:#e2e8f0}'
        . 'a{color:#38bdf8}'
        . '.wrap{max-width:720px;margin:2.5rem auto;padding:0 1.25rem}'
        . '.brand{font-weight:800;font-size:1.35rem;background:linear-gradient(90deg,#38bdf8,#818cf8);-webkit-background-clip:text;background-clip:text;color:transparent;text-align:center;margin-bottom:.25rem}'
        . '.tagline{color:#94a3b8;text-align:center;font-size:.9rem;margin-bottom:1.5rem}'
        . '.steps{display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap;margin-bottom:1.5rem}'
        . '.steps span{font-size:.72rem;padding:.25rem .7rem;border-radius:999px;background:#1e293b;color:#94a3b8}'
        . '.steps span.active{background:linear-gradient(90deg,#38bdf8,#6366f1);color:#04121f;font-weight:700}'
        . '.steps span.done{background:#134e4a;color:#5eead4}'
        . '.card{background:#111827;border:1px solid #1e293b;border-radius:14px;padding:1.75rem}'
        . 'h1{font-size:1.35rem;margin:0 0 .35rem}'
        . '.sub{color:#94a3b8;margin:0 0 1.4rem;font-size:.92rem}'
        . 'label{display:block;font-size:.85rem;color:#cbd5e1;margin:.9rem 0 .35rem}'
        . 'input,select{width:100%;padding:.7rem .8rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0;font-size:.95rem}'
        . 'input:focus,select:focus{outline:none;border-color:#38bdf8}'
        . '.row{display:flex;gap:.8rem}.row>div{flex:1}'
        . 'button{width:100%;margin-top:1.4rem;padding:.8rem;border:0;border-radius:9px;background:linear-gradient(90deg,#38bdf8,#6366f1);color:#04121f;font-weight:700;font-size:.98rem;cursor:pointer}'
        . '.alert{padding:.7rem .85rem;border-radius:9px;font-size:.88rem;margin-bottom:1rem;word-break:break-word}'
        . '.alert.ok{background:#052e2b;border:1px solid #134e4a;color:#5eead4}'
        . '.alert.err{background:#3b0d0d;border:1px solid #7f1d1d;color:#fca5a5}'
        . 'table{width:100%;border-collapse:collapse;font-size:.9rem}'
        . 'td{padding:.5rem .3rem;border-bottom:1px solid #1e293b}'
        . '.tag{display:inline-block;border-radius:999px;padding:.1rem .6rem;font-size:.75rem;font-weight:700}'
        . '.tag.ok{background:#134e4a;color:#5eead4}.tag.warn{background:#422006;color:#fbbf24}.tag.bad{background:#7f1d1d;color:#fca5a5}'
        . '.hint{color:#64748b;font-size:.78rem;margin-top:.25rem}'
        . '.mono{font-family:ui-monospace,Menlo,monospace;background:#0b1220;border:1px solid #334155;border-radius:8px;padding:.6rem;word-break:break-all;font-size:.82rem}'
        . 'ul{margin:.4rem 0 0;padding-left:1.1rem}li{margin:.25rem 0;font-size:.9rem}'
        . '</style></head><body><div class="wrap">';
    echo '<div class="brand">Code.getxtra.in</div>';
    echo '<div class="tagline">Enterprise digital-products marketplace — guided installer</div>';
    echo '<div class="steps">';
    foreach ($steps as $i => $label) {
        $cls = $i === $activeStep ? 'active' : ($i < $activeStep ? 'done' : '');
        echo '<span class="' . $cls . '">' . esc($label) . '</span>';
    }
    echo '</div><div class="card">' . $body . '</div>';
    echo '<div class="tagline" style="margin-top:1.25rem">Developer: ANSHU E-MITRA AND CSC CENTER</div>';
    echo '</div></body></html>';
    exit;
}

/* ── Already installed? Refuse. ────────────────────────────────── */

if ($installer->isInstalled()) {
    render('Already installed', ''
        . '<h1>Application already installed</h1>'
        . '<p class="sub">A lock file (<span class="mono">storage/installed.lock</span>) is present, so the installer is disabled.</p>'
        . '<div class="alert ok">For security, delete <span class="mono">install.php</span> and <span class="mono">public/install.php</span> from your server.</div>'
        . '<p><a href="/">Go to the site</a> &middot; <a href="/login">Admin login</a></p>', 4);
}

/* ── Request routing ───────────────────────────────────────────── */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$step = (string) ($_POST['step'] ?? $_GET['step'] ?? 'welcome');
$error = null;

// CSRF for POST.
if ($method === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals(install_token(), $token)) {
        $error = 'Your session expired. Please try again.';
        $step = 'welcome';
    }
}

$tokenField = '<input type="hidden" name="_token" value="' . esc(install_token()) . '">';

/* ── Step: welcome ─────────────────────────────────────────────── */

if ($step === 'welcome') {
    render('Welcome', ''
        . '<h1>Welcome</h1>'
        . '<p class="sub">This wizard will configure your database, generate a secure application key, create the schema, seed baseline data, and create your administrator account.</p>'
        . '<p>Before you begin, make sure you have:</p>'
        . '<ul>'
        . '<li>MySQL 8+ database credentials (host, name, user, password)</li>'
        . '<li>Write access to the project directory and <span class="mono">storage/</span></li>'
        . '<li>Your desired site URL (default <span class="mono">https://www.code.getxtra.in</span>) and admin email</li>'
        . '</ul>'
        . '<form method="post">' . $tokenField
        . '<input type="hidden" name="step" value="requirements">'
        . '<button type="submit">Start &rarr;</button></form>', 0);
}

/* ── Step: requirements ────────────────────────────────────────── */

if ($step === 'requirements') {
    $checks = $installer->requirements();
    $satisfied = $installer->requirementsSatisfied($checks);

    $rows = '';
    foreach ($checks as $c) {
        if ($c['ok']) {
            $tag = '<span class="tag ok">OK</span>';
        } elseif ($c['required']) {
            $tag = '<span class="tag bad">REQUIRED</span>';
        } else {
            $tag = '<span class="tag warn">OPTIONAL</span>';
        }
        $rows .= '<tr><td>' . esc($c['name']) . '</td><td style="color:#94a3b8">' . esc($c['detail'])
            . '</td><td style="text-align:right">' . $tag . '</td></tr>';
    }

    $body = '<h1>System requirements</h1>'
        . '<p class="sub">Everything marked REQUIRED must pass to continue.</p>'
        . '<table>' . $rows . '</table>';

    if ($satisfied) {
        $body .= '<form method="post">' . $tokenField
            . '<input type="hidden" name="step" value="database">'
            . '<button type="submit">Continue &rarr;</button></form>';
    } else {
        $body .= '<div class="alert err" style="margin-top:1rem">Please resolve the required items above, then reload this page.</div>'
            . '<form method="get"><input type="hidden" name="step" value="requirements"><button type="submit">Re-check</button></form>';
    }
    render('Requirements', $body, 1);
}

/**
 * Render the configuration step. DB credentials are carried forward as hidden
 * fields (not only in the session) so the final install request always has the
 * exact values that just passed the connection test — this is what prevents
 * the "using password: NO" failure caused by flaky sessions across redirects.
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $cfg
 */
$renderConfigure = static function (array $db, array $cfg, ?string $error) use ($tokenField): void {
    $hidden = ''
        . '<input type="hidden" name="db_host" value="' . esc($db['host'] ?? '127.0.0.1') . '">'
        . '<input type="hidden" name="db_port" value="' . esc($db['port'] ?? '3306') . '">'
        . '<input type="hidden" name="db_database" value="' . esc($db['database'] ?? '') . '">'
        . '<input type="hidden" name="db_username" value="' . esc($db['username'] ?? '') . '">'
        . '<input type="hidden" name="db_password" value="' . esc($db['password'] ?? '') . '">';
    $err = $error !== null ? '<div class="alert err">' . esc($error) . '</div>' : '';

    render('Configuration', ''
        . '<h1>Site &amp; administrator</h1>'
        . '<div class="alert ok">Database connection verified: <span class="mono">' . esc($db['database'] ?? '') . '</span></div>'
        . '<p class="sub">These settings are written to <span class="mono">.env</span> and used to create your first admin account.</p>'
        . $err
        . '<form method="post">' . $tokenField
        . '<input type="hidden" name="step" value="install">'
        . $hidden
        . '<label>Site URL</label><input name="app_url" value="' . esc($cfg['app_url'] ?? 'https://www.code.getxtra.in') . '" required>'
        . '<div class="hint">The public base URL, e.g. https://www.code.getxtra.in</div>'
        . '<label>Site name</label><input name="app_name" value="' . esc($cfg['app_name'] ?? 'Code.getxtra.in') . '" required>'
        . '<label>Environment</label><select name="app_env"><option value="production">production (recommended)</option><option value="local">local</option></select>'
        . '<hr style="border-color:#1e293b;margin:1.5rem 0">'
        . '<label>Administrator name</label><input name="admin_name" value="' . esc($cfg['admin_name'] ?? 'Administrator') . '" required>'
        . '<label>Administrator email</label><input type="email" name="admin_email" value="' . esc($cfg['admin_email'] ?? '') . '" required>'
        . '<label>Administrator password</label><input type="password" name="admin_password" value="' . esc($cfg['admin_password'] ?? '') . '" required>'
        . '<div class="hint">Minimum 10 characters. You can change it later.</div>'
        . '<button type="submit">Install now &rarr;</button></form>', 3);
};

/* ── Step: database (form + connection test) ───────────────────── */

if ($step === 'database') {
    $db = install_state()['db'] ?? [
        'host' => 'localhost', 'port' => '3306', 'database' => 'getxtrain_Codegetxdata',
        'username' => 'getxtrain_Codegetuser', 'password' => '',
    ];

    if ($method === 'POST' && isset($_POST['host']) && $error === null) {
        $db = [
            'host'     => trim((string) $_POST['host']),
            'port'     => (string) ((int) ($_POST['port'] ?? 3306)),
            'database' => trim((string) $_POST['database']),
            'username' => trim((string) $_POST['username']),
            'password' => (string) ($_POST['password'] ?? ''),
        ];
        $result = $installer->testDatabase($db);
        if ($result['ok']) {
            install_store(['db' => $db]);
            // Go straight into configuration in the SAME request, carrying the
            // verified credentials forward as hidden fields.
            $renderConfigure($db, install_state()['config'] ?? [], null);
        }
        $error = $result['message'];
    }

    $err = $error !== null ? '<div class="alert err">' . esc($error) . '</div>' : '';
    render('Database', ''
        . '<h1>Database connection</h1>'
        . '<p class="sub">Enter your MySQL details. On cPanel create the database and user first (MySQL Databases), add the user with ALL PRIVILEGES, and use host <span class="mono">localhost</span>.</p>'
        . $err
        . '<form method="post">' . $tokenField
        . '<input type="hidden" name="step" value="database">'
        . '<div class="row"><div><label>Host</label><input name="host" value="' . esc($db['host']) . '" required></div>'
        . '<div style="max-width:120px"><label>Port</label><input name="port" value="' . esc($db['port']) . '" required></div></div>'
        . '<label>Database name</label><input name="database" value="' . esc($db['database']) . '" required>'
        . '<label>Username</label><input name="username" value="' . esc($db['username']) . '" required>'
        . '<label>Password</label><input type="password" name="password" value="' . esc($db['password']) . '">'
        . '<div class="hint">Leave nothing blank unless your database user truly has no password.</div>'
        . '<button type="submit">Test connection &amp; continue &rarr;</button></form>', 2);
}

/* ── Step: configuration (GET fallback / direct navigation) ────── */

if ($step === 'configure') {
    $db = install_state()['db'] ?? null;
    if ($db === null || $db === []) {
        header('Location: ?step=database');
        exit;
    }
    $renderConfigure($db, install_state()['config'] ?? [], null);
}

/* ── Step: install (single-request finalize) ───────────────────── */

if ($step === 'install' && $method === 'POST' && $error === null) {
    $sessionDb = install_state()['db'] ?? [];

    // DB credentials come from the POSTed hidden fields (reliable), falling
    // back to the session only if a field is somehow absent.
    $db = [
        'host'     => trim((string) ($_POST['db_host'] ?? $sessionDb['host'] ?? 'localhost')),
        'port'     => (string) ((int) ($_POST['db_port'] ?? $sessionDb['port'] ?? 3306)),
        'database' => trim((string) ($_POST['db_database'] ?? $sessionDb['database'] ?? '')),
        'username' => trim((string) ($_POST['db_username'] ?? $sessionDb['username'] ?? '')),
        'password' => (string) ($_POST['db_password'] ?? $sessionDb['password'] ?? ''),
    ];
    $cfg = [
        'app_url'        => trim((string) ($_POST['app_url'] ?? 'https://www.code.getxtra.in')),
        'app_name'       => trim((string) ($_POST['app_name'] ?? 'Code.getxtra.in')),
        'app_env'        => ($_POST['app_env'] ?? 'production') === 'production' ? 'production' : 'local',
        'admin_name'     => trim((string) ($_POST['admin_name'] ?? '')),
        'admin_email'    => trim((string) ($_POST['admin_email'] ?? '')),
        'admin_password' => (string) ($_POST['admin_password'] ?? ''),
    ];
    install_store(['db' => $db, 'config' => $cfg]); // resilience only

    try {
        $log = $installer->run([
            'db'  => $db,
            'app' => [
                'url'   => $cfg['app_url'],
                'name'  => $cfg['app_name'],
                'env'   => $cfg['app_env'],
                'debug' => $cfg['app_env'] !== 'production',
            ],
            'admin' => [
                'name'     => $cfg['admin_name'],
                'email'    => $cfg['admin_email'],
                'password' => $cfg['admin_password'],
            ],
        ]);

        unset($_SESSION['install']); // clear collected data (incl. passwords)

        $items = '';
        foreach ($log as $line) {
            $items .= '<li>' . esc($line) . '</li>';
        }

        render('Done', ''
            . '<h1>Installation complete 🎉</h1>'
            . '<p class="sub">Code.getxtra.in is ready to use.</p>'
            . '<div class="alert ok">What just happened:</div><ul>' . $items . '</ul>'
            . '<div class="alert err" style="margin-top:1.25rem">Security: delete <span class="mono">install.php</span> and <span class="mono">public/install.php</span> from your server now.</div>'
            . '<p style="margin-top:1rem"><a href="/login">&rarr; Log in to the admin</a> &middot; <a href="/">Visit the storefront</a></p>', 4);
    } catch (Throwable $e) {
        // Re-render configuration with the error, preserving credentials + input.
        $renderConfigure($db, $cfg, $e->getMessage());
    }
}

/* ── Fallback ──────────────────────────────────────────────────── */

header('Location: ?step=welcome');
exit;
