<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Affiliate\AffiliateRepositoryInterface;
use InvalidArgumentException;
use PDO;

final class PdoAffiliateRepository extends Repository implements AffiliateRepositoryInterface
{
    protected string $table = 'affiliates';

    /** Counters that may be incremented — whitelisted to keep the column safe. */
    private const COUNTERS = ['clicks', 'signups', 'conversions'];

    public function create(array $data): int
    {
        return $this->insert([
            'user_id'         => (int) $data['user_id'],
            'code'            => (string) $data['code'],
            'commission_rate' => (float) ($data['commission_rate'] ?? 10.0),
            'status'          => (string) ($data['status'] ?? 'active'),
        ]);
    }

    public function findByUser(int $userId): ?array
    {
        return $this->firstWhere('user_id', $userId);
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE code = :c LIMIT 1"
        );
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT 1 FROM {$this->table} WHERE code = :c LIMIT 1"
        );
        $stmt->execute(['c' => $code]);
        return $stmt->fetchColumn() !== false;
    }

    public function incrementCounter(int $id, string $counter, int $by = 1): void
    {
        if (!in_array($counter, self::COUNTERS, true)) {
            throw new InvalidArgumentException("Unknown counter [{$counter}].");
        }
        // $counter is validated against a fixed whitelist above.
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET {$counter} = {$counter} + :by WHERE id = :id"
        );
        $stmt->bindValue('by', $by, PDO::PARAM_INT);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<string,mixed>|null */
    private function firstWhere(string $column, int|string $value): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE {$column} = :v LIMIT 1"
        );
        $stmt->execute(['v' => $value]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
