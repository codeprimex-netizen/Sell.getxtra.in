<?php

declare(strict_types=1);

/**
 * Web-based installation wizard for Sell.getxtra.in.
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

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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
    echo '<title>' . esc($title) . ' · Sell.getxtra.in Installer</title>';
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
    echo '<div class="brand">Sell.getxtra.in</div>';
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
        . '<div class="alert ok">For security, delete <span class="mono">public/install.php</span> from your server.</div>'
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
        . '<li>Your desired site URL (default <span class="mono">https://sell.getxtra.in</span>) and admin email</li>'
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

/* ── Step: database (form + test) ──────────────────────────────── */

if ($step === 'database') {
    $db = install_state()['db'] ?? [
        'host' => '127.0.0.1', 'port' => '3306', 'database' => 'sell_getxtra',
        'username' => '', 'password' => '',
    ];

    // Submitted for testing.
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
            header('Location: ?step=configure');
            exit;
        }
        $error = $result['message'];
    }

    $err = $error !== null ? '<div class="alert err">' . esc($error) . '</div>' : '';
    render('Database', ''
        . '<h1>Database connection</h1>'
        . '<p class="sub">Enter your MySQL details. The database will be created automatically if it does not exist.</p>'
        . $err
        . '<form method="post">' . $tokenField
        . '<input type="hidden" name="step" value="database">'
        . '<div class="row"><div><label>Host</label><input name="host" value="' . esc($db['host']) . '" required></div>'
        . '<div style="max-width:120px"><label>Port</label><input name="port" value="' . esc($db['port']) . '" required></div></div>'
        . '<label>Database name</label><input name="database" value="' . esc($db['database']) . '" required>'
        . '<label>Username</label><input name="username" value="' . esc($db['username']) . '" required>'
        . '<label>Password</label><input type="password" name="password" value="' . esc($db['password']) . '">'
        . '<button type="submit">Test connection &amp; continue &rarr;</button></form>', 2);
}

/* ── Step: configuration (site + admin) ────────────────────────── */

if ($step === 'configure') {
    if (empty(install_state()['db'])) {
        header('Location: ?step=database');
        exit;
    }

    $cfg = install_state()['config'] ?? [
        'app_url' => 'https://sell.getxtra.in',
        'app_name' => 'Sell.getxtra.in',
        'admin_name' => 'Administrator',
        'admin_email' => '',
    ];

    if ($method === 'POST' && isset($_POST['app_url']) && $error === null) {
        header('Location: ?step=finish');
        // Persist first, then finalize on the finish step.
        install_store(['config' => [
            'app_url'     => trim((string) $_POST['app_url']),
            'app_name'    => trim((string) $_POST['app_name']),
            'app_env'     => ($_POST['app_env'] ?? 'production') === 'production' ? 'production' : 'local',
            'admin_name'  => trim((string) $_POST['admin_name']),
            'admin_email' => trim((string) $_POST['admin_email']),
            'admin_password' => (string) ($_POST['admin_password'] ?? ''),
        ]]);
        exit;
    }

    render('Configuration', ''
        . '<h1>Site &amp; administrator</h1>'
        . '<p class="sub">These settings are written to <span class="mono">.env</span> and used to create your first admin account.</p>'
        . '<form method="post">' . $tokenField
        . '<input type="hidden" name="step" value="configure">'
        . '<label>Site URL</label><input name="app_url" value="' . esc($cfg['app_url']) . '" required>'
        . '<div class="hint">The public base URL, e.g. https://sell.getxtra.in</div>'
        . '<label>Site name</label><input name="app_name" value="' . esc($cfg['app_name']) . '" required>'
        . '<label>Environment</label><select name="app_env"><option value="production">production (recommended)</option><option value="local">local</option></select>'
        . '<hr style="border-color:#1e293b;margin:1.5rem 0">'
        . '<label>Administrator name</label><input name="admin_name" value="' . esc($cfg['admin_name']) . '" required>'
        . '<label>Administrator email</label><input type="email" name="admin_email" value="' . esc($cfg['admin_email']) . '" required>'
        . '<label>Administrator password</label><input type="password" name="admin_password" required>'
        . '<div class="hint">Minimum 10 characters. You can change it later.</div>'
        . '<button type="submit">Review &amp; install &rarr;</button></form>', 3);
}

/* ── Step: finish (run installer) ──────────────────────────────── */

if ($step === 'finish') {
    $state = install_state();
    $db = (array) ($state['db'] ?? []);
    $cfg = (array) ($state['config'] ?? []);

    if ($db === [] || $cfg === []) {
        header('Location: ?step=welcome');
        exit;
    }

    try {
        $log = $installer->run([
            'db' => $db,
            'app' => [
                'url'   => (string) ($cfg['app_url'] ?? 'https://sell.getxtra.in'),
                'name'  => (string) ($cfg['app_name'] ?? 'Sell.getxtra.in'),
                'env'   => (string) ($cfg['app_env'] ?? 'production'),
                'debug' => ($cfg['app_env'] ?? 'production') !== 'production',
            ],
            'admin' => [
                'name'     => (string) ($cfg['admin_name'] ?? ''),
                'email'    => (string) ($cfg['admin_email'] ?? ''),
                'password' => (string) ($cfg['admin_password'] ?? ''),
            ],
        ]);

        // Clear collected data (esp. passwords) from the session.
        unset($_SESSION['install']);

        $items = '';
        foreach ($log as $line) {
            $items .= '<li>' . esc($line) . '</li>';
        }

        render('Done', ''
            . '<h1>Installation complete 🎉</h1>'
            . '<p class="sub">Sell.getxtra.in is ready to use.</p>'
            . '<div class="alert ok">What just happened:</div><ul>' . $items . '</ul>'
            . '<div class="alert err" style="margin-top:1.25rem">Security: delete <span class="mono">public/install.php</span> from your server now.</div>'
            . '<p style="margin-top:1rem"><a href="/login">&rarr; Log in to the admin</a> &middot; <a href="/">Visit the storefront</a></p>', 4);
    } catch (Throwable $e) {
        render('Installation failed', ''
            . '<h1>Installation failed</h1>'
            . '<p class="sub">No lock file was written; you can fix the issue and retry.</p>'
            . '<div class="alert err">' . esc($e->getMessage()) . '</div>'
            . '<form method="post">' . $tokenField
            . '<input type="hidden" name="step" value="configure">'
            . '<button type="submit">&larr; Back to configuration</button></form>', 3);
    }
}

/* ── Fallback ──────────────────────────────────────────────────── */

header('Location: ?step=welcome');
exit;
