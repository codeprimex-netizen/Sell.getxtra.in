<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Persistence\ConnectionManager;
use PDO;

/**
 * Applies and rolls back versioned SQL migrations.
 *
 * Each migration is a PHP file in database/migrations returning an array
 * with 'up' and 'down' SQL strings. Applied migrations are tracked in a
 * `migrations` table so re-runs are idempotent. See Req 22.1.
 */
final class MigrationRunner
{
    public function __construct(
        private ConnectionManager $connection,
        private string $migrationsPath,
    ) {
    }

    public function migrate(): int
    {
        $pdo = $this->connection->write();
        $this->ensureMigrationsTable($pdo);

        $applied = $this->appliedMigrations($pdo);
        $files = $this->migrationFiles();
        $batch = $this->nextBatch($pdo);
        $count = 0;

        foreach ($files as $name => $path) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = require $path;
            $this->runStatements($pdo, (string) ($migration['up'] ?? ''));

            $stmt = $pdo->prepare(
                'INSERT INTO migrations (migration, batch) VALUES (:m, :b)'
            );
            $stmt->execute(['m' => $name, 'b' => $batch]);

            fwrite(STDOUT, "  ✔ migrated: {$name}\n");
            $count++;
        }

        fwrite(STDOUT, $count === 0 ? "Nothing to migrate.\n" : "Migrated {$count} file(s).\n");
        return $count;
    }

    public function rollback(): int
    {
        $pdo = $this->connection->write();
        $this->ensureMigrationsTable($pdo);

        $lastBatch = (int) ($pdo->query('SELECT MAX(batch) FROM migrations')->fetchColumn() ?: 0);
        if ($lastBatch === 0) {
            fwrite(STDOUT, "Nothing to roll back.\n");
            return 0;
        }

        $stmt = $pdo->prepare('SELECT migration FROM migrations WHERE batch = :b ORDER BY id DESC');
        $stmt->execute(['b' => $lastBatch]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $files = $this->migrationFiles();
        $count = 0;

        foreach ($names as $name) {
            if (!isset($files[$name])) {
                continue;
            }
            $migration = require $files[$name];
            $this->runStatements($pdo, (string) ($migration['down'] ?? ''));

            $del = $pdo->prepare('DELETE FROM migrations WHERE migration = :m');
            $del->execute(['m' => $name]);

            fwrite(STDOUT, "  ✔ rolled back: {$name}\n");
            $count++;
        }

        fwrite(STDOUT, "Rolled back {$count} file(s).\n");
        return $count;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return array<int, string> */
    private function appliedMigrations(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    private function nextBatch(PDO $pdo): int
    {
        return (int) ($pdo->query('SELECT MAX(batch) FROM migrations')->fetchColumn() ?: 0) + 1;
    }

    /** @return array<string, string> map of migration name => file path (sorted) */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);

        $map = [];
        foreach ($files as $file) {
            $map[basename($file, '.php')] = $file;
        }
        return $map;
    }

    private function runStatements(PDO $pdo, string $sql): void
    {
        $sql = trim($sql);
        if ($sql === '') {
            return;
        }

        // Split on semicolons at end of line to run multiple statements.
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: []),
            static fn (string $s): bool => $s !== '',
        );

        foreach ($statements as $statement) {
            $pdo->exec(rtrim($statement, ';'));
        }
    }
}
