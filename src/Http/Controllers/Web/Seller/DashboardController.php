<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Seller;

use App\Application\Seller\SellerDashboardService;
use App\Application\Seller\SellerProfileService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Seller dashboard (Req 11.2): sales, earnings, wallet, conversion, top items.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private SellerDashboardService $dashboard,
        private SellerProfileService $sellers,
    ) {
    }

    public function index(Request $request): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;

        return $this->view($request, 'seller.dashboard', array_merge(
            $this->dashboard->forSeller($sellerId),
            ['profile' => $this->sellers->profile($sellerId), 'wide' => true],
        ));
    }
}
