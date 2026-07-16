<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Secrets;

/**
 * Source of runtime secrets (Req 14.6). Implementations resolve sensitive
 * values (DB/payment credentials, signing keys) from a secrets backend so
 * that plaintext secrets never live in the codebase or VCS.
 */
interface SecretProvider
{
    public function get(string $key, ?string $default = null): ?string;

    public function has(string $key): bool;
}
