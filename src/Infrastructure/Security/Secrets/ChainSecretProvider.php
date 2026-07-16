<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Secrets;

/**
 * Tries each provider in order and returns the first hit — typically a file/
 * Vault-backed provider first, falling back to environment variables.
 */
final class ChainSecretProvider implements SecretProvider
{
    /** @var array<int, SecretProvider> */
    private array $providers;

    public function __construct(SecretProvider ...$providers)
    {
        $this->providers = $providers;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider->has($key)) {
                return $provider->get($key, $default);
            }
        }
        return $default;
    }

    public function has(string $key): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->has($key)) {
                return true;
            }
        }
        return false;
    }
}
