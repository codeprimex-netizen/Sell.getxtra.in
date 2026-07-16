<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Queue\JobHandler;
use RuntimeException;

/**
 * Delivers a signed outbound webhook (Req 19.4). Payload: url, event, data,
 * secret. The body is HMAC-signed; non-2xx responses raise so the worker
 * retries with backoff.
 */
final class DispatchWebhookHandler implements JobHandler
{
    public function __construct(private ?Logger $logger = null)
    {
    }

    public function handle(array $payload): void
    {
        $url = (string) ($payload['url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $body = json_encode([
            'event' => (string) ($payload['event'] ?? 'event'),
            'data'  => $payload['data'] ?? [],
            'sent_at' => time(),
        ]) ?: '{}';

        $signature = hash_hmac('sha256', $body, (string) ($payload['secret'] ?? ''));

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize webhook request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Getxtra-Signature: ' . $signature,
                'X-Getxtra-Event: ' . (string) ($payload['event'] ?? 'event'),
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Webhook delivery failed with status ' . $status);
        }

        $this->logger?->info('Webhook delivered', ['event' => $payload['event'] ?? null, 'status' => $status]);
    }
}
