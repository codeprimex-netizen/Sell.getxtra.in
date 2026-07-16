<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Application\Privacy\DataPrivacyService;
use App\Infrastructure\Queue\JobHandler;

/**
 * Queue handler that fulfills a data-export request off the request path
 * (Req 14.8). Job name: privacy.export.
 */
final class ProcessDataExportHandler implements JobHandler
{
    public function __construct(private DataPrivacyService $privacy)
    {
    }

    public function handle(array $payload): void
    {
        $requestId = (int) ($payload['request_id'] ?? 0);
        if ($requestId > 0) {
            $this->privacy->fulfillExport($requestId);
        }
    }
}
