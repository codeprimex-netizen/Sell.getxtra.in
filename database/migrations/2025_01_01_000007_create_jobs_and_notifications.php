<?php

declare(strict_types=1);

/**
 * Migration: durable job queue + dead-letter, in-app notifications, and
 * notification preferences (Req 18 / 13). Realizes DESIGN.md §5.4.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS jobs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      queue VARCHAR(60) NOT NULL DEFAULT 'default',
      name VARCHAR(120) NOT NULL,
      payload JSON NOT NULL,
      attempts INT UNSIGNED NOT NULL DEFAULT 0,
      available_at DATETIME NOT NULL,
      reserved_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_jobs_reserve (queue, reserved_at, available_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS failed_jobs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      queue VARCHAR(60) NOT NULL,
      name VARCHAR(120) NOT NULL,
      payload JSON NOT NULL,
      attempts INT UNSIGNED NOT NULL DEFAULT 0,
      error TEXT NULL,
      failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS notifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      type VARCHAR(60) NOT NULL,
      data JSON NOT NULL,
      read_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_notif_user (user_id, read_at),
      CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS notification_preferences (
      user_id BIGINT UNSIGNED PRIMARY KEY,
      email_enabled TINYINT(1) NOT NULL DEFAULT 1,
      sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
      unsubscribe_token CHAR(40) NOT NULL UNIQUE,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_npref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS notification_preferences;
    DROP TABLE IF EXISTS notifications;
    DROP TABLE IF EXISTS failed_jobs;
    DROP TABLE IF EXISTS jobs;
    SQL,
];
