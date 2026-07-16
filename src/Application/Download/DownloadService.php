<?php

declare(strict_types=1);

namespace App\Application\Download;

use App\Application\Audit\AuditLogger;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Infrastructure\Storage\StorageManager;

/**
 * Secure download authority (Req 10). Issues short-lived signed links for an
 * owned entitlement and, on redemption, re-validates ownership, status,
 * expiry, and download limits before revealing a private deliverable. Every
 * grant and denial is audited (Req 10.4). Real storage paths are never
 * exposed to the client.
 */
final class DownloadService
{
    public function __construct(
        private DownloadTokenService $tokens,
        private EntitlementRepositoryInterface $entitlements,
        private ProductVersionRepositoryInterface $versions,
        private ProductRepositoryInterface $products,
        private StorageManager $storage,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Create a signed download link for a buyer's entitlement.
     *
     * @throws DownloadException if the entitlement is missing / not owned / inactive
     */
    public function createLink(int $entitlementId, int $buyerId): string
    {
        $entitlement = $this->authorize($entitlementId, $buyerId);
        return '/download/' . $this->tokens->issue((int) $entitlement['id'], $buyerId);
    }

    /**
     * Redeem a signed token and resolve the deliverable to stream. Increments
     * the download count and writes an audit entry.
     *
     * @throws DownloadException
     */
    public function resolve(string $token, int $currentUserId, ?string $ip = null, ?string $requestId = null): Deliverable
    {
        $claims = $this->tokens->verify($token);
        if ($claims === null) {
            $this->audit->log('download.denied', $currentUserId, 'download', null, ['reason' => 'invalid_token'], $ip, $requestId);
            throw DownloadException::invalidToken();
        }

        // The signed-in user must match the token's buyer (defense in depth).
        if ($claims['buyer_id'] !== $currentUserId) {
            $this->audit->log('download.denied', $currentUserId, 'entitlement', $claims['entitlement_id'], ['reason' => 'buyer_mismatch'], $ip, $requestId);
            throw DownloadException::forbidden();
        }

        $entitlement = $this->authorize($claims['entitlement_id'], $currentUserId, $ip, $requestId);

        $version = $this->versions->currentForProduct((int) $entitlement['product_id']);
        if ($version === null || empty($version['storage_key']) || ($version['scan_status'] ?? '') !== 'clean') {
            $this->audit->log('download.denied', $currentUserId, 'entitlement', (int) $entitlement['id'], ['reason' => 'no_clean_version'], $ip, $requestId);
            throw DownloadException::unavailable();
        }

        $key = (string) $version['storage_key'];
        $disk = $this->storage->private();
        if (!$disk->exists($key)) {
            $this->audit->log('download.denied', $currentUserId, 'entitlement', (int) $entitlement['id'], ['reason' => 'missing_file'], $ip, $requestId);
            throw DownloadException::unavailable();
        }

        $this->entitlements->incrementDownloadCount((int) $entitlement['id']);
        $this->audit->log('download.serve', $currentUserId, 'entitlement', (int) $entitlement['id'], [
            'product_id' => (int) $entitlement['product_id'],
            'version'    => (string) $version['version_number'],
        ], $ip, $requestId);

        $product = $this->products->findById((int) $entitlement['product_id']);
        $slug = (string) ($product['slug'] ?? ('product-' . $entitlement['product_id']));
        $filename = $slug . '-v' . (string) $version['version_number'] . '.zip';

        return new Deliverable($key, $filename, $disk->size($key));
    }

    /**
     * Load an entitlement and assert it is owned, active, unexpired, and
     * under its download limit.
     *
     * @return array<string,mixed>
     * @throws DownloadException
     */
    private function authorize(int $entitlementId, int $buyerId, ?string $ip = null, ?string $requestId = null): array
    {
        $entitlement = $this->entitlements->findById($entitlementId);

        if ($entitlement === null || (int) $entitlement['buyer_id'] !== $buyerId) {
            throw DownloadException::forbidden();
        }
        if (($entitlement['status'] ?? '') !== 'active') {
            $this->audit->log('download.denied', $buyerId, 'entitlement', $entitlementId, ['reason' => 'revoked'], $ip, $requestId);
            throw DownloadException::revoked();
        }
        if (!empty($entitlement['expires_at']) && strtotime((string) $entitlement['expires_at']) < time()) {
            throw DownloadException::expired();
        }
        $max = $entitlement['max_downloads'] ?? null;
        if ($max !== null && (int) $entitlement['download_count'] >= (int) $max) {
            throw DownloadException::limitReached();
        }

        return $entitlement;
    }
}
