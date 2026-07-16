<?php

declare(strict_types=1);

/**
 * Migration: disputes (Req 12.4). Realizes DESIGN.md §5.4. Links a buyer's
 * complaint to an order and tracks it through resolution/refund.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS disputes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      raised_by BIGINT UNSIGNED NOT NULL,
      reason VARCHAR(500) NOT NULL,
      status ENUM('open','under_review','resolved','refunded','rejected') NOT NULL DEFAULT 'open',
      resolution VARCHAR(500) NULL,
      assigned_to BIGINT UNSIGNED NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_disp_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
      CONSTRAINT fk_disp_user FOREIGN KEY (raised_by) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_disp_status (status),
      INDEX idx_disp_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => 'DROP TABLE IF EXISTS disputes;',
];
