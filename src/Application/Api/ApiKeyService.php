<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Api\ApiKeyRepositoryInterface;
use App\Domain\Api\ApiScope;

/**
 * Issues and verifies API access keys (Req 19.2). A token is shown to the
 * owner exactly once at creation; only its SHA-256 hash and a public prefix
 * are persisted, so a leaked database never yields usable credentials.
 *
 * Token format: gx_<prefix:12>_<secret:40>
 */
final class ApiKeyService
{
    private const TOKEN_PREFIX = 'gx';
    private const DEFAULT_RATE_LIMIT = 120; // requests / minute

    public function __construct(private ApiKeyRepositoryInterface $keys)
    {
    }

    /**
     * Mint a new key. The plaintext token is returned only here.
     *
     * @param array<int, string> $scopes
     * @return array{id:int, token:string, prefix:string, name:string, scopes:array<int,string>, rate_limit:int}
     */
    public function generate(
        int $userId,
        string $name,
        array $scopes = [],
        int $rateLimit = self::DEFAULT_RATE_LIMIT,
        ?string $expiresAt = null,
    ): array {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'API key';
        $scopes = ApiScope::sanitize($scopes);
        $rateLimit = max(1, min($rateLimit, 10000));

        $prefix = bin2hex(random_bytes(6));          // 12 hex chars
        $secret = bin2hex(random_bytes(20));         // 40 hex chars
        $token = self::TOKEN_PREFIX . '_' . $prefix . '_' . $secret;

        $id = $this->keys->create([
            'user_id'    => $userId,
            'name'       => $name,
            'prefix'     => $prefix,
            'token_hash' => hash('sha256', $token),
            'scopes'     => implode(',', $scopes),
            'rate_limit' => $rateLimit,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id'         => $id,
            'token'      => $token,
            'prefix'     => $prefix,
            'name'       => $name,
            'scopes'     => $scopes,
            'rate_limit' => $rateLimit,
        ];
    }

    /**
     * Verify a presented token and return its (active, unexpired) key row with
     * decoded scopes, or null. On success the key's last-used timestamp is
     * refreshed.
     *
     * @return array<string,mixed>|null
     */
    public function authenticate(string $token): ?array
    {
        $parts = explode('_', trim($token));
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_PREFIX || $parts[1] === '') {
            return null;
        }

        $row = $this->keys->findByPrefix($parts[1]);
        if ($row === null) {
            return null;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '' && strtotime($expiresAt) < time()) {
            return null;
        }

        $this->keys->touchLastUsed((int) $row['id']);
        $row['scope_list'] = $this->scopesOf($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $key
     * @return array<int, string>
     */
    public function scopesOf(array $key): array
    {
        $raw = (string) ($key['scopes'] ?? '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
    }

    /** @param array<string,mixed> $key */
    public function hasScope(array $key, string $scope): bool
    {
        $scopes = $key['scope_list'] ?? $this->scopesOf($key);
        return in_array($scope, $scopes, true);
    }

    /** @return array<int, array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->keys->forUser($userId);
    }

    public function revoke(int $id, int $userId): bool
    {
        return $this->keys->revoke($id, $userId);
    }
}
