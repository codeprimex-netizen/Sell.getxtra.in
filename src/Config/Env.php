<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal, dependency-free .env loader (12-factor config).
 *
 * Parses KEY=VALUE lines, supports quoted values, comments (#) and
 * "export " prefixes. Values are injected into getenv()/$_ENV/$_SERVER
 * only if not already present in the real environment (real env wins).
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded || !is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::normalizeValue(trim($value));

            // Real environment always takes precedence over the file.
            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }

    private static function normalizeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Quoted values: return the literal content between the opening quote
        // and the next matching quote. Anything after the closing quote (for
        // example a trailing inline comment) is ignored, and '#' inside the
        // quotes is preserved verbatim.
        $quote = $value[0];
        if ($quote === '"' || $quote === "'") {
            $end = strpos($value, $quote, 1);
            if ($end !== false) {
                return substr($value, 1, $end - 1);
            }
            return substr($value, 1);
        }

        // Unquoted values: strip an inline comment introduced at the start of
        // the value or by leading whitespace followed by '#'. A '#' that is
        // part of the value itself (no leading whitespace, e.g. a token like
        // "ab#cd") is kept intact.
        $value = preg_replace('/(^|\s)#.*$/', '', $value) ?? $value;

        return rtrim($value);
    }

    /**
     * Retrieve an environment value with type coercion and default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
