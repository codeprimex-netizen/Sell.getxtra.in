<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Commerce\CommerceException;
use App\Application\Commerce\CartService;
use App\Application\Commerce\PricingService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;

/**
 * Shopping cart (Req 8.1). Works for guests (session-keyed) and logged-in
 * users; the guest cart is adopted on login by CartService.
 */
final class CartController extends Controller
{
    public function __construct(
        private CartService $cart,
        private PricingService $pricing,
    ) {
    }

    public function index(Request $request): Response
    {
        $cartId = $this->cartId($request);
        $items = $this->cart->items($cartId);
        $currency = (string) Config::get('commerce.currency', 'INR');
        $totals = $this->pricing->price($items, $currency);

        return $this->view($request, 'cart.index', [
            'items'  => $items,
            'totals' => $totals,
            'wide'   => true,
        ]);
    }

    public function add(Request $request): Response
    {
        $productId = (int) $request->input('product_id', 0);
        try {
            $this->cart->add($this->cartId($request), $productId, null);
            $this->flash($request, 'success', 'Added to cart.');
        } catch (CommerceException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/cart');
    }

    public function remove(Request $request): Response
    {
        $this->cart->remove($this->cartId($request), (int) $request->input('product_id', 0));
        $this->flash($request, 'success', 'Removed from cart.');
        return $this->redirect('/cart');
    }

    private function cartId(Request $request): int
    {
        $session = $request->attribute('session');
        $sessionKey = $session instanceof Session ? $session->id() : 'cli';
        return $this->cart->resolveCartId($this->currentUserId($request), $sessionKey);
    }
}
