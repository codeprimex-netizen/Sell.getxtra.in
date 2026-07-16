<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Commerce\CartService;
use App\Application\Commerce\CheckoutService;
use App\Application\Commerce\CommerceException;
use App\Application\Commerce\PricingService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use App\Support\Security\Token;

/**
 * Checkout (Req 8/9). Shows the review page and creates an idempotent order,
 * then hands off to the selected gateway's hosted checkout.
 */
final class CheckoutController extends Controller
{
    public function __construct(
        private CartService $cart,
        private CheckoutService $checkout,
        private PricingService $pricing,
    ) {
    }

    public function show(Request $request): Response
    {
        $cartId = $this->cartId($request);
        $items = $this->cart->items($cartId);
        $currency = (string) Config::get('commerce.currency', 'INR');

        // A fresh idempotency key per rendered checkout form.
        $session = $this->session($request);
        $key = Token::random(16);
        $session?->put('checkout_key', $key);

        return $this->view($request, 'checkout.index', [
            'items'   => $items,
            'totals'  => $this->pricing->price($items, $currency),
            'key'     => $key,
            'gateway' => (string) Config::get('commerce.default_gateway', 'offline'),
            'wide'    => true,
        ]);
    }

    public function process(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->redirect('/login');
        }

        $session = $this->session($request);
        // Reuse the form's key so a double-submit is idempotent.
        $key = (string) ($request->input('idempotency_key') ?: $session?->get('checkout_key') ?: Token::random(16));
        $cartId = $this->cartId($request);

        try {
            $result = $this->checkout->checkout(
                $userId,
                $cartId,
                $request->input('coupon') !== null ? (string) $request->input('coupon') : null,
                $key,
                $request->input('gateway') !== null ? (string) $request->input('gateway') : null,
            );
        } catch (CommerceException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect('/cart');
        }

        // Redirect to the gateway's hosted checkout / dev pay page.
        return $this->redirect($result['intent']->redirectUrl);
    }

    private function cartId(Request $request): int
    {
        $session = $request->attribute('session');
        $sessionKey = $session instanceof Session ? $session->id() : 'cli';
        return $this->cart->resolveCartId($this->currentUserId($request), $sessionKey);
    }
}
