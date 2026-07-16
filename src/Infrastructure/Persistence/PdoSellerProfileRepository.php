<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Seller\SellerProfileRepositoryInterface;
use PDO;

final class PdoSellerProfileRepository extends Repository implements SellerProfileRepositoryInterface
{
    protected string $table = 'seller_profiles';
    protected string $primaryKey = 'user_id';

    // Widened param keeps compatibility with base Repository::find(int|string).
    public function find(int|string $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }

    public function create(int $userId, string $displayName): void
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT IGNORE INTO {$this->table} (user_id, display_name, kyc_status)
             VALUES (:u, :d, 'none')"
        );
        $stmt->execute(['u' => $userId, 'd' => mb_substr($displayName, 0, 150)]);
    }

    public function updateKycStatus(int $userId, string $status, ?string $kycRef = null): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET kyc_status = :s, kyc_ref = COALESCE(:r, kyc_ref) WHERE user_id = :u"
        );
        return $stmt->execute(['s' => $status, 'r' => $kycRef, 'u' => $userId]);
    }

    public function setPayoutMethod(int $userId, string $method, string $encryptedDetails): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET payout_method = :m, payout_details_enc = :d WHERE user_id = :u"
        );
        $stmt->bindValue('m', $method);
        $stmt->bindValue('d', $encryptedDetails, PDO::PARAM_LOB);
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function pendingKyc(int $limit = 50): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT sp.*, u.email FROM {$this->table} sp
             INNER JOIN users u ON u.id = sp.user_id
             WHERE sp.kyc_status = 'pending' ORDER BY sp.updated_at ASC LIMIT :lim"
        );
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function commissionRate(int $userId): ?float
    {
        $row = $this->find($userId);
        return $row !== null ? (float) $row['commission_rate'] : null;
    }
}
