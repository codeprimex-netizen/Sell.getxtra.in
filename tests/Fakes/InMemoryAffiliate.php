<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Affiliate\AffiliateRepositoryInterface;
use App\Domain\Affiliate\ReferralRepositoryInterface;

/**
 * In-memory affiliate + referral repositories for Phase-20.2 tests.
 */
final class InMemoryAffiliateRepository implements AffiliateRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = array_merge([
            'id' => $id, 'commission_rate' => 10.0, 'status' => 'active',
            'clicks' => 0, 'signups' => 0, 'conversions' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ], $data, ['id' => $id]);
        return $id;
    }

    public function findByUser(int $userId): ?array
    {
        foreach ($this->rows as $r) {
            if ((int) $r['user_id'] === $userId) {
                return $r;
            }
        }
        return null;
    }

    public function findByCode(string $code): ?array
    {
        foreach ($this->rows as $r) {
            if (($r['code'] ?? null) === $code) {
                return $r;
            }
        }
        return null;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function codeExists(string $code): bool
    {
        return $this->findByCode($code) !== null;
    }

    public function incrementCounter(int $id, string $counter, int $by = 1): void
    {
        if (isset($this->rows[$id]) && in_array($counter, ['clicks', 'signups', 'conversions'], true)) {
            $this->rows[$id][$counter] = (int) $this->rows[$id][$counter] + $by;
        }
    }
}

final class InMemoryReferralRepository implements ReferralRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = array_merge([
            'id' => $id, 'status' => 'clicked', 'referred_user_id' => null,
            'order_id' => null, 'commission' => null, 'currency' => null,
            'created_at' => date('Y-m-d H:i:s'), 'converted_at' => null,
        ], $data, ['id' => $id]);
        return $id;
    }

    public function findByVisitor(string $visitorToken): ?array
    {
        foreach ($this->rows as $r) {
            if (($r['visitor_token'] ?? null) === $visitorToken) {
                return $r;
            }
        }
        return null;
    }

    public function findByReferredUser(int $userId): ?array
    {
        $match = null;
        foreach ($this->rows as $r) {
            if ((int) ($r['referred_user_id'] ?? 0) === $userId) {
                $match = $r;
            }
        }
        return $match;
    }

    public function hasReferredUser(int $userId): bool
    {
        return $this->findByReferredUser($userId) !== null;
    }

    public function attachAffiliate(int $referralId, int $affiliateId): void
    {
        if (isset($this->rows[$referralId]) && $this->rows[$referralId]['status'] === 'clicked') {
            $this->rows[$referralId]['affiliate_id'] = $affiliateId;
        }
    }

    public function markSignedUp(int $referralId, int $referredUserId): void
    {
        if (isset($this->rows[$referralId])) {
            $this->rows[$referralId]['referred_user_id'] = $referredUserId;
            $this->rows[$referralId]['status'] = 'signed_up';
        }
    }

    public function markConverted(int $referralId, int $orderId, float $commission, string $currency): void
    {
        if (isset($this->rows[$referralId])) {
            $this->rows[$referralId]['status'] = 'converted';
            $this->rows[$referralId]['order_id'] = $orderId;
            $this->rows[$referralId]['commission'] = $commission;
            $this->rows[$referralId]['currency'] = $currency;
            $this->rows[$referralId]['converted_at'] = date('Y-m-d H:i:s');
        }
    }

    public function forAffiliate(int $affiliateId, int $limit = 100): array
    {
        $rows = array_values(array_filter($this->rows, static fn ($r) => (int) $r['affiliate_id'] === $affiliateId));
        usort($rows, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_slice($rows, 0, $limit);
    }

    public function convertedBefore(string $before, int $limit = 500): array
    {
        $rows = array_values(array_filter($this->rows, static fn ($r) =>
            ($r['status'] ?? '') === 'converted'
            && !empty($r['converted_at'])
            && (string) $r['converted_at'] < $before));
        return array_slice($rows, 0, $limit);
    }
}
