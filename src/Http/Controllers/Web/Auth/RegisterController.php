<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Application\Affiliate\AffiliateService;
use App\Application\Identity\AuthException;
use App\Application\Identity\RegistrationService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validation\Validator;

/**
 * Account registration (Req 2.1). On success an email-verification token is
 * issued; in debug mode the link is surfaced via flash for local testing.
 */
final class RegisterController extends Controller
{
    public function __construct(
        private RegistrationService $registration,
        private ?AffiliateService $affiliates = null,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->view($request, 'auth.register');
    }

    public function store(Request $request): Response
    {
        $data = $request->all();
        $validator = Validator::make($data, [
            'name'                  => 'required|max:120',
            'email'                 => 'required|email|max:190',
            'password'              => 'required|min:10|confirmed',
            'terms'                 => 'accepted',
        ]);

        if ($validator->fails()) {
            return $this->view($request, 'auth.register', [
                'errors' => $validator->errors(),
                'old'    => ['name' => $data['name'] ?? '', 'email' => $data['email'] ?? ''],
            ], 422);
        }

        try {
            $result = $this->registration->register(
                (string) $request->input('name'),
                (string) $request->input('email'),
                (string) $request->input('password'),
            );
        } catch (AuthException $e) {
            return $this->view($request, 'auth.register', [
                'errors' => ['email' => [$e->getMessage()]],
                'old'    => ['name' => $data['name'] ?? '', 'email' => $data['email'] ?? ''],
            ], 422);
        }

        // Attribute an affiliate referral if the visitor arrived via a link (Req 20.2).
        $vid = (string) ($request->cookie('gx_vid') ?? '');
        if ($vid !== '') {
            $this->affiliates?->attributeSignup($vid, (int) $result['user_id']);
        }

        $message = 'Account created. Please check your email to verify your address.';
        if ((bool) Config::get('app.debug', false)) {
            $link = url('/verify-email?token=' . $result['verification_token']);
            $message .= " [dev] Verify: {$link}";
        }
        $this->flash($request, 'success', $message);

        return $this->redirect('/login');
    }
}
