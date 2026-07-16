<?php

declare(strict_types=1);

namespace App\Console;

use App\Config\Config;
use App\Config\Env;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Persistence\ConnectionManager;
use App\Support\Security\Token;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Advanced first-run installer for Code.getxtra.in.
 *
 * Encapsulates every step of provisioning a fresh deployment so both the
 * web wizard (public/install.php) and the CLI (`php bin/console install`)
 * share one code path:
 *
 *   1. Verify the runtime meets system requirements.
 *   2. Connect to MySQL and create the schema database if needed.
 *   3. Generate an application key and write a complete .env file.
 *   4. Run migrations and seed baseline reference data.
 *   5. Create the first administrator account.
 *   6. Write a lock file so the installer cannot run again.
 *
 * The class deliberately avoids booting the full application (there is no
 * .env yet on a fresh box) — it builds its own PDO for probing and forces
 * the freshly-collected settings into Config before reusing the existing,
 * already-tested MigrationRunner / Seeder.
 */
final class Installer
{
    /** Lock file, relative to the project root, that marks a completed install. */
    public const LOCK_PATH = 'storage/installed.lock';

    /** Minimum supported PHP version. */
    public const MIN_PHP = '8.2.0';

    /** Required PHP extensions. */
    private const REQUIRED_EXTENSIONS = [
        'pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'intl', 'fileinfo', 'ctype', 'filter',
    ];

    /** Recommended (optional) PHP extensions. */
    private const OPTIONAL_EXTENSIONS = ['gd', 'curl', 'redis', 'zip'];

    /** Directories that must be writable by the web/worker process. */
    private const WRITABLE_PATHS = [
        'storage', 'storage/logs', 'storage/cache', 'storage/tmp', 'storage/metrics',
    ];

    public function __construct(private string $basePath)
    {
    }

    // ── Install-state ──────────────────────────────────────────────

    public function lockFile(): string
    {
        return $this->basePath . '/' . self::LOCK_PATH;
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile());
    }

    // ── Requirements ───────────────────────────────────────────────

    /**
     * @return list<array{name:string, ok:bool, required:bool, detail:string}>
     */
    public function requirements(): array
    {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $checks[] = [
            'name'     => 'PHP >= ' . self::MIN_PHP,
            'ok'       => $phpOk,
            'required' => true,
            'detail'   => 'detected ' . PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'name'     => "PHP extension: {$ext}",
                'ok'       => $loaded,
                'required' => true,
                'detail'   => $loaded ? 'loaded' : 'missing',
            ];
        }

        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'name'     => "PHP extension: {$ext} (recommended)",
                'ok'       => $loaded,
                'required' => false,
                'detail'   => $loaded ? 'loaded' : 'not loaded',
            ];
        }

        foreach (self::WRITABLE_PATHS as $rel) {
            $path = $this->basePath . '/' . $rel;
            $ok = is_dir($path) && is_writable($path);
            $checks[] = [
                'name'     => "Writable: {$rel}/",
                'ok'       => $ok,
                'required' => true,
                'detail'   => $ok ? 'writable' : (is_dir($path) ? 'not writable' : 'missing'),
            ];
        }

        $envPath = $this->basePath . '/.env';
        $envWritable = is_file($envPath) ? is_writable($envPath) : is_writable($this->basePath);
        $checks[] = [
            'name'     => 'Writable: project root (.env)',
            'ok'       => $envWritable,
            'required' => true,
            'detail'   => $envWritable ? 'writable' : 'not writable',
        ];

        return $checks;
    }

    /**
     * @param list<array{name:string, ok:bool, required:bool, detail:string}>|null $checks
     */
    public function requirementsSatisfied(?array $checks = null): bool
    {
        foreach ($checks ?? $this->requirements() as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }
        return true;
    }

    // ── Database ───────────────────────────────────────────────────

    /**
     * Open a raw PDO connection to the MySQL server (optionally selecting the
     * target database). Used for probing and admin creation, independent of
     * the application's ConnectionManager.
     *
     * @param array{host:string, port?:int|string, database?:string, username:string, password?:string, charset?:string} $db
     */
    public function connect(array $db, bool $withDatabase = true): PDO
    {
        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int) ($db['port'] ?? 3306);
        if ($withDatabase && !empty($db['database'])) {
            $dsn .= ';dbname=' . $db['database'];
        }
        $dsn .= ';charset=' . $charset;

        return new PDO($dsn, (string) $db['username'], (string) ($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Verify the database connection, working on both cPanel-style hosting
     * (where the database is pre-created and the user is scoped to it, without
     * global CREATE privilege) and privileged/root setups (where we can create
     * the database on demand). Returns actionable messages on failure.
     *
     * @param array{host:string, port?:int|string, database:string, username:string, password?:string} $db
     * @return array{ok:bool, message:string}
     */
    public function testDatabase(array $db): array
    {
        $name = $this->sanitizeDbName((string) ($db['database'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'Invalid database name — use letters, digits and underscores only.'];
        }

        // Preferred path (also the cPanel path): connect straight to the target
        // database. If the user is correctly granted on an existing DB, this
        // succeeds without needing any CREATE privilege.
        try {
            $pdo = $this->connect(array_merge($db, ['database' => $name]), true);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'message' => "Connected to MySQL {$version}. Database `{$name}` is ready."];
        } catch (\PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);

            // Credentials / grant problem — give precise, host-aware guidance.
            if (in_array($driverCode, [1045, 1044, 1698], true)) {
                return ['ok' => false, 'message' => $this->accessDeniedHint($db)];
            }

            // Auth OK but the database is missing: try to create it (works for
            // privileged users; cPanel users typically cannot, so instruct).
            if ($driverCode === 1049) {
                try {
                    $server = $this->connect($db, false);
                    $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    return ['ok' => true, 'message' => "Connected. Database `{$name}` was created and is ready."];
                } catch (\Throwable) {
                    return ['ok' => false, 'message' =>
                        "The database `{$name}` does not exist and this user cannot create it. "
                        . 'Create the database first (cPanel → MySQL Databases → Create New Database), add your '
                        . 'user to it with ALL PRIVILEGES, then try again.'];
                }
            }

            return ['ok' => false, 'message' => 'Database error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Build a precise "access denied" hint, distinguishing a missing password
     * (the "using password: NO" case) from a wrong password / missing grant.
     *
     * @param array<string, mixed> $db
     */
    private function accessDeniedHint(array $db): string
    {
        $user = (string) ($db['username'] ?? '');
        $host = (string) ($db['host'] ?? 'localhost');
        $hasPassword = ((string) ($db['password'] ?? '')) !== '';

        $msg = "Access denied for database user '{$user}'. ";
        if (!$hasPassword) {
            $msg .= 'No password was sent — please enter the database user\'s password (the field was empty). ';
        } else {
            $msg .= 'The username or password is incorrect, or the user is not attached to this database. '
                . 'In cPanel: MySQL Databases → "Add User To Database" → grant ALL PRIVILEGES. ';
        }
        $msg .= "On cPanel the host is usually \"localhost\". (user='{$user}', host='{$host}')";

        return $msg;
    }

    private function sanitizeDbName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', $name);
    }

    // ── Application key ─────────────────────────────────────────────

    /** Generate a fresh 256-bit application key in "base64:" form. */
    public function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    // ── .env generation ─────────────────────────────────────────────

    /**
     * Render a complete .env by taking the shipped .env.example as the
     * template and overriding the given keys, preserving all documentation
     * comments. Override keys not present in the template are appended.
     *
     * @param array<string, string> $overrides
     */
    public function renderEnv(string $example, array $overrides): string
    {
        $used = [];
        $out = [];

        foreach (preg_split('/\R/', $example) ?: [] as $line) {
            if (preg_match('/^(export\s+)?([A-Z0-9_]+)\s*=/', $line, $m)) {
                $key = $m[2];
                if (array_key_exists($key, $overrides)) {
                    $out[] = $key . '=' . $this->escapeEnvValue($overrides[$key]);
                    $used[$key] = true;
                    continue;
                }
            }
            $out[] = $line;
        }

        $appended = false;
        foreach ($overrides as $key => $value) {
            if (!isset($used[$key])) {
                if (!$appended) {
                    $out[] = '';
                    $out[] = '# Added by the installer';
                    $appended = true;
                }
                $out[] = $key . '=' . $this->escapeEnvValue($value);
            }
        }

        return rtrim(implode("\n", $out)) . "\n";
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // Quote when the value contains whitespace, a hash, or quote characters
        // so the (comment-aware) Env parser reads it back verbatim.
        if (preg_match('/[\s#"\']/', $value) === 1) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        return $value;
    }

    public function writeEnv(string $content): void
    {
        $path = $this->basePath . '/.env';
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write the .env file. Check filesystem permissions.');
        }
        @chmod($path, 0640);
    }

    /**
     * Map collected settings onto .env keys, generating fresh secrets.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function buildEnvOverrides(array $config, string $appKey): array
    {
        $db = (array) ($config['db'] ?? []);
        $app = (array) ($config['app'] ?? []);
        $mail = (array) ($config['mail'] ?? []);

        $overrides = [
            'APP_NAME'  => (string) ($app['name'] ?? 'Code.getxtra.in'),
            'APP_ENV'   => (string) ($app['env'] ?? 'production'),
            'APP_DEBUG' => !empty($app['debug']) ? 'true' : 'false',
            'APP_URL'   => (string) ($app['url'] ?? 'https://www.code.getxtra.in'),
            'APP_KEY'   => $appKey,

            'DB_HOST'     => (string) ($db['host'] ?? '127.0.0.1'),
            'DB_PORT'     => (string) ((int) ($db['port'] ?? 3306)),
            'DB_DATABASE' => (string) ($db['database'] ?? 'getxtrain_Codegetxdata'),
            'DB_USERNAME' => (string) ($db['username'] ?? 'getxtrain_Codegetuser'),
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),

            // Fresh per-install secrets.
            'PAYMENT_OFFLINE_SECRET' => bin2hex(random_bytes(16)),
            'METRICS_TOKEN'          => bin2hex(random_bytes(16)),
        ];

        if ($mail !== []) {
            $overrides['MAIL_DRIVER']       = (string) ($mail['driver'] ?? 'log');
            $overrides['MAIL_HOST']         = (string) ($mail['host'] ?? '127.0.0.1');
            $overrides['MAIL_PORT']         = (string) ((int) ($mail['port'] ?? 587));
            $overrides['MAIL_USERNAME']     = (string) ($mail['username'] ?? '');
            $overrides['MAIL_PASSWORD']     = (string) ($mail['password'] ?? '');
            $overrides['MAIL_ENCRYPTION']   = (string) ($mail['encryption'] ?? 'tls');
            $overrides['MAIL_FROM_ADDRESS'] = (string) ($mail['from_address'] ?? 'no-reply@code.getxtra.in');
            $overrides['MAIL_FROM_NAME']    = (string) ($mail['from_name'] ?? ($app['name'] ?? 'Code.getxtra.in'));
        }

        return $overrides;
    }

    /**
     * Force the freshly-collected settings into Config so the reused
     * ConnectionManager targets the right database within this same process,
     * regardless of whether Config/Env were already booted with defaults.
     *
     * @param array<string, mixed> $config
     */
    private function applyRuntimeConfig(array $config, string $appKey): void
    {
        // CRITICAL: boot Config BEFORE overriding it. Config::set() does not
        // trigger boot(), and a later lazy boot() (e.g. from ConnectionManager
        // reading db.password) replaces the entire config array — which would
        // silently wipe the overrides below and connect with an empty password
        // ("Access denied ... using password: NO"). Loading the freshly-written
        // .env first also makes non-DB config (app.url, etc.) correct.
        Env::load($this->basePath . '/.env');
        Config::boot();

        $db = (array) ($config['db'] ?? []);
        // Authoritative in-memory overrides (no .env round-trip for the creds
        // that just passed the live connection test).
        Config::set('db.host', (string) ($db['host'] ?? '127.0.0.1'));
        Config::set('db.port', (int) ($db['port'] ?? 3306));
        Config::set('db.database', (string) ($db['database'] ?? 'getxtrain_Codegetxdata'));
        Config::set('db.username', (string) ($db['username'] ?? 'getxtrain_Codegetuser'));
        Config::set('db.password', (string) ($db['password'] ?? ''));
        Config::set('db.charset', (string) ($db['charset'] ?? 'utf8mb4'));
        Config::set('db.read_host', null);
        Config::set('app.key', $appKey);
    }

    // ── Schema + data ───────────────────────────────────────────────

    public function migrate(): void
    {
        $runner = new MigrationRunner(new ConnectionManager(), $this->basePath . '/database/migrations');
        $runner->migrate();
    }

    public function seed(): void
    {
        (new Seeder(new ConnectionManager()))->run();
    }

    /**
     * Create (or promote) the first administrator, granting the admin and
     * super_admin roles. Idempotent for a given email.
     */
    public function createAdmin(PDO $pdo, string $name, string $email, string $password): int
    {
        $email = strtolower(trim($email));
        $hasher = new PasswordHasher();
        $hash = $hasher->hash($password);

        $find = $pdo->prepare('SELECT id FROM users WHERE email = :e');
        $find->execute(['e' => $email]);
        $existing = $find->fetchColumn();

        if ($existing !== false) {
            $id = (int) $existing;
            $update = $pdo->prepare(
                "UPDATE users SET name = :n, password_hash = :p, status = 'active',
                 email_verified_at = NOW() WHERE id = :id"
            );
            $update->execute(['n' => trim($name), 'p' => $hash, 'id' => $id]);
        } else {
            $referral = strtoupper(substr(Token::random(6), 0, 8));
            $insert = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, status, email_verified_at, locale, referral_code)
                 VALUES (:n, :e, :p, 'active', NOW(), 'en', :r)"
            );
            $insert->execute(['n' => trim($name), 'e' => $email, 'p' => $hash, 'r' => $referral]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            "INSERT IGNORE INTO user_role (user_id, role_id)
             SELECT :u, id FROM roles WHERE name IN ('super_admin', 'admin')"
        )->execute(['u' => $id]);

        return $id;
    }

    // ── Lock ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    public function lock(array $meta = []): void
    {
        $payload = array_merge([
            'installed_at' => date('c'),
            'version'      => '1.0.0',
            'php'          => PHP_VERSION,
        ], $meta);

        if (file_put_contents($this->lockFile(), (string) json_encode($payload, JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('Unable to write the install lock file.');
        }
    }

    // ── Orchestration (headless / CLI) ───────────────────────────────

    /**
     * Run the complete installation end to end.
     *
     * @param array<string, mixed> $config Expected keys:
     *   db    => [host, port, database, username, password]
     *   app   => [url, name, env, debug, key?]
     *   admin => [name, email, password]
     *   mail  => [...] (optional)
     * @return list<string> human-readable progress log
     */
    public function run(array $config, bool $force = false): array
    {
        if ($this->isInstalled() && !$force) {
            throw new RuntimeException('Already installed. Pass force to reinstall.');
        }

        $checks = $this->requirements();
        if (!$this->requirementsSatisfied($checks)) {
            $failed = array_values(array_filter($checks, static fn ($c) => $c['required'] && !$c['ok']));
            $names = implode(', ', array_map(static fn ($c) => $c['name'], $failed));
            throw new RuntimeException('System requirements not satisfied: ' . $names);
        }

        $admin = (array) ($config['admin'] ?? []);
        $this->assertAdmin($admin);

        $log = [];

        $db = (array) ($config['db'] ?? []);
        $test = $this->testDatabase($db);
        if (!$test['ok']) {
            throw new RuntimeException('Database error: ' . $test['message']);
        }
        $log[] = $test['message'];

        $appKey = (string) (($config['app']['key'] ?? '') ?: $this->generateAppKey());
        $example = (string) file_get_contents($this->basePath . '/.env.example');
        $this->writeEnv($this->renderEnv($example, $this->buildEnvOverrides($config, $appKey)));
        $log[] = 'Wrote .env with a freshly generated APP_KEY.';

        $this->applyRuntimeConfig($config, $appKey);

        $this->migrate();
        $log[] = 'Applied database migrations.';

        $this->seed();
        $log[] = 'Seeded roles, permissions, categories and feature flags.';

        $adminId = $this->createAdmin(
            (new ConnectionManager())->write(),
            (string) $admin['name'],
            (string) $admin['email'],
            (string) $admin['password'],
        );
        $log[] = "Created administrator account (user #{$adminId}).";

        $this->lock([
            'admin_email' => strtolower((string) $admin['email']),
            'app_url'     => (string) ($config['app']['url'] ?? ''),
        ]);
        $log[] = 'Wrote install lock — the installer is now disabled.';

        return $log;
    }

    /**
     * @param array<string, mixed> $admin
     */
    public function assertAdmin(array $admin): void
    {
        $name = trim((string) ($admin['name'] ?? ''));
        $email = trim((string) ($admin['email'] ?? ''));
        $password = (string) ($admin['password'] ?? '');

        if ($name === '') {
            throw new RuntimeException('Administrator name is required.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid administrator email is required.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Administrator password must be at least 10 characters.');
        }
    }
}
