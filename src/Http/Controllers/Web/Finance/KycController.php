<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Application\Seller\SellerProfileService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * KYC review queue for finance/admin staff (Req 11.1).
 */
final class KycController extends Controller
{
    public function __construct(private SellerProfileService $sellers)
    {
    }

    public function queue(Request $request): Response
    {
        return $this->view($request, 'finance.kyc', [
            'pending' => $this->sellers->pendingKyc(),
            'wide'    => true,
        ]);
    }

    public function verify(Request $request, string $id): Response
    {
        $this->sellers->verifyKyc((int) $id, $this->currentUserId($request) ?? 0);
        $this->flash($request, 'success', 'KYC verified.');
        return $this->redirect('/finance/kyc');
    }

    public function reject(Request $request, string $id): Response
    {
        $this->sellers->rejectKyc((int) $id, $this->currentUserId($request) ?? 0);
        $this->flash($request, 'success', 'KYC rejected.');
        return $this->redirect('/finance/kyc');
    }
}
