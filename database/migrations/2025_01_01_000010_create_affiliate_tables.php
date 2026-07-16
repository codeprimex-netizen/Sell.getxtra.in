<?php

declare(strict_types=1);

/**
 * Migration: affiliate/referral program (Req 20.2).
 *
 * - affiliates: a user enrolled in the program, with a unique referral code
 *   and rolling funnel counters (clicks/signups/conversions).
 * - referrals: an attribution record — a click that may progress to a signup
 *   and then to a converted (commissioned) order. First-purchase attribution.
 *
 * Commission itself is posted to the double-entry ledger (account type
 * 'affiliate'), reusing the same pending→cleared lifecycle as seller earnings.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS affiliates (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      code CHAR(10) NOT NULL,
      commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
      status ENUM('active','suspended') NOT NULL DEFAULT 'active',
      clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
      signups BIGINT UNSIGNED NOT NULL DEFAULT 0,
      conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_affiliate_user (user_id),
      UNIQUE KEY uq_affiliate_code (code),
      CONSTRAINT fk_affiliate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS referrals (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      affiliate_id BIGINT UNSIGNED NOT NULL,
      visitor_token CHAR(32) NOT NULL,
      referred_user_id BIGINT UNSIGNED NULL,
      order_id BIGINT UNSIGNED NULL,
      commission DECIMAL(12,2) NULL,
      currency CHAR(3) NULL,
      status ENUM('clicked','signed_up','converted','rejected') NOT NULL DEFAULT 'clicked',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      converted_at DATETIME NULL,
      UNIQUE KEY uq_referral_visitor (visitor_token),
      INDEX idx_referral_affiliate (affiliate_id),
      INDEX idx_referral_user (referred_user_id),
      INDEX idx_referral_status (status),
      CONSTRAINT fk_referral_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
      CONSTRAINT fk_referral_user FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS referrals;
    DROP TABLE IF EXISTS affiliates;
    SQL,
];
