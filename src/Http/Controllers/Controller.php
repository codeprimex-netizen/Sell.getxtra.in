<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use App\Http\View;

/**
 * Base controller with view rendering and session/redirect conveniences.
 * Injects common view data (app name, current user, CSRF token, flash msgs)
 * so templates stay lean.
 */
abstract class Controller
{
    /**
     * Render a view into an HTML response, merging in shared view data.
     *
     * @param array<string, mixed> $data
     */
    protected function view(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        $session = $this->session($request);

        $shared = [
            'app_name'      => (string) Config::get('app.name', 'Sell.getxtra.in'),
            'auth_user'     => $request->attribute('auth_user'),
            'csrf_token'    => $session?->csrfToken() ?? '',
            'csp_nonce'     => (string) ($request->attribute('csp_nonce') ?? ''),
            'flash_success' => $session?->getFlash('success'),
            'flash_error'   => $session?->getFlash('error'),
            'errors'        => $data['errors'] ?? [],
            'old'           => $data['old'] ?? [],
        ];

        return Response::html(View::render($template, array_merge($shared, $data)), $status);
    }

    protected function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }

    protected function session(Request $request): ?Session
    {
        $session = $request->attribute('session');
        return $session instanceof Session ? $session : null;
    }

    /** @return array<string, mixed>|null */
    protected function currentUser(Request $request): ?array
    {
        $user = $request->attribute('auth_user');
        return is_array($user) ? $user : null;
    }

    protected function currentUserId(Request $request): ?int
    {
        $id = $request->attribute('auth_user_id');
        return is_int($id) ? $id : null;
    }

    /**
     * Flash a message for the next request.
     */
    protected function flash(Request $request, string $key, string $message): void
    {
        $this->session($request)?->flash($key, $message);
    }
}
