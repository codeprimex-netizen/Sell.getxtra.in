<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Application\Notification\NotificationService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * The authenticated user's in-app notification centre (Req 13.2): list,
 * mark one read, and mark all read.
 */
final class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function index(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;

        return $this->view($request, 'account.notifications', [
            'notifications' => $this->notifications->forUser($userId, 50),
            'unread'        => $this->notifications->unreadCount($userId),
        ]);
    }

    public function markRead(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        $id = (int) $request->input('id', 0);

        if ($userId !== null && $id > 0) {
            $this->notifications->markRead($id, $userId);
        }

        return $this->redirect('/account/notifications');
    }

    public function markAllRead(Request $request): Response
    {
        $userId = $this->currentUserId($request);

        if ($userId !== null) {
            $this->notifications->markAllRead($userId);
            $this->flash($request, 'success', 'All notifications marked as read.');
        }

        return $this->redirect('/account/notifications');
    }
}
