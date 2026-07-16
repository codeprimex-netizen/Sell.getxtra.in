<?php

declare(strict_types=1);

/**
 * Migration: catalog tables — categories, tags, products, product_tag,
 * license_tiers, product_files, product_versions. Realizes DESIGN.md §5.2.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS categories (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      parent_id INT UNSIGNED NULL,
      name VARCHAR(120) NOT NULL,
      slug VARCHAR(150) NOT NULL UNIQUE,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS tags (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(80) NOT NULL,
      slug VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS products (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      seller_id BIGINT UNSIGNED NOT NULL,
      category_id INT UNSIGNED NULL,
      title VARCHAR(200) NOT NULL,
      slug VARCHAR(220) NOT NULL UNIQUE,
      short_desc VARCHAR(300) NULL,
      description MEDIUMTEXT NULL,
      tech_stack VARCHAR(255) NULL,
      difficulty ENUM('beginner','intermediate','advanced') NULL,
      dependencies VARCHAR(255) NULL,
      base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      currency CHAR(3) NOT NULL DEFAULT 'INR',
      thumbnail_url VARCHAR(255) NULL,
      demo_url VARCHAR(255) NULL,
      status ENUM('draft','pending','in_review','approved','rejected','suspended','archived') NOT NULL DEFAULT 'draft',
      scan_status ENUM('pending','clean','infected','error') NOT NULL DEFAULT 'pending',
      reject_reason VARCHAR(500) NULL,
      is_featured TINYINT(1) NOT NULL DEFAULT 0,
      views BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sales_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      avg_rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
      rating_count INT UNSIGNED NOT NULL DEFAULT 0,
      meta_title VARCHAR(180) NULL,
      meta_description VARCHAR(300) NULL,
      published_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      CONSTRAINT fk_prod_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
      INDEX idx_prod_status (status),
      INDEX idx_prod_cat (category_id),
      INDEX idx_prod_featured (is_featured),
      INDEX idx_prod_seller (seller_id),
      FULLTEXT INDEX ft_prod (title, short_desc, description)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS product_tag (
      product_id BIGINT UNSIGNED NOT NULL,
      tag_id INT UNSIGNED NOT NULL,
      PRIMARY KEY (product_id, tag_id),
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS license_tiers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(80) NOT NULL,
      price DECIMAL(12,2) NOT NULL,
      sale_price DECIMAL(12,2) NULL,
      sale_starts_at DATETIME NULL,
      sale_ends_at DATETIME NULL,
      description VARCHAR(255) NULL,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS product_files (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id BIGINT UNSIGNED NOT NULL,
      type ENUM('screenshot','doc','thumbnail') NOT NULL,
      storage_key VARCHAR(300) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS product_versions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id BIGINT UNSIGNED NOT NULL,
      version_number VARCHAR(30) NOT NULL,
      changelog TEXT NULL,
      storage_key VARCHAR(300) NOT NULL,
      file_size_bytes BIGINT UNSIGNED NULL,
      checksum_sha256 CHAR(64) NULL,
      scan_status ENUM('pending','clean','infected','error') NOT NULL DEFAULT 'pending',
      is_current TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      INDEX idx_ver_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS product_versions;
    DROP TABLE IF EXISTS product_files;
    DROP TABLE IF EXISTS license_tiers;
    DROP TABLE IF EXISTS product_tag;
    DROP TABLE IF EXISTS products;
    DROP TABLE IF EXISTS tags;
    DROP TABLE IF EXISTS categories;
    SQL,
];
