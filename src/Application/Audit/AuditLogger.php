<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditLogRepositoryInterface;
use Throwable;

/**
 * Thin convenience over the audit repository (Req 15.5). Auditing must never
 * break the request it observes, so failures are swallowed.
 */
final class AuditLogger
{
    public function __construct(private AuditLogRepositoryInterface $repository)
    {
    }

    /** @param array<string,mixed> $meta */
    public function log(
        string $action,
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        ?string $ip = null,
        ?string $requestId = null,
    ): void {
        try {
            $this->repository->record($actorId, $action, $targetType, $targetId, [], $meta, $ip, $requestId);
        } catch (Throwable) {
            // Never let auditing failures surface to the user.
        }
    }
}
