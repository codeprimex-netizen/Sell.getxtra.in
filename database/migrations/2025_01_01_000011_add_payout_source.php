<?php

declare(strict_types=1);

/**
 * Migration: distinguish payout sources (seller earnings vs affiliate
 * commission) so both can flow through the same finance rails while reserving
 * against the correct ledger balance (Req 11.3 / 20.2).
 */

return [
    'up' => <<<SQL
    ALTER TABLE payouts
      ADD COLUMN source ENUM('seller','affiliate') NOT NULL DEFAULT 'seller' AFTER seller_id,
      ADD INDEX idx_payout_source (source);
    SQL,

    'down' => <<<SQL
    ALTER TABLE payouts DROP INDEX idx_payout_source, DROP COLUMN source;
    SQL,
];
