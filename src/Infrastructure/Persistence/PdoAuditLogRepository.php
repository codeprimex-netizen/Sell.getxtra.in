<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Audit\AuditLogRepositoryInterface;
use PDO;

/**
 * PDO-backed audit log (Req 15.5). IP is stored as packed binary; JSON
 * columns hold before/after snapshots. Insert-only.
 */
final class PdoAuditLogRepository extends Repository implements AuditLogRepositoryInterface
{
    protected string $table = 'audit_logs';

    public function record(
        ?int $actorId,
        string $action,
        ?string $targetType,
        ?int $targetId,
        array $before,
        array $after,
        ?string $ip,
        ?string $requestId,
    ): void {
        $stmt = $this->connection->write()->prepare(
            "INSERT INTO {$this->table}
                (actor_id, action, target_type, target_id, before_json, after_json, ip, request_id)
             VALUES (:actor, :action, :ttype, :tid, :before, :after, :ip, :rid)"
        );

        $packedIp = $ip !== null && $ip !== '' ? @inet_pton($ip) : false;

        $stmt->bindValue('actor', $actorId, $actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('action', $action);
        $stmt->bindValue('ttype', $targetType);
        $stmt->bindValue('tid', $targetId, $targetId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('before', $before === [] ? null : json_encode($before));
        $stmt->bindValue('after', $after === [] ? null : json_encode($after));
        $stmt->bindValue('ip', $packedIp === false ? null : $packedIp, $packedIp === false ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue('rid', $requestId);
        $stmt->execute();
    }

    public function forTarget(string $targetType, int $targetId, int $limit = 50): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE target_type = :t AND target_id = :id ORDER BY id DESC LIMIT :lim"
        );
        $stmt->bindValue('t', $targetType);
        $stmt->bindValue('id', $targetId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
