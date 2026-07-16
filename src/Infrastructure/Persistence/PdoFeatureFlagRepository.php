<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Admin\FeatureFlagRepositoryInterface;

/**
 * Feature flags with an optional rollout percentage (Req 1.7).
 */
final class PdoFeatureFlagRepository extends Repository implements FeatureFlagRepositoryInterface
{
    protected string $table = 'feature_flags';

    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->connection->read()->query("SELECT * FROM {$this->table} ORDER BY name ASC");
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    public function isEnabled(string $name): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT is_enabled FROM {$this->table} WHERE name = :n LIMIT 1"
        );
        $stmt->execute(['n' => $name]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function setEnabled(string $name, bool $enabled, int $rolloutPercent = 100): void
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT INTO {$this->table} (name, is_enabled, rollout_percent) VALUES (:n, :e, :r)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), rollout_percent = VALUES(rollout_percent)"
        );
        $stmt->execute(['n' => $name, 'e' => $enabled ? 1 : 0, 'r' => max(0, min(100, $rolloutPercent))]);
    }
}
