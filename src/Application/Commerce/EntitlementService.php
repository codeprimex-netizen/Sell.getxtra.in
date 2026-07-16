<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Support\Security\Token;

/**
 * Grants and revokes buyer entitlements (Req 10.2/10.3). Each paid order item
 * yields an entitlement with a unique license key; download delivery is
 * built in Phase 6. Full refunds revoke the related entitlements.
 */
final class EntitlementService
{
    public function __construct(
        private EntitlementRepositoryInterface $entitlements,
        private ProductRepositoryInterface $products,
    ) {
    }

    /**
     * @param array<int, array<string,mixed>> $items order_items rows (with id)
     * @return array<int,int> created entitlement ids
     */
    public function grantForOrder(int $buyerId, array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $this->entitlements->create([
                'order_item_id' => (int) $item['id'],
                'buyer_id'      => $buyerId,
                'product_id'    => (int) $item['product_id'],
                'license_key'   => $this->uniqueLicenseKey(),
                'status'        => 'active',
            ]);
            $this->products->incrementSales((int) $item['product_id']);
        }
        return $ids;
    }

    public function revokeForOrder(int $orderId): void
    {
        foreach ($this->entitlements->forOrder($orderId) as $entitlement) {
            $this->entitlements->revoke((int) $entitlement['id']);
        }
    }

    private function uniqueLicenseKey(): string
    {
        // Extremely low collision probability; retry defensively.
        do {
            $key = Token::licenseKey();
        } while ($this->entitlements->findByLicenseKey($key) !== null);

        return $key;
    }
}
