<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * Dispatches jobs for (eventually) asynchronous processing. The driver is
 * selected by config (sync|database|redis). See Req 18.1.
 */
interface QueueInterface
{
    public function push(Job $job): void;
}
