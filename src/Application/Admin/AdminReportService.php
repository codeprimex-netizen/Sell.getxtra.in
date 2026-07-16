<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Admin\ReportRepositoryInterface;
use App\Domain\Support\DisputeRepositoryInterface;

/**
 * Assembles the admin operations dashboard (Req 12.5).
 */
final class AdminReportService
{
    public function __construct(
        private ReportRepositoryInterface $reports,
        private DisputeRepositoryInterface $disputes,
    ) {
    }

    /** @return array{overview: array<string,int|float>, top_sellers: array<int,array<string,mixed>>, open_disputes: int} */
    public function dashboard(): array
    {
        return [
            'overview'      => $this->reports->overview(),
            'top_sellers'   => $this->reports->topSellers(5),
            'open_disputes' => $this->disputes->openCount(),
        ];
    }
}
