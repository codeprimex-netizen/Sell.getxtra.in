<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Seller;

use App\Application\Seller\SellerException;
use App\Application\Seller\SellerProfileService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Seller onboarding + KYC (Req 11.1).
 */
final class ProfileController extends Controller
{
    public function __construct(private SellerProfileService $sellers)
    {
    }

    /** Become-a-seller form (any authenticated user). */
    public function onboardForm(Request $request): Response
    {
        return $this->view($request, 'seller.onboard');
    }

    public function onboard(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->redirect('/login');
        }
        $this->sellers->becomeSeller($userId, (string) $request->input('display_name', ''));
        $this->flash($request, 'success', 'Welcome aboard! Complete KYC to start selling.');
        return $this->redirect('/seller/profile');
    }

    public function show(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;
        return $this->view($request, 'seller.profile', [
            'profile' => $this->sellers->profile($userId),
        ]);
    }

    public function submitKyc(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;
        try {
            $this->sellers->submitKyc($userId, (string) $request->input('kyc_ref', 'KYC-' . $userId));
            $this->flash($request, 'success', 'KYC submitted for review.');
        } catch (SellerException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/seller/profile');
    }

    public function setPayoutMethod(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;
        $this->sellers->setPayoutMethod(
            $userId,
            (string) $request->input('method', 'bank'),
            (string) $request->input('details', ''),
        );
        $this->flash($request, 'success', 'Payout method saved.');
        return $this->redirect('/seller/profile');
    }
}
