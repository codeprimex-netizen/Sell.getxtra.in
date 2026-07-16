<?php

declare(strict_types=1);

/**
 * Migration: reviews & wishlists (Req 7). Realizes DESIGN.md §5.4.
 * A unique (product_id, user_id) prevents duplicate reviews per user.
 */

return [
    'up' => <<<SQL
    CREATE TABLE IF NOT EXISTS reviews (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      rating TINYINT UNSIGNED NOT NULL,
      comment TEXT NULL,
      is_verified TINYINT(1) NOT NULL DEFAULT 0,
      status ENUM('pending','published','rejected') NOT NULL DEFAULT 'pending',
      seller_reply TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_review (product_id, user_id),
      CONSTRAINT fk_rev_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_rev_product (product_id),
      INDEX idx_rev_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS wishlists (
      user_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, product_id),
      CONSTRAINT fk_wl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_wl_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL,

    'down' => <<<SQL
    DROP TABLE IF EXISTS wishlists;
    DROP TABLE IF EXISTS reviews;
    SQL,
];
