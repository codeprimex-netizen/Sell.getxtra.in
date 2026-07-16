<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Typed application configuration, hydrated from environment variables.
 *
 * Config is loaded once at bootstrap and accessed via dot-notation keys,
 * e.g. Config::get('db.host'). Keeps env access centralized and testable.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$items = [
            'app' => [
                'name'     => Env::get('APP_NAME', 'Sell.getxtra.in'),
                'env'      => Env::get('APP_ENV', 'production'),
                'debug'    => (bool) Env::get('APP_DEBUG', false),
                'url'      => Env::get('APP_URL', 'https://www.sell.getxtra.in'),
                'key'      => Env::get('APP_KEY', ''),
                'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
            ],
            'db' => [
                'host'      => Env::get('DB_HOST', '127.0.0.1'),
                'port'      => (int) Env::get('DB_PORT', 3306),
                'database'  => Env::get('DB_DATABASE', 'sell_getxtra'),
                'username'  => Env::get('DB_USERNAME', 'root'),
                'password'  => Env::get('DB_PASSWORD', ''),
                'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
                'read_host' => Env::get('DB_READ_HOST', null),
                'read_port' => (int) Env::get('DB_READ_PORT', 3306),
            ],
            'redis' => [
                'host'     => Env::get('REDIS_HOST', '127.0.0.1'),
                'port'     => (int) Env::get('REDIS_PORT', 6379),
                'password' => Env::get('REDIS_PASSWORD', null),
            ],
            'session' => [
                'driver'   => Env::get('SESSION_DRIVER', 'file'),
                'lifetime' => (int) Env::get('SESSION_LIFETIME', 120),
                'secure'   => (bool) Env::get('SESSION_SECURE_COOKIE', true),
            ],
            'queue' => [
                'driver' => Env::get('QUEUE_DRIVER', 'sync'),
            ],
            'search' => [
                'driver' => Env::get('SEARCH_DRIVER', 'mysql'),
                'host'   => Env::get('SEARCH_HOST', ''),
                'key'    => Env::get('SEARCH_KEY', ''),
            ],
            'storage' => [
                'driver'         => Env::get('STORAGE_DRIVER', 'local'),
                's3_endpoint'    => Env::get('S3_ENDPOINT', ''),
                's3_region'      => Env::get('S3_REGION', ''),
                'bucket_public'  => Env::get('S3_BUCKET_PUBLIC', ''),
                'bucket_private' => Env::get('S3_BUCKET_PRIVATE', ''),
                'cdn_url'        => Env::get('CDN_URL', ''),
            ],
            'log' => [
                'channel' => Env::get('LOG_CHANNEL', 'json'),
                'level'   => Env::get('LOG_LEVEL', 'info'),
                'path'    => Env::get('LOG_PATH', 'storage/logs/app.log'),
            ],
            'security' => [
                'rate_limit_enabled' => (bool) Env::get('RATE_LIMIT_ENABLED', true),
            ],
        ];

        self::$booted = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$booted) {
            self::boot();
        }

        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        // Shallow set for top-level or dotted keys (used by tests/overrides).
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }
}
