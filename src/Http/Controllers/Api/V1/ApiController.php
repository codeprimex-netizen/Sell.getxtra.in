<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Base class for versioned API controllers (Req 19.1). Centralizes the JSON
 * envelope so every endpoint returns a consistent shape:
 *
 *   success -> { "data": ..., "meta": { request_id, ... } }
 *   error   -> { "error": { code, message, ... } }
 */
abstract class ApiController extends Controller
{
    /**
     * @param mixed $data
     * @param array<string,mixed> $meta
     */
    protected function ok(Request $request, mixed $data, array $meta = [], int $status = 200): Response
    {
        return Response::json([
            'data' => $data,
            'meta' => array_merge(['request_id' => $request->attribute('request_id')], $meta),
        ], $status);
    }

    /**
     * Envelope for a paginated collection.
     *
     * @param array<int, mixed> $items
     */
    protected function paginated(Request $request, array $items, int $page, int $perPage): Response
    {
        return $this->ok($request, $items, [
            'page'     => $page,
            'per_page' => $perPage,
            'count'    => count($items),
        ]);
    }

    /** @param array<string,mixed> $extra */
    protected function error(string $code, string $message, int $status = 400, array $extra = []): Response
    {
        return Response::apiError($code, $message, $status, $extra);
    }

    protected function notFound(string $message = 'Resource not found.'): Response
    {
        return $this->error('not_found', $message, 404);
    }

    /** Clamp a page number from the query string. */
    protected function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    /** Clamp a per-page size from the query string. */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min((int) $request->query('per_page', $default), $max));
    }

    /** The API key row attached by the apikey middleware (if any). @return array<string,mixed>|null */
    protected function apiKey(Request $request): ?array
    {
        $key = $request->attribute('api_key');
        return is_array($key) ? $key : null;
    }
}
