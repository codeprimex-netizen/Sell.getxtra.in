<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Affiliate\ReferralRepositoryInterface;
use PDO;

final class PdoReferralRepository extends Repository implements ReferralRepositoryInterface
{
    protected string $table = 'referrals';

    public function create(array $data): int
    {
        return $this->insert([
            'affiliate_id'  => (int) $data['affiliate_id'],
            'visitor_token' => (string) $data['visitor_token'],
            'status'        => (string) ($data['status'] ?? 'clicked'),
        ]);
    }

    public function findByVisitor(string $visitorToken): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE visitor_token = :t LIMIT 1"
        );
        $stmt->execute(['t' => $visitorToken]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByReferredUser(int $userId): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE referred_user_id = :u ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function hasReferredUser(int $userId): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT 1 FROM {$this->table} WHERE referred_user_id = :u LIMIT 1"
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function attachAffiliate(int $referralId, int $affiliateId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET affiliate_id = :a WHERE id = :id AND status = 'clicked'"
        );
        $stmt->execute(['a' => $affiliateId, 'id' => $referralId]);
    }

    public function markSignedUp(int $referralId, int $referredUserId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET referred_user_id = :u, status = 'signed_up' WHERE id = :id"
        );
        $stmt->execute(['u' => $referredUserId, 'id' => $referralId]);
    }

    public function markConverted(int $referralId, int $orderId, float $commission, string $currency): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table}
             SET status = 'converted', order_id = :o, commission = :c, currency = :cur, converted_at = NOW()
             WHERE id = :id"
        );
        $stmt->bindValue('o', $orderId, PDO::PARAM_INT);
        $stmt->bindValue('c', $commission);
        $stmt->bindValue('cur', $currency);
        $stmt->bindValue('id', $referralId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function forAffiliate(int $affiliateId, int $limit = 100): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE affiliate_id = :a ORDER BY id DESC LIMIT :lim"
        );
        $stmt->bindValue('a', $affiliateId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 500)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
