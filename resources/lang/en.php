<?php

declare(strict_types=1);

/**
 * English message catalog (Req 20.4). Keys use dot notation and :placeholder
 * tokens for interpolation.
 */
return [
    'app' => [
        'tagline' => 'Premium digital products for developers and creators',
    ],
    'nav' => [
        'login'     => 'Log in',
        'register'  => 'Sign up',
        'dashboard' => 'Dashboard',
        'logout'    => 'Log out',
    ],
    'catalog' => [
        'title'    => 'Browse products',
        'search'   => 'Search',
        'related'  => 'Related products',
        'no_items' => 'No products found.',
    ],
    'cart' => [
        'title'    => 'Your cart',
        'checkout' => 'Checkout',
        'empty'    => 'Your cart is empty.',
        'added'    => ':title was added to your cart.',
    ],
    'order' => [
        'confirmed' => 'Order :number confirmed — thank you!',
    ],
    'notification' => [
        'unread' => ':count unread',
    ],
];
