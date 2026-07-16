<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Notification\NotificationPreferenceRepositoryInterface;
use App\Domain\Notification\NotificationRepositoryInterface;
use App\Infrastructure\Queue\Dispatcher;

/**
 * In-app notifications + optional email fan-out (Req 13). notify() always
 * records an in-app notification and, when the user allows email and the
 * caller requests it, enqueues a templated email via the queue.
 */
final class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private NotificationPreferenceRepositoryInterface $preferences,
        private Dispatcher $dispatcher,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array{email?:string, subject?:string, template?:string, vars?:array<string,mixed>} $email
     */
    public function notify(int $userId, string $type, array $data, array $email = []): int
    {
        $id = $this->notifications->create($userId, $type, $data);

        if ($email !== [] && isset($email['email'], $email['subject']) && $this->emailAllowed($userId)) {
            $this->dispatcher->dispatch('email.send', [
                'to'       => $email['email'],
                'subject'  => $email['subject'],
                'template' => $email['template'] ?? 'generic',
                'vars'     => $email['vars'] ?? $data,
            ], 'mail');
        }

        return $id;
    }

    public function emailAllowed(int $userId): bool
    {
        $prefs = $this->preferences->getOrCreate($userId);
        return (int) ($prefs['email_enabled'] ?? 1) === 1;
    }

    /** @return array<int, array<string,mixed>> */
    public function forUser(int $userId, int $limit = 30): array
    {
        return $this->notifications->forUser($userId, $limit);
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    public function markRead(int $id, int $userId): bool
    {
        return $this->notifications->markRead($id, $userId);
    }

    public function markAllRead(int $userId): void
    {
        $this->notifications->markAllRead($userId);
    }
}
