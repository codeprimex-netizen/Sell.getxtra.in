<?php

declare(strict_types=1);

namespace App\Application\Seo;

/**
 * Per-request SEO head builder.
 *
 * Assembles a complete, standards-current (2025/2026) document head:
 *   - title + meta description + keywords
 *   - modern robots directives (max-image-preview:large, max-snippet, …)
 *   - rel=canonical + rel=alternate hreflang (per supported locale + x-default)
 *   - Open Graph + Twitter Card for rich social/link-unfurl previews
 *   - theme-color, referrer, and CDN preconnect hints
 *   - a single JSON-LD `@graph` (Organization + WebSite/SearchAction + any
 *     per-page entities such as Product and BreadcrumbList)
 *
 * All dynamic values are HTML-escaped; the JSON-LD script carries the CSP
 * nonce so it survives the nonce-based Content-Security-Policy.
 */
final class Seo
{
    private string $title;
    private string $description = '';
    private string $canonical;
    private string $type = 'website';
    private ?string $image = null;
    private bool $index = true;
    private string $keywords = '';
    private string $nonce = '';

    /** @var list<array<string, mixed>> extra schema.org graph nodes */
    private array $graph = [];

    /** @var list<array{name:string, url:string}> */
    private array $breadcrumbs = [];

    /** BCP-47 locale codes for og/hreflang, keyed by app locale. */
    private const LOCALE_MAP = [
        'en' => 'en_US',
        'hi' => 'hi_IN',
    ];

    /**
     * @param list<string> $supportedLocales
     * @param list<string> $sameAs
     */
    public function __construct(
        private string $siteName,
        private string $baseUrl,
        private string $locale = 'en',
        private array $supportedLocales = ['en'],
        private ?string $logoUrl = null,
        private array $sameAs = [],
        private ?string $cdnUrl = null,
        private ?string $twitterHandle = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->title = $siteName;
        $this->canonical = $this->baseUrl . '/';
    }

    public function title(string $title): self
    {
        $title = trim($title);
        if ($title !== '') {
            $this->title = $title;
        }
        return $this;
    }

    public function description(string $description): self
    {
        // Search engines truncate ~155-160 chars; keep it tidy.
        $this->description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
        return $this;
    }

    public function canonical(string $url): self
    {
        if ($url !== '') {
            $this->canonical = $url;
        }
        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function image(?string $url): self
    {
        if ($url !== null && $url !== '') {
            $this->image = $url;
        }
        return $this;
    }

    public function keywords(string $keywords): self
    {
        $this->keywords = trim($keywords);
        return $this;
    }

    public function noindex(bool $on = true): self
    {
        $this->index = !$on;
        return $this;
    }

    public function nonce(string $nonce): self
    {
        $this->nonce = $nonce;
        return $this;
    }

    /**
     * @param list<array{name:string, url:string}> $items
     */
    public function breadcrumbs(array $items): self
    {
        $this->breadcrumbs = $items;
        return $this;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function addSchema(array $node): self
    {
        if ($node !== []) {
            $this->graph[] = $node;
        }
        return $this;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function ogLocale(string $appLocale): string
    {
        return self::LOCALE_MAP[$appLocale] ?? $appLocale;
    }

    private function withLang(string $url, string $lang): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'lang=' . rawurlencode($lang);
    }

    /** Render the meta/link tags block. */
    public function metaHtml(): string
    {
        $lines = [];

        if ($this->description !== '') {
            $lines[] = '<meta name="description" content="' . $this->e($this->description) . '">';
        }
        if ($this->keywords !== '') {
            $lines[] = '<meta name="keywords" content="' . $this->e($this->keywords) . '">';
        }

        // Modern robots directives (rich previews when indexable).
        $robots = $this->index
            ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
            : 'noindex, nofollow';
        $lines[] = '<meta name="robots" content="' . $robots . '">';
        $lines[] = '<meta name="referrer" content="strict-origin-when-cross-origin">';
        $lines[] = '<meta name="theme-color" content="#0f172a">';

        // Canonical + hreflang alternates.
        $lines[] = '<link rel="canonical" href="' . $this->e($this->canonical) . '">';
        if ($this->index && count($this->supportedLocales) > 1) {
            foreach ($this->supportedLocales as $loc) {
                $lines[] = '<link rel="alternate" hreflang="' . $this->e($loc)
                    . '" href="' . $this->e($this->withLang($this->canonical, $loc)) . '">';
            }
            $lines[] = '<link rel="alternate" hreflang="x-default" href="' . $this->e($this->canonical) . '">';
        }

        // Performance hints.
        if ($this->cdnUrl !== null && $this->cdnUrl !== '') {
            $lines[] = '<link rel="preconnect" href="' . $this->e(rtrim($this->cdnUrl, '/')) . '" crossorigin>';
        }

        // Open Graph.
        $lines[] = '<meta property="og:site_name" content="' . $this->e($this->siteName) . '">';
        $lines[] = '<meta property="og:type" content="' . $this->e($this->type) . '">';
        $lines[] = '<meta property="og:title" content="' . $this->e($this->title) . '">';
        if ($this->description !== '') {
            $lines[] = '<meta property="og:description" content="' . $this->e($this->description) . '">';
        }
        $lines[] = '<meta property="og:url" content="' . $this->e($this->canonical) . '">';
        $lines[] = '<meta property="og:locale" content="' . $this->e($this->ogLocale($this->locale)) . '">';
        foreach ($this->supportedLocales as $loc) {
            if ($loc !== $this->locale) {
                $lines[] = '<meta property="og:locale:alternate" content="' . $this->e($this->ogLocale($loc)) . '">';
            }
        }
        if ($this->image !== null) {
            $lines[] = '<meta property="og:image" content="' . $this->e($this->image) . '">';
        }

        // Twitter Card.
        $lines[] = '<meta name="twitter:card" content="' . ($this->image !== null ? 'summary_large_image' : 'summary') . '">';
        $lines[] = '<meta name="twitter:title" content="' . $this->e($this->title) . '">';
        if ($this->description !== '') {
            $lines[] = '<meta name="twitter:description" content="' . $this->e($this->description) . '">';
        }
        if ($this->image !== null) {
            $lines[] = '<meta name="twitter:image" content="' . $this->e($this->image) . '">';
        }
        if ($this->twitterHandle !== null && $this->twitterHandle !== '') {
            $handle = '@' . ltrim($this->twitterHandle, '@');
            $lines[] = '<meta name="twitter:site" content="' . $this->e($handle) . '">';
        }

        return implode("\n  ", $lines);
    }

    /** Render the JSON-LD `@graph` script (nonce-aware for CSP). */
    public function jsonLdHtml(): string
    {
        $graph = [
            StructuredData::organization($this->siteName, $this->baseUrl, $this->logoUrl, $this->sameAs),
            StructuredData::website($this->siteName, $this->baseUrl, $this->locale),
        ];

        if ($this->breadcrumbs !== []) {
            $graph[] = StructuredData::breadcrumbs($this->breadcrumbs);
        }

        foreach ($this->graph as $node) {
            $graph[] = $node;
        }

        $doc = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        $json = (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonceAttr = $this->nonce !== '' ? ' nonce="' . $this->e($this->nonce) . '"' : '';

        return '<script type="application/ld+json"' . $nonceAttr . '>' . $json . '</script>';
    }

    /** Full head contribution: meta/link tags + JSON-LD. */
    public function head(): string
    {
        return $this->metaHtml() . "\n  " . $this->jsonLdHtml();
    }
}
