<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Config\Config;
use RuntimeException;

/**
 * Authenticated symmetric encryption (AES-256-GCM) for data at rest such
 * as TOTP secrets and payout details. Keyed by APP_KEY. See Req 14.6.
 */
final class Crypto
{
    private string $key;

    private const CIPHER = 'aes-256-gcm';

    public function __construct(?string $key = null)
    {
        $configured = $key ?? (string) Config::get('app.key', '');
        $this->key = $this->normalizeKey($configured);
    }

    /** Encrypt a plaintext string; returns base64(iv|tag|ciphertext). */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /** Decrypt a value produced by encrypt(); returns null on failure. */
    public function decrypt(string $payload): ?string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 28) {
            return null;
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Derive a 32-byte key from the configured value. Accepts "base64:..."
     * keys; otherwise hashes the input so any string yields a valid key
     * (a random APP_KEY should be set in production).
     */
    private function normalizeKey(string $configured): string
    {
        if ($configured === '') {
            $configured = 'sell.getxtra.in-insecure-dev-key';
        }
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }
        return hash('sha256', $configured, true);
    }
}
