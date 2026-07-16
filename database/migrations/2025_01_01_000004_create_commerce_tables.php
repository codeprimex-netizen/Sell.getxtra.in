<?php

declare(strict_types=1);

/**
 * Migration: commerce tables (Req 8/9/10). Realizes DESIGN.md §5.3 —
 * carts, coupons, orders, payments, webhook idempotency, entitlements, a
 * double-entry ledger, and refunds.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS carts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NULL,
      session_key VARCHAR(64) NULL,
      currency CHAR(3) NOT NULL DEFAULT 'INR',
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cart_user (user_id),
      INDEX idx_cart_session (session_key),
      CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS cart_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      cart_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      license_tier_id BIGINT UNSIGNED NULL,
      unit_price DECIMAL(12,2) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cart_product (cart_id, product_id),
      CONSTRAINT fk_ci_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
      CONSTRAINT fk_ci_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS coupons (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(40) NOT NULL UNIQUE,
      type ENUM('percent','fixed') NOT NULL,
      value DECIMAL(12,2) NOT NULL,
      scope ENUM('all','category','product','seller') NOT NULL DEFAULT 'all',
      scope_ref BIGINT UNSIGNED NULL,
      min_order DECIMAL(12,2) NULL,
      max_uses INT UNSIGNED NULL,
      used_count INT UNSIGNED NOT NULL DEFAULT 0,
      per_user_limit INT UNSIGNED NULL,
      starts_at DATETIME NULL,
      expires_at DATETIME NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS orders (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      buyer_id BIGINT UNSIGNED NOT NULL,
      order_number VARCHAR(40) NOT NULL UNIQUE,
      currency CHAR(3) NOT NULL DEFAULT 'INR',
      subtotal DECIMAL(12,2) NOT NULL,
      discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      total DECIMAL(12,2) NOT NULL,
      coupon_id BIGINT UNSIGNED NULL,
      status ENUM('pending','paid','failed','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
      idempotency_key VARCHAR(64) NULL UNIQUE,
      invoice_key VARCHAR(300) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_order_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_order_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
      INDEX idx_order_status (status),
      INDEX idx_order_buyer (buyer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS order_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      seller_id BIGINT UNSIGNED NOT NULL,
      license_tier_id BIGINT UNSIGNED NULL,
      title_snapshot VARCHAR(200) NOT NULL,
      unit_price DECIMAL(12,2) NOT NULL,
      commission DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      seller_earning DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
      CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products(id),
      CONSTRAINT fk_oi_seller FOREIGN KEY (seller_id) REFERENCES users(id),
      INDEX idx_oi_seller (seller_id),
      INDEX idx_oi_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS payments (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      gateway VARCHAR(30) NOT NULL,
      gateway_ref VARCHAR(120) NULL,
      amount DECIMAL(12,2) NOT NULL,
      currency CHAR(3) NOT NULL DEFAULT 'INR',
      status ENUM('created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'created',
      raw_payload JSON NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_pay_ref (gateway, gateway_ref),
      CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS webhook_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      source VARCHAR(40) NOT NULL,
      event_id VARCHAR(160) NOT NULL,
      payload JSON NOT NULL,
      processed_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_wh (source, event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS entitlements (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_item_id BIGINT UNSIGNED NOT NULL,
      buyer_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      license_key VARCHAR(80) NOT NULL UNIQUE,
      status ENUM('active','revoked') NOT NULL DEFAULT 'active',
      download_count INT UNSIGNED NOT NULL DEFAULT 0,
      max_downloads INT UNSIGNED NULL,
      expires_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_ent_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
      CONSTRAINT fk_ent_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ent_product FOREIGN KEY (product_id) REFERENCES products(id),
      INDEX idx_ent_buyer (buyer_id),
      INDEX idx_ent_buyer_product (buyer_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS ledger_accounts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_type ENUM('platform','seller') NOT NULL,
      owner_id BIGINT UNSIGNED NULL,
      currency CHAR(3) NOT NULL DEFAULT 'INR',
      balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
      pending_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
      UNIQUE KEY uq_acct (owner_type, owner_id, currency)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS ledger_entries (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      account_id BIGINT UNSIGNED NOT NULL,
      ref_type VARCHAR(30) NOT NULL,
      ref_id BIGINT UNSIGNED NULL,
      direction ENUM('credit','debit') NOT NULL,
      bucket ENUM('cleared','pending') NOT NULL DEFAULT 'cleared',
      amount DECIMAL(14,2) NOT NULL,
      balance_after DECIMAL(14,2) NOT NULL,
      memo VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_le_acct FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
      INDEX idx_le_acct (account_id),
      INDEX idx_le_ref (ref_type, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS refunds (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      amount DECIMAL(12,2) NOT NULL,
      reason VARCHAR(255) NULL,
      status ENUM('requested','approved','processed','rejected') NOT NULL DEFAULT 'requested',
      gateway_ref VARCHAR(120) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      processed_at DATETIME NULL,
      CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
      INDEX idx_refund_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS refunds;
    DROP TABLE IF EXISTS ledger_entries;
    DROP TABLE IF EXISTS ledger_accounts;
    DROP TABLE IF EXISTS entitlements;
    DROP TABLE IF EXISTS webhook_events;
    DROP TABLE IF EXISTS payments;
    DROP TABLE IF EXISTS order_items;
    DROP TABLE IF EXISTS orders;
    DROP TABLE IF EXISTS coupons;
    DROP TABLE IF EXISTS cart_items;
    DROP TABLE IF EXISTS carts;
    SQL,
];
