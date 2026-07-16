<?php

declare(strict_types=1);

namespace App\Infrastructure\I18n;

/**
 * Message translator (Req 20.4). Loads per-locale catalogs (PHP arrays) from
 * resources/lang and resolves dot-notation keys with :placeholder
 * substitution, falling back to the default locale and finally the key itself.
 */
final class Translator
{
    /** @var array<string, array<string,mixed>> loaded catalogs by locale */
    private array $catalogs = [];

    /**
     * @param array<int,string> $supported
     */
    public function __construct(
        private string $langPath,
        private string $locale = 'en',
        private string $fallback = 'en',
        private array $supported = ['en'],
    ) {
    }

    public function setLocale(string $locale): void
    {
        if ($this->isSupported($locale)) {
            $this->locale = $locale;
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported, true);
    }

    /** @return array<int,string> */
    public function supported(): array
    {
        return $this->supported;
    }

    /**
     * Translate a key. Missing keys fall back to the default locale then to the
     * key string itself, so the UI never shows a blank.
     *
     * @param array<string,string|int|float> $replace
     */
    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->locale;
        $line = $this->line($locale, $key) ?? $this->line($this->fallback, $key) ?? $key;

        foreach ($replace as $token => $value) {
            $line = str_replace(':' . $token, (string) $value, $line);
        }

        return $line;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->line($locale ?? $this->locale, $key) !== null;
    }

    private function line(string $locale, string $key): ?string
    {
        $catalog = $this->catalog($locale);
        $value = $catalog;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return is_string($value) ? $value : null;
    }

    /** @return array<string,mixed> */
    private function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $file = rtrim($this->langPath, '/') . '/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            $this->catalogs[$locale] = is_array($data) ? $data : [];
        }
        return $this->catalogs[$locale];
    }
}
