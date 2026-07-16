<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Review\WishlistService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;

/**
 * Wishlist management (Req 7.1). Guests toggle a session-backed wishlist that
 * is merged into their account on login/first authenticated access.
 */
final class WishlistController extends Controller
{
    private const GUEST_KEY = 'guest_wishlist';

    public function __construct(private WishlistService $wishlist)
    {
    }

    public function index(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->redirect('/login');
        }

        $this->mergeGuest($request, $userId);

        return $this->view($request, 'account.wishlist', [
            'products' => $this->wishlist->list($userId),
        ]);
    }

    public function toggle(Request $request): Response
    {
        $productId = (int) $request->input('product_id', 0);
        $session = $this->session($request);
        $userId = $this->currentUserId($request);

        if ($productId <= 0) {
            return $this->back($request);
        }

        if ($userId !== null) {
            $this->mergeGuest($request, $userId);
            $on = $this->wishlist->toggle($userId, $productId);
            $this->flash($request, 'success', $on ? 'Added to your wishlist.' : 'Removed from your wishlist.');
        } elseif ($session instanceof Session) {
            $guest = array_map('intval', (array) $session->get(self::GUEST_KEY, []));
            if (in_array($productId, $guest, true)) {
                $guest = array_values(array_filter($guest, static fn ($id) => $id !== $productId));
            } else {
                $guest[] = $productId;
            }
            $session->put(self::GUEST_KEY, $guest);
            $this->flash($request, 'success', 'Wishlist updated. Sign in to save it to your account.');
        }

        return $this->back($request);
    }

    private function mergeGuest(Request $request, int $userId): void
    {
        $session = $this->session($request);
        if (!$session instanceof Session) {
            return;
        }
        $guest = array_map('intval', (array) $session->get(self::GUEST_KEY, []));
        if ($guest !== []) {
            $this->wishlist->mergeGuest($userId, $guest);
            $session->forget(self::GUEST_KEY);
        }
    }

    private function back(Request $request): Response
    {
        $referer = $request->header('Referer');
        return $this->redirect($referer !== null && $referer !== '' ? $referer : '/products');
    }
}
