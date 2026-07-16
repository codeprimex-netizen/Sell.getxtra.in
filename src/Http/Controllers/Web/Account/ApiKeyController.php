<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Application\Api\ApiKeyService;
use App\Domain\Api\ApiScope;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Self-service API key management for developers (Req 19.2). The plaintext
 * token is surfaced exactly once, immediately after creation, via a flash.
 */
final class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $keys)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);

        return $this->view($request, 'account.api-keys', [
            'keys'        => $this->keys->listForUser($userId),
            'all_scopes'  => ApiScope::all(),
            'new_token'   => $this->session($request)?->getFlash('new_api_token'),
        ]);
    }

    public function store(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $name = (string) ($request->input('name') ?? '');
        $scopes = $request->input('scopes', []);
        $scopes = is_array($scopes) ? $scopes : [];

        $result = $this->keys->generate($userId, $name, $scopes);

        $this->flash($request, 'new_api_token', $result['token']);
        $this->flash($request, 'success', 'API key created. Copy your token now — it will not be shown again.');

        return $this->redirect('/account/api-keys');
    }

    public function revoke(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $id = (int) $request->input('id', 0);

        if ($id > 0 && $this->keys->revoke($id, $userId)) {
            $this->flash($request, 'success', 'API key revoked.');
        }

        return $this->redirect('/account/api-keys');
    }
}
