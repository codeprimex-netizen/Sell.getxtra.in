<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Api\WebhookService;
use App\Domain\Api\WebhookEvent;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

/**
 * Outbound webhook subscription management API (Req 19.4). Guarded by
 * `scope:webhooks.manage`. The signing secret is returned only at creation.
 */
final class WebhookController extends ApiController
{
    public function __construct(private WebhookService $webhooks)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $items = array_map(static fn (array $s): array => [
            'id'                => (int) $s['id'],
            'url'               => (string) $s['url'],
            'events'            => array_values(array_filter(explode(',', (string) $s['events']))),
            'is_active'         => (bool) $s['is_active'],
            'last_delivered_at' => $s['last_delivered_at'] ?? null,
            'created_at'        => $s['created_at'] ?? null,
        ], $this->webhooks->listForUser($userId));

        return $this->ok($request, $items, [
            'count'            => count($items),
            'available_events' => WebhookEvent::all(),
        ]);
    }

    public function store(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $url = (string) ($request->input('url') ?? '');
        $events = $request->input('events', []);
        if (is_string($events)) {
            $events = array_map('trim', explode(',', $events));
        }

        try {
            $result = $this->webhooks->subscribe($userId, $url, is_array($events) ? $events : []);
        } catch (InvalidArgumentException $e) {
            return $this->error('validation_error', $e->getMessage(), 422);
        }

        return $this->ok($request, [
            'id'     => $result['id'],
            'events' => $result['events'],
            // Shown once so the integrator can verify the X-Getxtra-Signature.
            'secret' => $result['secret'],
        ], [], 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $userId = (int) $this->currentUserId($request);
        $deleted = $this->webhooks->unsubscribe((int) $id, $userId);

        if (!$deleted) {
            return $this->notFound('Subscription not found.');
        }

        return $this->ok($request, ['deleted' => true]);
    }
}
