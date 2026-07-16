<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

/**
 * A single readiness probe for a dependency (Req 15.4).
 */
interface HealthCheck
{
    public function name(): string;

    /** @return array{healthy:bool, detail:string} */
    public function run(): array;
}
