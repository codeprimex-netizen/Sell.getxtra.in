<?php

declare(strict_types=1);

namespace App\Http;

/**
 * HTTP response value object with a fluent API and JSON/HTML helpers.
 */
final class Response
{
    /** @var (callable():void)|null Streamer for large/binary responses. */
    private $streamer = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    /** Attach a callback that echoes the body directly (used for file downloads). */
    public function setStreamer(callable $streamer): void
    {
        $this->streamer = $streamer;
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new self($body, $status, array_merge(
            ['Content-Type' => 'application/json; charset=utf-8'],
            $headers,
        ));
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string, mixed> $data */
    public static function apiError(string $code, string $message, int $status = 400, array $data = []): self
    {
        return self::json([
            'error' => array_merge(['code' => $code, 'message' => $message], $data),
        ], $status);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}", true);
            }
        }

        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }

        echo $this->body;
    }
}
