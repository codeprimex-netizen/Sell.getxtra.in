<?php

declare(strict_types=1);

namespace App\Application\Download;

use App\Config\Config;

/**
 * Issues and verifies short-lived, tamper-proof download tokens (Req 10.1).
 * A token is a signed, opaque string binding an entitlement to a buyer with
 * an expiry — no server-side token table needed. The signing key is derived
 * from APP_KEY so tokens cannot be forged.
 */
final class DownloadTokenService
{
    private const DEFAULT_TTL = 300; // 5 minutes

    private string $key;

    public function __construct(?string $secret = null)
    {
        $secret ??= (string) Config::get('app.key', 'insecure-dev-key');
        $this->key = hash('sha256', $secret . '|downloads', true);
    }

    public function issue(int $entitlementId, int $buyerId, ?int $ttl = null): string
    {
        $payload = [
            'e' => $entitlementId,
            'b' => $buyerId,
            'x' => time() + ($ttl ?? self::DEFAULT_TTL),
        ];
        $encoded = $this->b64(json_encode($payload) ?: '');
        return $encoded . '.' . $this->b64($this->sign($encoded));
    }

    /**
     * Verify a token; returns [entitlement_id, buyer_id] or null when the
     * signature is invalid, the token is malformed, or it has expired.
     *
     * @return array{entitlement_id:int, buyer_id:int}|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $sig] = $parts;

        $expected = $this->b64($this->sign($encoded));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['e'], $data['b'], $data['x'])) {
            return null;
        }
        if ((int) $data['x'] < time()) {
            return null; // expired
        }

        return ['entitlement_id' => (int) $data['e'], 'buyer_id' => (int) $data['b']];
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key, true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
