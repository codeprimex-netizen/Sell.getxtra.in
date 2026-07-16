<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Notification\NotificationPreferenceService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * One-click email unsubscribe via a signed token (Req 13.3). Public so it
 * works straight from an email link with no login required.
 */
final class UnsubscribeController extends Controller
{
    public function __construct(private NotificationPreferenceService $preferences)
    {
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        $ok = $this->preferences->unsubscribe($token);

        return $this->view($request, 'unsubscribe', [
            'ok' => $ok,
        ], $ok ? 200 : 404);
    }
}
