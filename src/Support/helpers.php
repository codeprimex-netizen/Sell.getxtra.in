<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Config\Config;
use App\Config\Env;

if (!function_exists('app')) {
    /**
     * Resolve a service from the container, or the container itself.
     */
    function app(?string $id = null): mixed
    {
        $container = Container::getInstance();
        return $id === null ? $container : $container->get($id);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a value for safe output (XSS defense). See Req 14.2.
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $root = dirname(__DIR__, 2);
        return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Build a fingerprinted, CDN-aware URL for a static asset (Req 16.2).
     */
    function asset(string $path): string
    {
        /** @var App\Infrastructure\Assets\AssetManager $assets */
        $assets = app(App\Infrastructure\Assets\AssetManager::class);
        return $assets->url($path);
    }
}

if (!function_exists('__')) {
    /**
     * Translate a message key for the current locale (Req 20.4).
     *
     * @param array<string,string|int|float> $replace
     */
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        /** @var App\Infrastructure\I18n\Translator $translator */
        $translator = app(App\Infrastructure\I18n\Translator::class);
        return $translator->translate($key, $replace, $locale);
    }
}

if (!function_exists('trans')) {
    /** Alias of __() for message translation (Req 20.4). @param array<string,string|int|float> $replace */
    function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return __($key, $replace, $locale);
    }
}

if (!function_exists('money')) {
    /**
     * Format a decimal amount with a currency prefix.
     */
    function money(float|string $amount, string $currency = 'INR'): string
    {
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? ($currency . ' ');
        return $symbol . number_format((float) $amount, 2);
    }
}

if (!function_exists('slugify')) {
    /**
     * Generate a URL-safe slug (SEO). See Req 4.6.
     */
    function slugify(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        return $text === '' ? 'n-a' : $text;
    }
}
