<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Affiliate\AffiliateService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Public referral landing (Req 20.2): /r/{code}. Records the click against a
 * long-lived visitor cookie and redirects into the storefront. The cookie is
 * later read at signup to attribute the referral.
 */
final class ReferralController extends Controller
{
    private const COOKIE = 'gx_vid';

    public function __construct(private AffiliateService $affiliates)
    {
    }

    public function land(Request $request, string $code): Response
    {
        $vid = (string) ($request->cookie(self::COOKIE) ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
            $vid = bin2hex(random_bytes(16));
        }

        $this->affiliates->recordClick($code, $vid);

        $secure = (bool) Config::get('session.secure', true) ? '; Secure' : '';
        $cookie = self::COOKIE . '=' . $vid . '; Path=/; Max-Age=7776000; HttpOnly; SameSite=Lax' . $secure;

        return $this->redirect('/products')->withHeader('Set-Cookie', $cookie);
    }
}
