<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Seller;

use App\Application\Seller\PayoutService;
use App\Application\Seller\SellerException;
use App\Application\Seller\SellerWalletService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Seller-facing payouts (Req 11.3): view wallet + history, request a payout.
 */
final class PayoutController extends Controller
{
    public function __construct(
        private PayoutService $payouts,
        private SellerWalletService $wallet,
    ) {
    }

    public function index(Request $request): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        $currency = (string) Config::get('commerce.currency', 'INR');

        return $this->view($request, 'seller.payouts', [
            'wallet'   => $this->wallet->wallet($sellerId, $currency),
            'payouts'  => $this->payouts->forSeller($sellerId),
            'currency' => $currency,
            'wide'     => true,
        ]);
    }

    public function request(Request $request): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        $currency = (string) Config::get('commerce.currency', 'INR');

        try {
            $this->payouts->request($sellerId, (float) $request->input('amount', 0), $currency, (string) $request->input('method', 'bank'));
            $this->flash($request, 'success', 'Payout requested. Finance will process it shortly.');
        } catch (SellerException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/seller/payouts');
    }
}
