<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\I18n\LocaleFormatter;
use App\Infrastructure\I18n\Translator;
use Closure;

/**
 * Resolves the request locale (Req 20.4) from, in order: an explicit ?lang
 * query, the locale cookie, the authenticated user's preference, then the
 * Accept-Language header — validated against the supported set with a default
 * fallback. Sets it on the translator/formatter and exposes it to views.
 */
final class Localize implements MiddlewareInterface
{
    public function __construct(
        private Translator $translator,
        private LocaleFormatter $formatter,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $locale = $this->resolve($request);
        $this->translator->setLocale($locale);
        $this->formatter->setLocale($locale);

        return $next($request->withAttribute('locale', $locale));
    }

    private function resolve(Request $request): string
    {
        $default = (string) Config::get('app.locale', 'en');

        $query = (string) ($request->query('lang') ?? '');
        if ($query !== '' && $this->translator->isSupported($query)) {
            return $query;
        }

        $user = $request->attribute('auth_user');
        if (is_array($user) && isset($user['locale']) && $this->translator->isSupported((string) $user['locale'])) {
            return (string) $user['locale'];
        }

        foreach ($this->parseAcceptLanguage($request->header('Accept-Language')) as $candidate) {
            if ($this->translator->isSupported($candidate)) {
                return $candidate;
            }
        }

        return $default;
    }

    /** @return array<int,string> primary subtags in quality order */
    private function parseAcceptLanguage(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }
        $langs = [];
        foreach (explode(',', $header) as $part) {
            $code = trim(explode(';', $part)[0]);
            $primary = strtolower(explode('-', $code)[0]);
            if ($primary !== '') {
                $langs[] = $primary;
            }
        }
        return $langs;
    }
}
