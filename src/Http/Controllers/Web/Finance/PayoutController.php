<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Application\Seller\PayoutService;
use App\Application\Seller\SellerException;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Finance payout processing queue (Req 11.3/11.5).
 */
final class PayoutController extends Controller
{
    public function __construct(private PayoutService $payouts)
    {
    }

    public function queue(Request $request): Response
    {
        return $this->view($request, 'finance.payouts', [
            'payouts' => $this->payouts->queue(),
            'wide'    => true,
        ]);
    }

    public function pay(Request $request, string $id): Response
    {
        try {
            $this->payouts->markPaid((int) $id, $this->currentUserId($request) ?? 0, (string) $request->input('gateway_ref', ''));
            $this->flash($request, 'success', 'Payout marked paid.');
        } catch (SellerException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/finance/payouts');
    }

    public function reject(Request $request, string $id): Response
    {
        try {
            $this->payouts->reject((int) $id, $this->currentUserId($request) ?? 0, (string) $request->input('note', 'Rejected'));
            $this->flash($request, 'success', 'Payout rejected.');
        } catch (SellerException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/finance/payouts');
    }
}
