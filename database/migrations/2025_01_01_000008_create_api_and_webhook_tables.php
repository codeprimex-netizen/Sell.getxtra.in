<?php

declare(strict_types=1);

/**
 * Migration: public API access keys and outbound webhook subscriptions
 * (Req 19.2 / 19.4). Realizes DESIGN.md §5.5.
 *
 * API tokens are never stored in plaintext — only a SHA-256 hash of the
 * full token plus a public, indexable prefix used to locate the row before
 * a constant-time hash comparison.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS api_keys (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(120) NOT NULL,
      prefix CHAR(12) NOT NULL,
      token_hash CHAR(64) NOT NULL,
      scopes VARCHAR(500) NOT NULL DEFAULT '',
      rate_limit INT UNSIGNED NOT NULL DEFAULT 120,
      last_used_at DATETIME NULL,
      expires_at DATETIME NULL,
      revoked_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_apikey_prefix (prefix),
      INDEX idx_apikey_user (user_id),
      CONSTRAINT fk_apikey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS webhook_subscriptions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      url VARCHAR(500) NOT NULL,
      secret CHAR(64) NOT NULL,
      events VARCHAR(500) NOT NULL DEFAULT '*',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      last_delivered_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_websub_user (user_id),
      INDEX idx_websub_active (is_active),
      CONSTRAINT fk_websub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS webhook_subscriptions;
    DROP TABLE IF EXISTS api_keys;
    SQL,
];
