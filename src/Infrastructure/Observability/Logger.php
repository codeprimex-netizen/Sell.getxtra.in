<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

/**
 * Structured logger emitting JSON lines with a correlation/request id.
 *
 * PSR-3-ish level methods without the external dependency. In production
 * these lines are shipped to a central store (ELK/Loki). See Req 15.1.
 */
final class Logger
{
    private const LEVELS = [
        'debug'     => 100,
        'info'      => 200,
        'notice'    => 250,
        'warning'   => 300,
        'error'     => 400,
        'critical'  => 500,
        'alert'     => 550,
        'emergency' => 600,
    ];

    private int $threshold;

    private string $requestId;

    public function __construct(
        private string $path = 'storage/logs/app.log',
        string $minLevel = 'debug',
        ?string $requestId = null,
    ) {
        $this->threshold = self::LEVELS[$minLevel] ?? 100;
        $this->requestId = $requestId ?? self::generateRequestId();
    }

    public static function generateRequestId(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Throwable) {
            $bytes = md5(uniqid('', true), true);
        }

        // RFC-4122-ish v4 formatting.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $weight = self::LEVELS[$level] ?? 100;
        if ($weight < $this->threshold) {
            return;
        }

        $record = [
            'timestamp'  => date('c'),
            'level'      => $level,
            'message'    => $message,
            'request_id' => $this->requestId,
            'context'    => $context,
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $dir = dirname($this->path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
