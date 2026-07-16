<?php

declare(strict_types=1);

/**
 * Migration: seller payouts + product analytics rollups (Req 11 / 6.5).
 * Realizes DESIGN.md §5.3 (payouts) and §5.4 (product_daily_stats).
 * seller_profiles already exists from the identity migration.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS payouts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      seller_id BIGINT UNSIGNED NOT NULL,
      amount DECIMAL(12,2) NOT NULL,
      currency CHAR(3) NOT NULL DEFAULT 'INR',
      method VARCHAR(40) NULL,
      status ENUM('requested','processing','paid','rejected') NOT NULL DEFAULT 'requested',
      gateway_ref VARCHAR(120) NULL,
      note VARCHAR(255) NULL,
      requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      processed_at DATETIME NULL,
      CONSTRAINT fk_payout_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_payout_seller (seller_id),
      INDEX idx_payout_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS product_daily_stats (
      product_id BIGINT UNSIGNED NOT NULL,
      day DATE NOT NULL,
      views BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sales BIGINT UNSIGNED NOT NULL DEFAULT 0,
      revenue DECIMAL(14,2) NOT NULL DEFAULT 0.00,
      PRIMARY KEY (product_id, day),
      CONSTRAINT fk_stats_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS product_daily_stats;
    DROP TABLE IF EXISTS payouts;
    SQL,
];
