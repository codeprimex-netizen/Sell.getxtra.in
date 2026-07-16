<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Support\Security\Token;

/**
 * Session service — a working data set over a SessionStore with flash
 * messages and a CSRF token. Load once at the start of a request, mutate,
 * then persist with save() (handled by StartSession middleware).
 */
final class Session
{
    private const FLASH_NEW = '_flash_next';
    private const FLASH_NOW = '_flash_now';
    private const CSRF_KEY = '_csrf_token';

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $loaded = false;

    public function __construct(private SessionStore $store)
    {
    }

    public function start(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->data = $this->store->load();

        // Age flash data: promote "next" to "now", clear "next".
        $this->data[self::FLASH_NOW] = $this->data[self::FLASH_NEW] ?? [];
        $this->data[self::FLASH_NEW] = [];

        $this->loaded = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->data[self::FLASH_NEW][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->data[self::FLASH_NOW][$key] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return isset($this->data[self::FLASH_NOW][$key]);
    }

    /** Reissue the session id while preserving data (post-login fixation defense). */
    public function regenerate(): void
    {
        $this->store->regenerateId();
    }

    /** Clear all data and reissue the id (logout). */
    public function invalidate(): void
    {
        $this->data = [];
        $this->store->regenerateId();
    }

    public function id(): string
    {
        return $this->store->id();
    }

    /** Get (creating if needed) the CSRF token for this session. See Req 14.3. */
    public function csrfToken(): string
    {
        if (empty($this->data[self::CSRF_KEY])) {
            $this->data[self::CSRF_KEY] = Token::random(32);
        }
        return (string) $this->data[self::CSRF_KEY];
    }

    public function verifyCsrf(?string $candidate): bool
    {
        $token = $this->data[self::CSRF_KEY] ?? null;
        return is_string($token) && is_string($candidate) && Token::equals($token, $candidate);
    }

    public function persist(): void
    {
        $this->store->save($this->data);
    }
}
