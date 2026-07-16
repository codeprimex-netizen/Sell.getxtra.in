<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Secrets;

use RuntimeException;

/**
 * Application-facing facade over a {@see SecretProvider} (Req 14.6). Use
 * require() for secrets whose absence should fail fast at boot, and get()
 * for optional ones.
 */
final class SecretsManager
{
    public function __construct(private SecretProvider $provider)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->provider->get($key, $default);
    }

    public function has(string $key): bool
    {
        return $this->provider->has($key);
    }

    /** @throws RuntimeException when the secret is not configured */
    public function require(string $key): string
    {
        $value = $this->provider->get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Required secret [{$key}] is not configured.");
        }
        return $value;
    }
}
