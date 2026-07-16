<?php

declare(strict_types=1);

namespace App\Application\Download;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Commerce\EntitlementRepositoryInterface;

/**
 * License key verification (Req 10.3). Powers a public endpoint so buyers /
 * integrations can confirm a key is valid and active for a product.
 */
final class LicenseService
{
    public function __construct(
        private EntitlementRepositoryInterface $entitlements,
        private ProductRepositoryInterface $products,
    ) {
    }

    /**
     * @return array{valid: bool, status: string, product: ?string}
     */
    public function verify(string $licenseKey): array
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return ['valid' => false, 'status' => 'invalid', 'product' => null];
        }

        $entitlement = $this->entitlements->findByLicenseKey($licenseKey);
        if ($entitlement === null) {
            return ['valid' => false, 'status' => 'not_found', 'product' => null];
        }

        $status = (string) $entitlement['status'];
        $product = $this->products->findById((int) $entitlement['product_id']);

        return [
            'valid'   => $status === 'active',
            'status'  => $status,
            'product' => $product['title'] ?? null,
        ];
    }
}
