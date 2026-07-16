<?php

declare(strict_types=1);

/**
 * Migration: identity, access control, and platform-core tables.
 *
 * Realizes DESIGN.md §5.1 (identity & access) plus core operational tables
 * (settings, feature_flags, audit_logs) needed from Phase 1 onward.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      email VARCHAR(190) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      status ENUM('active','pending','suspended','deleted') NOT NULL DEFAULT 'pending',
      email_verified_at DATETIME NULL,
      two_factor_secret VARBINARY(255) NULL,
      two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
      locale VARCHAR(10) NOT NULL DEFAULT 'en',
      referral_code VARCHAR(20) NULL UNIQUE,
      referred_by BIGINT UNSIGNED NULL,
      last_login_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      CONSTRAINT fk_users_ref FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS roles (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(50) NOT NULL UNIQUE,
      label VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS permissions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(80) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS role_permission (
      role_id INT UNSIGNED NOT NULL,
      permission_id INT UNSIGNED NOT NULL,
      PRIMARY KEY (role_id, permission_id),
      FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
      FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS user_role (
      user_id BIGINT UNSIGNED NOT NULL,
      role_id INT UNSIGNED NOT NULL,
      PRIMARY KEY (user_id, role_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS user_sessions (
      id CHAR(64) PRIMARY KEY,
      user_id BIGINT UNSIGNED NULL,
      ip VARBINARY(16) NULL,
      user_agent VARCHAR(255) NULL,
      last_seen_at DATETIME NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS auth_tokens (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      type ENUM('email_verify','password_reset') NOT NULL,
      token_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_token (type, token_hash),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS login_attempts (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      identifier VARCHAR(190) NOT NULL,
      attempts INT UNSIGNED NOT NULL DEFAULT 1,
      locked_until DATETIME NULL,
      last_attempt DATETIME NOT NULL,
      INDEX idx_la (identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS seller_profiles (
      user_id BIGINT UNSIGNED PRIMARY KEY,
      display_name VARCHAR(150) NOT NULL,
      kyc_status ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
      kyc_ref VARCHAR(120) NULL,
      payout_method VARCHAR(40) NULL,
      payout_details_enc VARBINARY(1024) NULL,
      commission_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(100) PRIMARY KEY,
      `value` JSON NOT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS feature_flags (
      name VARCHAR(80) PRIMARY KEY,
      is_enabled TINYINT(1) NOT NULL DEFAULT 0,
      rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS audit_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      actor_id BIGINT UNSIGNED NULL,
      action VARCHAR(80) NOT NULL,
      target_type VARCHAR(60) NULL,
      target_id BIGINT UNSIGNED NULL,
      before_json JSON NULL,
      after_json JSON NULL,
      ip VARBINARY(16) NULL,
      request_id CHAR(36) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_audit_actor (actor_id),
      INDEX idx_audit_target (target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS audit_logs;
    DROP TABLE IF EXISTS feature_flags;
    DROP TABLE IF EXISTS settings;
    DROP TABLE IF EXISTS seller_profiles;
    DROP TABLE IF EXISTS login_attempts;
    DROP TABLE IF EXISTS auth_tokens;
    DROP TABLE IF EXISTS user_sessions;
    DROP TABLE IF EXISTS user_role;
    DROP TABLE IF EXISTS role_permission;
    DROP TABLE IF EXISTS permissions;
    DROP TABLE IF EXISTS roles;
    DROP TABLE IF EXISTS users;
    SQL,
];
