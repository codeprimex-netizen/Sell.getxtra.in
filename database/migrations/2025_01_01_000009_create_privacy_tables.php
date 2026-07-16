<?php

declare(strict_types=1);

/**
 * Migration: GDPR/DPDP compliance tables (Req 14.8).
 *
 * - user_consents: per-purpose consent state (marketing, cookies, terms) with
 *   an audit of when and from where it was last changed.
 * - data_requests: data-subject access (export) and right-to-erasure requests,
 *   with their processing lifecycle and a one-time download token for exports.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS user_consents (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      type VARCHAR(60) NOT NULL,
      granted TINYINT(1) NOT NULL DEFAULT 0,
      ip VARCHAR(45) NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_consent_user_type (user_id, type),
      CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS data_requests (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      type ENUM('export','erasure') NOT NULL,
      status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
      token CHAR(64) NULL,
      download_key VARCHAR(300) NULL,
      requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      UNIQUE KEY uq_datareq_token (token),
      INDEX idx_datareq_user (user_id),
      INDEX idx_datareq_status (status),
      CONSTRAINT fk_datareq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS data_requests;
    DROP TABLE IF EXISTS user_consents;
    SQL,
];
