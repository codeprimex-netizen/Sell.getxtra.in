<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Seo\Seo;
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
            'app_name'      => (string) Config::get('app.name', 'Code.getxtra.in'),
            'auth_user'     => $request->attribute('auth_user'),
            'csrf_token'    => $session?->csrfToken() ?? '',
            'csp_nonce'     => (string) ($request->attribute('csp_nonce') ?? ''),
            'locale'        => (string) ($request->attribute('locale') ?? 'en'),
            'flash_success' => $session?->getFlash('success'),
            'flash_error'   => $session?->getFlash('error'),
            'errors'        => $data['errors'] ?? [],
            'old'           => $data['old'] ?? [],
            'seo_head'      => $this->buildSeo($request, $data)->head(),
        ];

        return Response::html(View::render($template, array_merge($shared, $data)), $status);
    }

    /**
     * Compose the advanced SEO head for the current page from Config defaults,
     * the request (locale + path), and per-view overrides in $data:
     *   title, meta_description, canonical, og_image, seo_type, seo_keywords,
     *   seo_noindex, breadcrumbs (list of [name,url]), schema (list of nodes).
     *
     * @param array<string, mixed> $data
     */
    protected function buildSeo(Request $request, array $data): Seo
    {
        $baseUrl = (string) Config::get('app.url', '');
        $locale = (string) ($request->attribute('locale') ?? Config::get('app.locale', 'en'));

        /** @var list<string> $supported */
        $supported = array_values(array_map('strval', (array) Config::get('app.supported_locales', ['en'])));

        $logo = (string) Config::get('seo.logo', '');

        $seo = new Seo(
            siteName: (string) Config::get('app.name', 'Code.getxtra.in'),
            baseUrl: $baseUrl,
            locale: $locale,
            supportedLocales: $supported,
            logoUrl: $logo !== '' ? $logo : null,
            sameAs: array_values((array) Config::get('seo.same_as', [])),
            cdnUrl: ((string) Config::get('storage.cdn_url', '')) ?: null,
            twitterHandle: ((string) Config::get('seo.twitter', '')) ?: null,
        );

        $seo->canonical((string) ($data['canonical'] ?? (rtrim($baseUrl, '/') . $request->path())))
            ->nonce((string) ($request->attribute('csp_nonce') ?? ''));

        if (!empty($data['title'])) {
            $seo->title((string) $data['title']);
        }
        if (!empty($data['meta_description'])) {
            $seo->description((string) $data['meta_description']);
        }
        $seo->image((string) ($data['og_image'] ?? $logo));
        if (!empty($data['seo_type'])) {
            $seo->type((string) $data['seo_type']);
        }
        if (!empty($data['seo_keywords'])) {
            $seo->keywords((string) $data['seo_keywords']);
        }
        if (!empty($data['seo_noindex'])) {
            $seo->noindex(true);
        }
        if (!empty($data['breadcrumbs']) && is_array($data['breadcrumbs'])) {
            /** @var list<array{name:string, url:string}> $crumbs */
            $crumbs = $data['breadcrumbs'];
            $seo->breadcrumbs($crumbs);
        }
        if (!empty($data['schema']) && is_array($data['schema'])) {
            foreach ($data['schema'] as $node) {
                if (is_array($node)) {
                    $seo->addSchema($node);
                }
            }
        }

        return $seo;
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
