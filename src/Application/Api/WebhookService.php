<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Api\WebhookEvent;
use App\Domain\Api\WebhookSubscriptionRepositoryInterface;
use App\Infrastructure\Queue\Dispatcher;

/**
 * Manages outbound webhook subscriptions and fans domain events out to them
 * (Req 19.4). Delivery is performed asynchronously by the `webhook.dispatch`
 * job, which signs the payload (HMAC-SHA256) and is retried with backoff by
 * the queue worker; exhausted deliveries land in the dead-letter table.
 */
final class WebhookService
{
    private const QUEUE = 'webhooks';

    public function __construct(
        private WebhookSubscriptionRepositoryInterface $subscriptions,
        private Dispatcher $dispatcher,
    ) {
    }

    /**
     * Register a subscription. The signing secret is generated server-side
     * and returned so the caller can verify future deliveries.
     *
     * @param array<int, string> $events
     * @return array{id:int, secret:string, events:array<int,string>}
     * @throws \InvalidArgumentException when the URL is not a valid https/http URL
     */
    public function subscribe(int $userId, string $url, array $events = []): array
    {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('A valid http(s) URL is required.');
        }

        $events = WebhookEvent::sanitize($events);
        $secret = bin2hex(random_bytes(32));

        $id = $this->subscriptions->create([
            'user_id'   => $userId,
            'url'       => mb_substr($url, 0, 500),
            'secret'    => $secret,
            'events'    => implode(',', $events),
            'is_active' => 1,
        ]);

        return ['id' => $id, 'secret' => $secret, 'events' => $events];
    }

    /** @return array<int, array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->subscriptions->forUser($userId);
    }

    public function unsubscribe(int $id, int $userId): bool
    {
        return $this->subscriptions->deleteForUser($id, $userId);
    }

    /**
     * Emit an event to every active matching subscription. Returns the number
     * of deliveries enqueued.
     *
     * @param array<string,mixed> $data
     */
    public function emit(string $event, array $data): int
    {
        if (!WebhookEvent::isValid($event) || $event === WebhookEvent::WILDCARD) {
            return 0;
        }

        $count = 0;
        foreach ($this->subscriptions->activeForEvent($event) as $sub) {
            $this->dispatcher->dispatch('webhook.dispatch', [
                'url'    => (string) $sub['url'],
                'event'  => $event,
                'data'   => $data,
                'secret' => (string) $sub['secret'],
            ], self::QUEUE);
            $this->subscriptions->markDelivered((int) $sub['id']);
            $count++;
        }

        return $count;
    }
}
