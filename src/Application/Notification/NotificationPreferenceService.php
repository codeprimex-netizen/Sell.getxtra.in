<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Notification\NotificationPreferenceRepositoryInterface;

/**
 * Manages notification preferences + one-click email unsubscribe (Req 13.3).
 */
final class NotificationPreferenceService
{
    public function __construct(private NotificationPreferenceRepositoryInterface $preferences)
    {
    }

    /** @return array<string,mixed> */
    public function get(int $userId): array
    {
        return $this->preferences->getOrCreate($userId);
    }

    public function setEmailEnabled(int $userId, bool $enabled): void
    {
        $this->preferences->setEmailEnabled($userId, $enabled);
    }

    /** Unsubscribe via signed link token. Returns true if a user matched. */
    public function unsubscribe(string $token): bool
    {
        $userId = $this->preferences->userIdForToken($token);
        if ($userId === null) {
            return false;
        }
        $this->preferences->setEmailEnabled($userId, false);
        return true;
    }
}
