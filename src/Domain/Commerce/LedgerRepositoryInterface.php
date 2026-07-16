<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Double-entry ledger persistence (Req 9.5). Accounts hold a cleared balance
 * and a pending balance (seller earnings pending the refund window). Entries
 * are immutable and reference the originating order/refund.
 */
interface LedgerRepositoryInterface
{
    /** Get or create an account, returning its id. */
    public function account(string $ownerType, ?int $ownerId, string $currency): int;

    /**
     * Post an entry and adjust the account balance in one transaction.
     *
     * @param 'credit'|'debit'   $direction
     * @param 'cleared'|'pending' $bucket
     */
    public function post(
        int $accountId,
        string $direction,
        string $bucket,
        Money $amount,
        string $refType,
        ?int $refId,
        string $memo,
    ): void;

    /** @return array{balance: float, pending: float} */
    public function balances(int $accountId): array;

    /** @return array<int, array<string,mixed>> */
    public function entriesForRef(string $refType, int $refId): array;
}
