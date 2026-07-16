<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Download\LicenseService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Public license verification API (Req 10.3 / 19.1). Confirms whether a
 * license key is valid and active for a product.
 */
final class LicenseController extends Controller
{
    public function __construct(private LicenseService $licenses)
    {
    }

    public function verify(Request $request): Response
    {
        $key = (string) ($request->input('key') ?? $request->query('key', ''));
        $result = $this->licenses->verify($key);

        return Response::json([
            'data' => $result,
            'meta' => ['request_id' => $request->attribute('request_id')],
        ], $result['valid'] ? 200 : 404);
    }
}
