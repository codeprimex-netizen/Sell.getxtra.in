<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Commerce\CouponRepositoryInterface;

/**
 * Coupon management for the admin console (Req 12.2 / 20.1).
 */
final class CouponAdminService
{
    private const TYPES = ['percent', 'fixed'];
    private const SCOPES = ['all', 'category', 'product', 'seller'];

    public function __construct(private CouponRepositoryInterface $coupons)
    {
    }

    /** @return array<int, array<string,mixed>> */
    public function all(): array
    {
        return $this->coupons->all();
    }

    /**
     * @param array<string,mixed> $input
     * @throws AdminException
     * @return int coupon id
     */
    public function create(array $input): int
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $type = (string) ($input['type'] ?? '');
        $value = (float) ($input['value'] ?? 0);

        if ($code === '') {
            throw AdminException::validation('Coupon code is required.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw AdminException::validation('Coupon type must be percent or fixed.');
        }
        if ($value <= 0) {
            throw AdminException::validation('Coupon value must be greater than zero.');
        }
        if ($type === 'percent' && $value > 100) {
            throw AdminException::validation('A percentage coupon cannot exceed 100%.');
        }
        if ($this->coupons->findByCode($code) !== null) {
            throw AdminException::validation('A coupon with this code already exists.');
        }

        $scope = (string) ($input['scope'] ?? 'all');
        if (!in_array($scope, self::SCOPES, true)) {
            $scope = 'all';
        }

        return $this->coupons->create([
            'code'           => $code,
            'type'           => $type,
            'value'          => $value,
            'scope'          => $scope,
            'scope_ref'      => $input['scope_ref'] ?? null,
            'min_order'      => $input['min_order'] ?? null,
            'max_uses'       => $input['max_uses'] ?? null,
            'per_user_limit' => $input['per_user_limit'] ?? null,
            'starts_at'      => $input['starts_at'] ?? null,
            'expires_at'     => $input['expires_at'] ?? null,
            'is_active'      => 1,
        ]);
    }

    /** @throws AdminException */
    public function setActive(int $id, bool $active): void
    {
        if ($this->coupons->findById($id) === null) {
            throw AdminException::notFound('Coupon');
        }
        $this->coupons->setActive($id, $active);
    }
}
