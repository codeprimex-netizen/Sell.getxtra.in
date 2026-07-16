<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;

/**
 * Serves the published OpenAPI 3 specification (Req 19.3). The document lives
 * in resources/openapi.json and is kept in sync with the implementation; a
 * contract test asserts every documented path is a registered route.
 */
final class OpenApiController extends ApiController
{
    public function __construct(private string $basePath)
    {
    }

    public function spec(Request $request): Response
    {
        $path = $this->basePath . '/resources/openapi.json';
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        $doc = json_decode($raw, true);

        if (!is_array($doc)) {
            return $this->error('spec_unavailable', 'API specification is unavailable.', 500);
        }

        // Reflect the configured base URL so the spec is accurate per-environment.
        $url = (string) Config::get('app.url', 'https://www.sell.getxtra.in');
        $doc['servers'] = [['url' => $url, 'description' => (string) Config::get('app.env', 'production')]];

        return Response::json($doc);
    }
}
