<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Audit\AuditLogger;
use App\Infrastructure\Observability\Logger;

/**
 * Records and alerts on security-significant events (Req 14.10): privilege
 * changes, suspicious logins, and mass downloads. Every event is written to
 * the immutable audit trail and emitted as a structured warning log so that
 * alerting rules (Phase 12) can page on it.
 */
final class SecurityEventService
{
    public const PRIVILEGE_CHANGE = 'privilege_change';
    public const SUSPICIOUS_LOGIN = 'suspicious_login';
    public const MASS_DOWNLOAD    = 'mass_download';

    public function __construct(
        private AuditLogger $audit,
        private Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function record(
        string $event,
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        ?string $ip = null,
        ?string $requestId = null,
    ): void {
        $this->audit->log('security.' . $event, $actorId, $targetType, $targetId, $meta, $ip, $requestId);
        $this->logger->warning('Security event: ' . $event, array_merge([
            'actor_id'    => $actorId,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'ip'          => $ip,
        ], $meta));
    }

    public function privilegeChanged(int $actorId, int $targetUserId, string $role, string $direction, ?string $ip = null): void
    {
        $this->record(self::PRIVILEGE_CHANGE, $actorId, 'user', $targetUserId, [
            'role'      => $role,
            'direction' => $direction, // granted | revoked
        ], $ip);
    }

    public function suspiciousLogin(?int $userId, string $reason, ?string $ip = null): void
    {
        $this->record(self::SUSPICIOUS_LOGIN, $userId, 'user', $userId, ['reason' => $reason], $ip);
    }

    public function massDownload(int $userId, int $count, int $windowMinutes, ?string $ip = null): void
    {
        $this->record(self::MASS_DOWNLOAD, $userId, 'user', $userId, [
            'count'          => $count,
            'window_minutes' => $windowMinutes,
        ], $ip);
    }
}
