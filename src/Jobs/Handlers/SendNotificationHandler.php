<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Application\Notification\NotificationService;
use App\Infrastructure\Queue\JobHandler;

/**
 * Creates an in-app notification (Req 13.2). Payload: user_id, type, data.
 */
final class SendNotificationHandler implements JobHandler
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $this->notifications->notify(
            $userId,
            (string) ($payload['type'] ?? 'system'),
            (array) ($payload['data'] ?? []),
        );
    }
}
