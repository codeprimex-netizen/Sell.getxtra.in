<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Minimal PHP-template view renderer with layout support. Templates live in
 * resources/views and receive escaped data; the shared layout wraps rendered
 * content in the site chrome. Output is always escaped in templates via e().
 */
final class View
{
    private static string $viewPath = '';

    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
    }

    /**
     * Render a template, optionally wrapped in a layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($template, $data);

        if ($layout !== null) {
            return self::renderFile($layout, array_merge($data, ['content' => $content]));
        }

        return $content;
    }

    /** @param array<string, mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        $file = self::$viewPath . '/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View [{$template}] not found at {$file}.");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
