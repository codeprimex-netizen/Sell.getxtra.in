<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Analytics\AnalyticsService;
use App\Http\Request;
use App\Http\Response;

/**
 * Self-hosted analytics beacon (Req 20 / 16.3). Accepts a client event and
 * records it only when the caller signals analytics consent. CSRF-exempt (it
 * lives under /api) and rate-limited at the route level.
 */
final class EventController extends ApiController
{
    /** Events the beacon will accept — an allowlist keeps the metric bounded. */
    private const ALLOWED = ['page_view', 'product_view', 'add_to_cart', 'begin_checkout', 'purchase', 'search'];

    public function __construct(private AnalyticsService $analytics)
    {
    }

    public function track(Request $request): Response
    {
        $event = (string) ($request->input('event') ?? '');
        if (!in_array($event, self::ALLOWED, true)) {
            return $this->error('invalid_event', 'Unknown analytics event.', 422);
        }

        // Consent signalled by the client (mirrors the stored cookie/marketing consent).
        $consented = filter_var($request->input('consent', false), FILTER_VALIDATE_BOOLEAN);
        $props = is_array($request->input('props')) ? $request->input('props') : [];

        $recorded = $this->analytics->track($event, ['path' => (string) ($props['path'] ?? '')], $consented);

        return $this->ok($request, ['recorded' => $recorded], ['consent' => $consented]);
    }
}
