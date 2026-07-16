<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Secrets;

use App\Config\Env;

/**
 * Resolves secrets from the process environment. Suitable for local
 * development and platforms that inject secrets as env vars.
 */
final class EnvSecretProvider implements SecretProvider
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = Env::get($key);
        return $value === null ? $default : (string) $value;
    }

    public function has(string $key): bool
    {
        return Env::get($key) !== null;
    }
}
