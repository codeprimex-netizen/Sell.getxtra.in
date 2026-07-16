<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Application\Affiliate\AffiliatePayoutException;
use App\Application\Affiliate\AffiliatePayoutService;
use App\Application\Affiliate\AffiliateService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Affiliate dashboard (Req 20.2): enrol, view the referral link, funnel
 * counters, commission earnings, and request payouts.
 */
final class AffiliateController extends Controller
{
    public function __construct(
        private AffiliateService $affiliates,
        private AffiliatePayoutService $payouts,
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $stats = $this->affiliates->stats($userId);
        $enrolled = (bool) ($stats['enrolled'] ?? false);

        $base = rtrim((string) Config::get('app.url', ''), '/');
        $link = $enrolled ? $base . '/r/' . $stats['code'] : '';

        return $this->view($request, 'account.affiliate', [
            'enabled'  => $this->affiliates->isEnabled(),
            'stats'    => $stats,
            'link'     => $link,
            'wallet'   => $enrolled ? $this->payouts->wallet($userId) : null,
            'payouts'  => $enrolled ? $this->payouts->payouts($userId) : [],
        ]);
    }

    public function requestPayout(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $amount = (float) $request->input('amount', 0);

        try {
            $this->payouts->request($userId, $amount);
            $this->flash($request, 'success', 'Payout requested. Finance will process it shortly.');
        } catch (AffiliatePayoutException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect('/account/affiliate');
    }

    public function enroll(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);

        if (!$this->affiliates->isEnabled()) {
            $this->flash($request, 'error', 'The affiliate program is not currently available.');
            return $this->redirect('/account/affiliate');
        }

        $this->affiliates->enroll($userId);
        $this->flash($request, 'success', 'You are enrolled in the affiliate program. Share your link to start earning.');

        return $this->redirect('/account/affiliate');
    }
}
