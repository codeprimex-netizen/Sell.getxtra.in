<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Time-based One-Time Password (TOTP) — RFC 6238 / RFC 4226.
 *
 * Self-contained implementation (HMAC-SHA1, 30s step, 6 digits) with no
 * external dependency, compatible with Google Authenticator / Authy.
 * Used for Two-Factor Authentication (Req 2.4).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private int $digits = 6,
        private int $period = 30,
        private string $algo = 'sha1',
    ) {
    }

    /** Generate a new random Base32 secret. */
    public function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        return $this->base32Encode($bytes);
    }

    /** Compute the OTP for a given secret at a given timestamp. */
    public function codeAt(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        return $this->hotp($secret, $counter);
    }

    /**
     * Verify a user-supplied code, allowing a ±$window step drift to absorb
     * clock skew (default one step each way).
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . $this->digits . '}$/', $code)) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /** Build an otpauth:// URI for QR provisioning. */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($this->algo),
            'digits'    => $this->digits,
            'period'    => $this->period,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0) . pack('N*', $counter); // 64-bit big-endian
        $hash = hash_hmac($this->algo, $binCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part);
        $number = ($value[1] ?? 0) & 0x7FFFFFFF;

        $otp = $number % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper($secret), '=');
        if ($secret === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
