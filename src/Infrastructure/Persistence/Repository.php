<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/**
 * Base repository providing safe, prepared-statement data access.
 *
 * All queries use bound parameters (Req 14.1). Reads go to the replica
 * connection; writes/transactions use the primary. Subclasses set $table.
 */
abstract class Repository
{
    protected string $table = '';

    protected string $primaryKey = 'id';

    public function __construct(protected ConnectionManager $connection)
    {
    }

    /**
     * Fetch a single row by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function find(int|string $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1";
        $stmt = $this->connection->read()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch a single row matching an equality condition.
     *
     * @return array<string, mixed>|null
     */
    public function findBy(string $column, mixed $value): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$column} = :value LIMIT 1";
        $stmt = $this->connection->read()->prepare($sql);
        $stmt->execute(['value' => $value]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows (optionally paginated with keyset-friendly limit/offset).
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $sql = "SELECT * FROM {$this->table} ORDER BY {$this->primaryKey} DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->connection->read()->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Insert a row and return the new id.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $pdo = $this->connection->write();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update a row by primary key.
     *
     * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }

        $assignments = array_map(
            static fn (string $c): string => "{$c} = :{$c}",
            array_keys($data),
        );

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :__id',
            $this->table,
            implode(', ', $assignments),
            $this->primaryKey,
        );

        $stmt = $this->connection->write()->prepare($sql);
        $data['__id'] = $id;

        return $stmt->execute($data);
    }

    public function delete(int|string $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id";
        $stmt = $this->connection->write()->prepare($sql);

        return $stmt->execute(['id' => $id]);
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) AS c FROM {$this->table}";
        $stmt = $this->connection->read()->query($sql);
        $row = $stmt !== false ? $stmt->fetch() : ['c' => 0];

        return (int) ($row['c'] ?? 0);
    }
}
