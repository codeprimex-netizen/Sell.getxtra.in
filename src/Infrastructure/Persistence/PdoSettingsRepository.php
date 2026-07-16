<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Admin\SettingsRepositoryInterface;

/**
 * Key-value platform settings persisted as JSON (Req 12 / 1.7).
 */
final class PdoSettingsRepository extends Repository implements SettingsRepositoryInterface
{
    protected string $table = 'settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT `value` FROM {$this->table} WHERE `key` = :k LIMIT 1"
        );
        $stmt->execute(['k' => $key]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return $default;
        }
        $decoded = json_decode((string) $raw, true);
        return $decoded['v'] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT INTO {$this->table} (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        $stmt->execute(['k' => $key, 'v' => json_encode(['v' => $value])]);
    }

    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->connection->read()->query("SELECT `key`, `value` FROM {$this->table}");
        $out = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $out[(string) $row['key']] = $decoded['v'] ?? null;
        }
        return $out;
    }
}
