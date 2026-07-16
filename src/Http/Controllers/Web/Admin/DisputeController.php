<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Commerce\CommerceException;
use App\Application\Support\DisputeException;
use App\Application\Support\DisputeService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Dispute handling for back-office staff (Req 12.4).
 */
final class DisputeController extends Controller
{
    public function __construct(private DisputeService $disputes)
    {
    }

    public function index(Request $request): Response
    {
        $status = $request->query('status') !== null ? (string) $request->query('status') : null;
        return $this->view($request, 'admin.disputes', [
            'disputes' => $this->disputes->queue($status),
            'status'   => $status,
            'wide'     => true,
        ]);
    }

    public function resolve(Request $request, string $id): Response
    {
        return $this->act($request, fn () => $this->disputes->resolve((int) $id, (string) $request->input('resolution', 'Resolved'), $this->actor($request)), 'Dispute resolved.');
    }

    public function reject(Request $request, string $id): Response
    {
        return $this->act($request, fn () => $this->disputes->reject((int) $id, (string) $request->input('resolution', 'Rejected'), $this->actor($request)), 'Dispute rejected.');
    }

    public function refund(Request $request, string $id): Response
    {
        $amount = (float) $request->input('amount', 0);
        return $this->act($request, fn () => $this->disputes->refund((int) $id, $amount, (string) $request->input('resolution', 'Refunded'), $this->actor($request)), 'Dispute refunded.');
    }

    private function act(Request $request, callable $action, string $success): Response
    {
        try {
            $action();
            $this->flash($request, 'success', $success);
        } catch (DisputeException | CommerceException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/disputes');
    }

    private function actor(Request $request): int
    {
        return $this->currentUserId($request) ?? 0;
    }
}
