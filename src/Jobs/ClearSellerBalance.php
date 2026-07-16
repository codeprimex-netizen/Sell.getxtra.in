<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Commerce\LedgerService;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Infrastructure\Queue\Job;

/**
 * Moves seller earnings from pending to cleared once the refund window has
 * elapsed (Req 11.4). Scans paid orders older than the window and clears each
 * (idempotently via LedgerService::clearEarning). Runs on a schedule in
 * Phase 9; for now it is invocable via `bin/console clear-balances`.
 */
final class ClearSellerBalance implements Job
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private LedgerService $ledger,
        private int $refundWindowDays = 14,
    ) {
    }

    public function queue(): string
    {
        return 'clearing';
    }

    public function handle(): void
    {
        $this->run();
    }

    /** @return int number of orders cleared this run */
    public function run(): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($this->refundWindowDays * 86400));
        $cleared = 0;

        foreach ($this->orders->paidOrdersBefore($cutoff, 500) as $order) {
            $items = $this->orders->items((int) $order['id']);
            if ($this->ledger->clearEarning((int) $order['id'], $items, (string) $order['currency'])) {
                $cleared++;
            }
        }

        return $cleared;
    }
}
