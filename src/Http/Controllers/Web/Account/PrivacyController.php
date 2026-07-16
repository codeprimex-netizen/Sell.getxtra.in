<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Application\Privacy\ConsentService;
use App\Application\Privacy\DataPrivacyService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Self-service privacy centre (Req 14.8): manage consent, request a data
 * export, request account erasure, and download a completed export.
 */
final class PrivacyController extends Controller
{
    public function __construct(
        private ConsentService $consents,
        private DataPrivacyService $privacy,
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);

        $granted = [];
        foreach (ConsentService::types() as $type) {
            $granted[$type] = $this->consents->has($userId, $type);
        }

        return $this->view($request, 'account.privacy', [
            'consent_types' => ConsentService::types(),
            'granted'       => $granted,
            'requests'      => $this->privacy->requestsFor($userId),
        ]);
    }

    public function updateConsent(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $submitted = (array) ($request->input('consents', []) ?: []);

        foreach (ConsentService::types() as $type) {
            $this->consents->apply($userId, $type, in_array($type, $submitted, true), $request->ip());
        }

        $this->flash($request, 'success', 'Your consent preferences were saved.');
        return $this->redirect('/account/privacy');
    }

    public function requestExport(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $this->privacy->requestExport($userId);
        $this->flash($request, 'success', 'Your data export is being prepared. Check back shortly to download it.');
        return $this->redirect('/account/privacy');
    }

    public function requestErasure(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $this->privacy->requestErasure($userId);
        $this->flash($request, 'success', 'Your account erasure request has been received and will be processed.');
        return $this->redirect('/account/privacy');
    }

    public function download(Request $request, string $token): Response
    {
        $userId = (int) $this->currentUserId($request);
        $json = $this->privacy->getExportByToken($token, $userId);

        if ($json === null) {
            return $this->view($request, 'account.privacy', [
                'consent_types' => ConsentService::types(),
                'granted'       => [],
                'requests'      => $this->privacy->requestsFor($userId),
                'download_error' => 'That export is not available. It may still be processing or has expired.',
            ], 404);
        }

        return (new Response($json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="my-data-export.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }
}
