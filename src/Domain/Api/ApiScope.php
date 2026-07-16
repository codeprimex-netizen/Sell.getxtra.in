<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * The catalogue of permission scopes an API key may hold (Req 19.2). Scopes
 * are coarse-grained, resource-oriented grants checked by the `scope`
 * middleware on protected `/api/v1` routes.
 */
final class ApiScope
{
    public const PRODUCTS_READ  = 'products.read';
    public const ORDERS_READ    = 'orders.read';
    public const LICENSES_READ  = 'licenses.read';
    public const WEBHOOKS_MANAGE = 'webhooks.manage';

    /** @return array<int, string> every scope that may be granted */
    public static function all(): array
    {
        return [
            self::PRODUCTS_READ,
            self::ORDERS_READ,
            self::LICENSES_READ,
            self::WEBHOOKS_MANAGE,
        ];
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * Normalize a caller-supplied list to known, de-duplicated scopes.
     *
     * @param array<int, string> $scopes
     * @return array<int, string>
     */
    public static function sanitize(array $scopes): array
    {
        $clean = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '' && self::isValid($scope) && !in_array($scope, $clean, true)) {
                $clean[] = $scope;
            }
        }
        return $clean;
    }
}
