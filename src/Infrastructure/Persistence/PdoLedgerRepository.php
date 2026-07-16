<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\Money;
use PDO;

/**
 * PDO-backed double-entry ledger (Req 9.5). Posting an entry updates the
 * owning account's cleared or pending balance and records an immutable line
 * with the running balance — all inside one transaction.
 */
final class PdoLedgerRepository extends Repository implements LedgerRepositoryInterface
{
    protected string $table = 'ledger_accounts';

    public function account(string $ownerType, ?int $ownerId, string $currency): int
    {
        $read = $this->connection->read();
        $find = $read->prepare(
            "SELECT id FROM {$this->table}
             WHERE owner_type = :t AND owner_id <=> :o AND currency = :c LIMIT 1"
        );
        $find->execute(['t' => $ownerType, 'o' => $ownerId, 'c' => $currency]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $write = $this->connection->write();
        $ins = $write->prepare(
            "INSERT INTO {$this->table} (owner_type, owner_id, currency) VALUES (:t, :o, :c)"
        );
        $ins->execute(['t' => $ownerType, 'o' => $ownerId, 'c' => $currency]);
        return (int) $write->lastInsertId();
    }

    public function post(
        int $accountId,
        string $direction,
        string $bucket,
        Money $amount,
        string $refType,
        ?int $refId,
        string $memo,
    ): void {
        $this->connection->transaction(function (PDO $pdo) use ($accountId, $direction, $bucket, $amount, $refType, $refId, $memo): void {
            // Lock the account row for a consistent running balance.
            $sel = $pdo->prepare(
                "SELECT balance, pending_balance FROM {$this->table} WHERE id = :id FOR UPDATE"
            );
            $sel->execute(['id' => $accountId]);
            $acct = $sel->fetch();
            if ($acct === false) {
                throw new \RuntimeException("Ledger account {$accountId} not found.");
            }

            $delta = $amount->decimal() * ($direction === 'credit' ? 1 : -1);
            $column = $bucket === 'pending' ? 'pending_balance' : 'balance';
            $newBalance = round((float) $acct[$column] + $delta, 2);

            $upd = $pdo->prepare(
                "UPDATE {$this->table} SET {$column} = :bal WHERE id = :id"
            );
            $upd->execute(['bal' => $newBalance, 'id' => $accountId]);

            $entry = $pdo->prepare(
                'INSERT INTO ledger_entries
                    (account_id, ref_type, ref_id, direction, bucket, amount, balance_after, memo)
                 VALUES (:acct, :rt, :rid, :dir, :bucket, :amt, :bal, :memo)'
            );
            $entry->execute([
                'acct'   => $accountId,
                'rt'     => $refType,
                'rid'    => $refId,
                'dir'    => $direction,
                'bucket' => $bucket,
                'amt'    => $amount->decimal(),
                'bal'    => $newBalance,
                'memo'   => $memo,
            ]);
        });
    }

    public function balances(int $accountId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT balance, pending_balance FROM {$this->table} WHERE id = :id"
        );
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch() ?: ['balance' => 0, 'pending_balance' => 0];

        return [
            'balance' => (float) $row['balance'],
            'pending' => (float) $row['pending_balance'],
        ];
    }

    public function entriesForRef(string $refType, int $refId): array
    {
        $stmt = $this->connection->read()->prepare(
            'SELECT * FROM ledger_entries WHERE ref_type = :rt AND ref_id = :rid ORDER BY id ASC'
        );
        $stmt->execute(['rt' => $refType, 'rid' => $refId]);
        return $stmt->fetchAll();
    }
}
