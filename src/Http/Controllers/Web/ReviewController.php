<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Review\ReviewException;
use App\Application\Review\ReviewService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Review submission, seller replies, and moderation (Req 7.2-7.5).
 */
final class ReviewController extends Controller
{
    public function __construct(private ReviewService $reviews)
    {
    }

    public function store(Request $request, string $id): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->redirect('/login');
        }

        try {
            $this->reviews->submit(
                (int) $id,
                $userId,
                (int) $request->input('rating', 0),
                $request->input('comment') !== null ? (string) $request->input('comment') : null,
            );
            $this->flash($request, 'success', 'Thanks! Your review has been posted.');
        } catch (ReviewException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->back($request);
    }

    public function reply(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request);
        if ($sellerId === null) {
            return $this->redirect('/login');
        }

        try {
            $this->reviews->reply((int) $id, $sellerId, (string) $request->input('reply', ''));
            $this->flash($request, 'success', 'Reply posted.');
        } catch (ReviewException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->back($request);
    }

    public function moderate(Request $request, string $id): Response
    {
        try {
            $this->reviews->moderate((int) $id, (string) $request->input('status', ''));
            $this->flash($request, 'success', 'Review updated.');
        } catch (ReviewException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->back($request);
    }

    private function back(Request $request): Response
    {
        $referer = $request->header('Referer');
        return $this->redirect($referer !== null && $referer !== '' ? $referer : '/products');
    }
}
