<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Cryptographically secure token helpers.
 *
 * Raw tokens are handed to the user (email links, API keys); only their
 * SHA-256 hash is persisted, so a database leak cannot reveal live tokens.
 */
final class Token
{
    /** Generate a random URL-safe token (hex). */
    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /** Deterministic SHA-256 hash of a token for storage/lookup. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Constant-time comparison to avoid timing attacks. */
    public static function equals(string $known, string $candidate): bool
    {
        return hash_equals($known, $candidate);
    }

    /**
     * Generate a numeric license/reference key in blocks, e.g.
     * "A1B2-C3D4-E5F6-G7H8". Used later for license keys (Req 10.3).
     */
    public static function licenseKey(int $blocks = 4, int $blockSize = 4): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $parts = [];

        for ($b = 0; $b < $blocks; $b++) {
            $chunk = '';
            for ($i = 0; $i < $blockSize; $i++) {
                $chunk .= $alphabet[random_int(0, $max)];
            }
            $parts[] = $chunk;
        }

        return implode('-', $parts);
    }
}
