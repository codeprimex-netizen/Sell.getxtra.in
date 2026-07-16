<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Seller\PayoutRepositoryInterface;
use App\Domain\Seller\SellerProfileRepositoryInterface;
use App\Domain\Seller\SellerStatsRepositoryInterface;

final class InMemorySellerProfileRepository implements SellerProfileRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];

    public function find(int $userId): ?array
    {
        return $this->rows[$userId] ?? null;
    }

    public function create(int $userId, string $displayName): void
    {
        $this->rows[$userId] ??= [
            'user_id' => $userId, 'display_name' => $displayName, 'kyc_status' => 'none',
            'kyc_ref' => null, 'payout_method' => null, 'payout_details_enc' => null, 'commission_rate' => 20.0,
        ];
    }

    public function updateKycStatus(int $userId, string $status, ?string $kycRef = null): bool
    {
        if (!isset($this->rows[$userId])) {
            return false;
        }
        $this->rows[$userId]['kyc_status'] = $status;
        if ($kycRef !== null) {
            $this->rows[$userId]['kyc_ref'] = $kycRef;
        }
        return true;
    }

    public function setPayoutMethod(int $userId, string $method, string $encryptedDetails): bool
    {
        if (!isset($this->rows[$userId])) {
            return false;
        }
        $this->rows[$userId]['payout_method'] = $method;
        $this->rows[$userId]['payout_details_enc'] = $encryptedDetails;
        return true;
    }

    public function pendingKyc(int $limit = 50): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => $r['kyc_status'] === 'pending'));
    }

    public function commissionRate(int $userId): ?float
    {
        return isset($this->rows[$userId]) ? (float) $this->rows[$userId]['commission_rate'] : null;
    }
}

final class InMemoryPayoutRepository implements PayoutRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $data['requested_at'] = date('Y-m-d H:i:s');
        $this->rows[$id] = $data;
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0, ?string $source = null): array
    {
        return array_values(array_filter($this->rows, static fn ($p) =>
            (int) $p['seller_id'] === $sellerId && ($source === null || ($p['source'] ?? 'seller') === $source)));
    }

    public function byStatus(string $status, int $limit = 50, int $offset = 0, ?string $source = null): array
    {
        return array_values(array_filter($this->rows, static fn ($p) =>
            $p['status'] === $status && ($source === null || ($p['source'] ?? 'seller') === $source)));
    }

    public function updateStatus(int $id, string $status, ?string $gatewayRef = null, ?string $note = null): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['status'] = $status;
        if ($gatewayRef !== null) {
            $this->rows[$id]['gateway_ref'] = $gatewayRef;
        }
        if ($note !== null) {
            $this->rows[$id]['note'] = $note;
        }
        return true;
    }

    public function reservedAmount(int $sellerId, ?string $source = null): float
    {
        $sum = 0.0;
        foreach ($this->rows as $p) {
            if ((int) $p['seller_id'] === $sellerId
                && in_array($p['status'], ['requested', 'processing'], true)
                && ($source === null || ($p['source'] ?? 'seller') === $source)) {
                $sum += (float) $p['amount'];
            }
        }
        return $sum;
    }
}

final class InMemorySellerStatsRepository implements SellerStatsRepositoryInterface
{
    public function summary(int $sellerId): array
    {
        return ['units' => 5, 'revenue' => 5000.0, 'earnings' => 4000.0, 'views' => 100, 'products' => 3];
    }

    public function topProducts(int $sellerId, int $limit = 5): array
    {
        return [['id' => 1, 'title' => 'Pro Kit', 'slug' => 'pro-kit', 'sales_count' => 5, 'views' => 100, 'avg_rating' => 4.5]];
    }
}
