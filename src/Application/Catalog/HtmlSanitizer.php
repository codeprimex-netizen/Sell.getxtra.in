<?php

declare(strict_types=1);

namespace App\Application\Catalog;

/**
 * Conservative HTML sanitizer for seller-authored rich text (Req 4.1 / 14.2).
 *
 * Strips scripts/styles and event handlers, neutralizes dangerous URL
 * schemes, and keeps only a small allowlist of formatting tags. This is a
 * defense-in-depth measure; output is still escaped where appropriate.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><code><pre><blockquote>';

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Remove script/style blocks entirely (including content).
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? '';
        // Remove any lingering opening tags of those types.
        $html = preg_replace('#</?(script|style|iframe|object|embed)[^>]*>#i', '', $html) ?? '';

        // Strip on* event-handler attributes (onclick, onerror, ...).
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';

        // Neutralize javascript:/data:/vbscript: URLs in href/src.
        $html = preg_replace(
            '/(href|src)\s*=\s*("|\')\s*(javascript|data|vbscript):[^"\']*("|\')/i',
            '$1=$2#$4',
            $html
        ) ?? '';

        // Keep only the allowlisted tags.
        $html = strip_tags($html, self::ALLOWED_TAGS);

        return trim($html);
    }
}
