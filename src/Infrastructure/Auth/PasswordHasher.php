<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Password hashing using Argon2id when available, with a bcrypt fallback.
 * See Req 2.1. Supports transparent rehashing when parameters change.
 */
final class PasswordHasher
{
    private string $algo;

    /** @var array<string, int> */
    private array $options;

    public function __construct()
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $this->algo = PASSWORD_ARGON2ID;
            $this->options = [
                'memory_cost' => 65536, // 64 MB
                'time_cost'   => 4,
                'threads'     => 2,
            ];
        } else {
            $this->algo = PASSWORD_BCRYPT;
            $this->options = ['cost' => 12];
        }
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, $this->algo, $this->options);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Whether the stored hash should be re-hashed with current parameters
     * (e.g. after an algorithm/cost upgrade). Call after a successful verify.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->options);
    }
}
