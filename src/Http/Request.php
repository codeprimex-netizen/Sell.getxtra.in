<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable-ish HTTP request abstraction over PHP superglobals.
 *
 * Centralizes input access so controllers never touch $_GET/$_POST
 * directly, easing sanitization and testing.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $body = [],
        private array $server = [],
        private array $cookies = [],
        private array $files = [],
        private array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Support method spoofing via _method for HTML forms.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim((string) $path, '/');

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self(
            method: $method,
            path: $path === '//' ? '/' : $path,
            query: $_GET,
            body: $body,
            server: $_SERVER,
            cookies: $_COOKIE,
            files: $_FILES,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        if ($value === null && strtolower($name) === 'content-type') {
            $value = $this->server['CONTENT_TYPE'] ?? null;
        }

        return $value !== null ? (string) $value : $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('Accept', '') ?? '';
        return str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** Retrieve an uploaded file by input name, or null if not present. */
    public function file(string $key): ?UploadedFile
    {
        $entry = $this->files[$key] ?? null;
        if (!is_array($entry) || ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return UploadedFile::fromArray($entry);
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
