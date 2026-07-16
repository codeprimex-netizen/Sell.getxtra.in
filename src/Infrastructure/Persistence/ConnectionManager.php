<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Config\Config;
use PDO;

/**
 * Manages PDO connections with a read/write split.
 *
 * The primary connection handles writes and transactions; reads may be
 * routed to a replica (Req 16.3). Connections are lazily created and
 * memoized. If no replica is configured, reads fall back to the primary.
 */
final class ConnectionManager
{
    private ?PDO $write = null;

    private ?PDO $read = null;

    /** @var array<string, mixed> */
    private array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    public function write(): PDO
    {
        return $this->write ??= $this->connect(
            (string) Config::get('db.host'),
            (int) Config::get('db.port', 3306),
        );
    }

    public function read(): PDO
    {
        // Inside a transaction we must stay on the primary for consistency.
        if ($this->write !== null && $this->write->inTransaction()) {
            return $this->write;
        }

        $readHost = Config::get('db.read_host');
        if ($readHost === null || $readHost === '') {
            return $this->write();
        }

        return $this->read ??= $this->connect(
            (string) $readHost,
            (int) Config::get('db.read_port', 3306),
        );
    }

    private function connect(string $host, int $port): PDO
    {
        $db = (string) Config::get('db.database');
        $charset = (string) Config::get('db.charset', 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        return new PDO(
            $dsn,
            (string) Config::get('db.username'),
            (string) Config::get('db.password'),
            $this->options,
        );
    }

    /**
     * Run a set of operations inside a transaction on the primary.
     *
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->write();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
