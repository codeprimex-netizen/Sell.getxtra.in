<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Api\ApiKeyService;
use App\Http\Request;
use App\Http\Response;

/**
 * Identity endpoint (Req 19.1/19.2). Echoes the account and scopes behind the
 * presented API token — useful for integrators to verify credentials.
 */
final class MeController extends ApiController
{
    public function __construct(private ApiKeyService $keys)
    {
    }

    public function show(Request $request): Response
    {
        $user = $this->currentUser($request) ?? [];
        $key = $this->apiKey($request) ?? [];

        return $this->ok($request, [
            'user' => [
                'id'    => (int) ($user['id'] ?? 0),
                'name'  => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
            ],
            'api_key' => [
                'id'     => (int) ($key['id'] ?? 0),
                'name'   => (string) ($key['name'] ?? ''),
                'prefix' => (string) ($key['prefix'] ?? ''),
                'scopes' => $this->keys->scopesOf($key),
            ],
        ]);
    }
}
