# Design — Sell.getxtra.in

**Project:** Sell.getxtra.in — Digital Products Marketplace
**Base URL:** https://www.sell.getxtra.in
**Developer:** ANSHU E-MITRA AND CSC CENTER
**Stack:** Core PHP 8.x (custom MVC), MySQL (PDO), Bootstrap/Tailwind CDN, vanilla JS/jQuery, Apache/Nginx

---

## 1. Architecture Overview

A single front controller (`public/index.php`) receives every request through `.htaccess` rewriting, the custom router maps the URL to a controller action, the controller uses models (PDO) for data and renders a view. No third-party framework — routing, security, and structure are hand-built.

```
                    ┌──────────────────────────────────────────┐
   Browser  ──────► │  Apache/Nginx  +  .htaccess (clean URLs)   │
                    └───────────────┬────────────────────────────┘
                                    ▼
                        public/index.php  (Front Controller)
                                    ▼
                        Router  ──►  Middleware (auth, CSRF, RBAC)
                                    ▼
                          Controller  ──►  Model (PDO)  ──►  MySQL
                                    ▼
                              View / Template  ──►  HTML
```

### Request Lifecycle
1. `.htaccess` rewrites all non-file requests to `public/index.php`.
2. Front controller bootstraps: load config, autoloader, start secure session, init PDO.
3. Router matches method + path to `Controller@action`.
4. Middleware runs (auth check, RBAC, CSRF validation on POST).
5. Controller executes, calls Model(s), passes data to a View.
6. View renders with escaped output; response returned.

---

## 2. Directory Structure

```
Sell.getxtra.in/
├── public/                     # Web root (only this is exposed)
│   ├── index.php               # Front controller
│   ├── .htaccess               # Rewrite rules
│   └── assets/                 # css, js, images (public)
├── app/
│   ├── Config/
│   │   ├── config.php          # App constants, BASE_URL, paths
│   │   ├── database.php        # PDO connection (env-driven)
│   │   └── routes.php          # Route definitions
│   ├── Core/
│   │   ├── Router.php          # Custom router
│   │   ├── Controller.php      # Base controller (view render helpers)
│   │   ├── Model.php           # Base model (PDO wrapper)
│   │   ├── Database.php        # PDO singleton
│   │   ├── Request.php         # Input access + sanitization
│   │   ├── Session.php         # Session helpers
│   │   ├── Csrf.php            # CSRF token generate/verify
│   │   ├── Auth.php            # Login/logout, current user
│   │   └── Validator.php       # Input validation rules
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── RoleMiddleware.php  # buyer/seller/admin gate
│   │   └── CsrfMiddleware.php
│   ├── Controllers/
│   │   ├── HomeController.php
│   │   ├── AuthController.php
│   │   ├── ProductController.php
│   │   ├── SearchController.php
│   │   ├── CartCheckoutController.php
│   │   ├── PaymentController.php     # gateway + webhooks
│   │   ├── DownloadController.php    # secure token downloads
│   │   ├── ReviewController.php
│   │   ├── WishlistController.php
│   │   ├── Seller/DashboardController.php
│   │   ├── Seller/ProductManageController.php
│   │   ├── Seller/PayoutController.php
│   │   └── Admin/*Controller.php     # products, users, categories, disputes
│   ├── Models/
│   │   ├── User.php
│   │   ├── Product.php
│   │   ├── ProductVersion.php
│   │   ├── ProductFile.php
│   │   ├── Category.php
│   │   ├── Tag.php
│   │   ├── Order.php
│   │   ├── OrderItem.php
│   │   ├── License.php
│   │   ├── Review.php
│   │   ├── Wishlist.php
│   │   ├── Coupon.php
│   │   ├── Payout.php
│   │   ├── Dispute.php
│   │   └── SupportTicket.php
│   ├── Views/
│   │   ├── layouts/            # header, footer, base
│   │   ├── home/
│   │   ├── auth/
│   │   ├── products/
│   │   ├── seller/
│   │   ├── admin/
│   │   └── errors/            # 403, 404, 500
│   ├── Services/
│   │   ├── PaymentService.php  # Razorpay/Stripe SDK wrapper
│   │   ├── InvoiceService.php  # TCPDF/DomPDF
│   │   ├── UploadService.php   # validated file uploads
│   │   ├── DownloadTokenService.php
│   │   ├── LicenseService.php
│   │   └── SlugService.php
│   └── Helpers/
│       └── functions.php       # e(), url(), asset(), old()
├── storage/                    # OUTSIDE public web root
│   ├── uploads/products/       # zip/code files (protected)
│   ├── uploads/docs/
│   ├── invoices/
│   └── logs/
├── database/
│   ├── schema.sql              # Full DDL
│   └── seeds.sql               # Sample/seed data
├── vendor/                     # Composer libs (PDF, gateway SDK)
├── composer.json
├── .env.example
├── REQUIREMENTS.md
├── DESIGN.md
└── TASKS.md
```

---

## 3. Routing Design

Routes registered as `method, pattern, [Controller, action], [middleware]`. Patterns support params like `/product/{slug}`.

| Method | Path | Controller@action | Access |
|--------|------|-------------------|--------|
| GET | `/` | Home@index | public |
| GET | `/products` | Product@index | public |
| GET | `/product/{slug}` | Product@show | public |
| GET | `/search` | Search@index | public |
| GET/POST | `/register` | Auth@register | guest |
| GET/POST | `/login` | Auth@login | guest |
| POST | `/logout` | Auth@logout | auth |
| GET | `/wishlist` | Wishlist@index | auth |
| POST | `/wishlist/toggle` | Wishlist@toggle | auth |
| POST | `/review/{productId}` | Review@store | buyer |
| POST | `/checkout` | CartCheckout@process | buyer |
| POST | `/payment/webhook` | Payment@webhook | public (signed) |
| GET | `/download/{token}` | Download@serve | buyer |
| GET | `/seller/dashboard` | Seller\Dashboard@index | seller |
| GET/POST | `/seller/products/create` | Seller\ProductManage@create | seller |
| POST | `/seller/payouts/request` | Seller\Payout@request | seller |
| GET | `/admin` | Admin\Dashboard@index | admin |
| POST | `/admin/products/{id}/status` | Admin\Product@updateStatus | admin |
| GET | `/sitemap.xml` | Home@sitemap | public |

Unmatched routes → `errors/404`.

---

## 4. Security Design

- **SQL Injection:** All queries via PDO prepared statements with bound params (base `Model` enforces this).
- **XSS:** Global `e()` helper (`htmlspecialchars`) on every output; views never echo raw user data.
- **CSRF:** `Csrf::token()` embeds a hidden field + session token; `CsrfMiddleware` validates on all POST/PUT/DELETE.
- **Auth:** `password_hash()` (bcrypt) + `password_verify()`; `session_regenerate_id(true)` on login; secure, HttpOnly, SameSite cookies.
- **RBAC:** `RoleMiddleware` checks `session role` against route requirement → 403 on mismatch.
- **File Uploads:** `UploadService` validates extension whitelist + MIME (`finfo`) + size cap; randomized stored filenames; product files saved under `storage/` (outside web root).
- **Secure Downloads:** `DownloadController` verifies token + ownership + expiry, then streams file with `Content-Disposition` headers; real path never exposed.
- **Transport:** Force HTTPS; HSTS header.
- **Rate limiting:** Failed-login throttle keyed by IP/email.

---

## 5. Data Model (MySQL Schema)

Engine: InnoDB, charset `utf8mb4`. Timestamps default `CURRENT_TIMESTAMP`.

### 5.1 Entity Relationship (summary)
- `users` 1—N `products` (seller)
- `products` 1—N `product_versions`, 1—N `product_files`, N—N `tags` (via `product_tag`)
- `products` N—1 `categories`
- `users` 1—N `orders` 1—N `order_items` N—1 `products`
- `order_items` 1—N `licenses`, 1—1 `download_tokens`
- `products` 1—N `reviews` N—1 `users`
- `users` N—N `products` via `wishlists`
- `users` 1—N `payouts`, `disputes`, `support_tickets`

### 5.2 DDL

```sql
-- USERS
CREATE TABLE users (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer',
  is_verified    TINYINT(1) NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  avatar         VARCHAR(255) NULL,
  bio            TEXT NULL,
  referral_code  VARCHAR(20) NULL UNIQUE,
  referred_by    BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CATEGORIES
CREATE TABLE categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id   INT UNSIGNED NULL,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(150) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TAGS
CREATE TABLE tags (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(80) NOT NULL,
  slug  VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRODUCTS
CREATE TABLE products (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seller_id        BIGINT UNSIGNED NOT NULL,
  category_id      INT UNSIGNED NULL,
  title            VARCHAR(200) NOT NULL,
  slug             VARCHAR(220) NOT NULL UNIQUE,
  short_desc       VARCHAR(300) NULL,
  description      MEDIUMTEXT NULL,
  tech_stack       VARCHAR(255) NULL,
  difficulty       ENUM('beginner','intermediate','advanced') NULL,
  dependencies     VARCHAR(255) NULL,
  price            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_price   DECIMAL(10,2) NULL,
  thumbnail        VARCHAR(255) NULL,
  demo_url         VARCHAR(255) NULL,
  file_size_bytes  BIGINT UNSIGNED NULL,
  status           ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  reject_reason    VARCHAR(500) NULL,
  is_featured      TINYINT(1) NOT NULL DEFAULT 0,
  views            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sales_count      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  avg_rating       DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  meta_title       VARCHAR(180) NULL,
  meta_description VARCHAR(300) NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_prod_status (status),
  INDEX idx_prod_category (category_id),
  INDEX idx_prod_featured (is_featured),
  FULLTEXT INDEX ft_prod (title, short_desc, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRODUCT ↔ TAG (many-to-many)
CREATE TABLE product_tag (
  product_id BIGINT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, tag_id),
  CONSTRAINT fk_pt_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRODUCT VERSIONS + CHANGELOG
CREATE TABLE product_versions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id     BIGINT UNSIGNED NOT NULL,
  version_number VARCHAR(30) NOT NULL,
  changelog      TEXT NULL,
  file_path      VARCHAR(255) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  is_current     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ver_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_ver_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRODUCT MEDIA/FILES (screenshots, docs)
CREATE TABLE product_files (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  BIGINT UNSIGNED NOT NULL,
  type        ENUM('screenshot','doc','thumbnail') NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pf_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- LICENSE TIERS (per product pricing options)
CREATE TABLE license_tiers (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(80) NOT NULL,      -- e.g. Regular, Extended
  price       DECIMAL(10,2) NOT NULL,
  description VARCHAR(255) NULL,
  CONSTRAINT fk_lt_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- COUPONS
CREATE TABLE coupons (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40) NOT NULL UNIQUE,
  type           ENUM('percent','fixed') NOT NULL,
  value          DECIMAL(10,2) NOT NULL,
  max_uses       INT UNSIGNED NULL,
  used_count     INT UNSIGNED NOT NULL DEFAULT 0,
  min_order      DECIMAL(10,2) NULL,
  starts_at      DATETIME NULL,
  expires_at     DATETIME NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ORDERS
CREATE TABLE orders (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  buyer_id        BIGINT UNSIGNED NOT NULL,
  order_number    VARCHAR(40) NOT NULL UNIQUE,
  subtotal        DECIMAL(10,2) NOT NULL,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total           DECIMAL(10,2) NOT NULL,
  coupon_id       BIGINT UNSIGNED NULL,
  status          ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  gateway         VARCHAR(30) NULL,           -- razorpay/stripe
  gateway_ref     VARCHAR(120) NULL,          -- payment/order id
  invoice_path    VARCHAR(255) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  INDEX idx_order_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ORDER ITEMS
CREATE TABLE order_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  seller_id       BIGINT UNSIGNED NOT NULL,
  license_tier_id BIGINT UNSIGNED NULL,
  unit_price      DECIMAL(10,2) NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_oi_seller FOREIGN KEY (seller_id) REFERENCES users(id),
  INDEX idx_oi_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- LICENSE KEYS (issued per purchased item)
CREATE TABLE licenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  buyer_id      BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  license_key   VARCHAR(80) NOT NULL UNIQUE,
  status        ENUM('active','revoked') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lic_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_lic_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lic_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SECURE DOWNLOAD TOKENS
CREATE TABLE download_tokens (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  buyer_id      BIGINT UNSIGNED NOT NULL,
  token         CHAR(64) NOT NULL UNIQUE,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_downloads INT UNSIGNED NULL,
  expires_at    DATETIME NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dt_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_dt_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_dt_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REVIEWS & RATINGS
CREATE TABLE reviews (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id   BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  rating       TINYINT UNSIGNED NOT NULL,   -- 1..5
  comment      TEXT NULL,
  is_verified  TINYINT(1) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rev_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_review (product_id, user_id),
  INDEX idx_rev_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WISHLIST
CREATE TABLE wishlists (
  user_id    BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, product_id),
  CONSTRAINT fk_wl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wl_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PAYOUTS (seller withdrawals)
CREATE TABLE payouts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seller_id   BIGINT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  method      VARCHAR(40) NULL,           -- bank/upi/paypal
  details     VARCHAR(255) NULL,
  status      ENUM('requested','processing','paid','rejected') NOT NULL DEFAULT 'requested',
  note        VARCHAR(255) NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  CONSTRAINT fk_payout_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DISPUTES / REFUNDS
CREATE TABLE disputes (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    BIGINT UNSIGNED NOT NULL,
  raised_by   BIGINT UNSIGNED NOT NULL,
  reason      VARCHAR(500) NOT NULL,
  status      ENUM('open','under_review','resolved','refunded','rejected') NOT NULL DEFAULT 'open',
  resolution  VARCHAR(500) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_disp_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_disp_user FOREIGN KEY (raised_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SUPPORT TICKETS
CREATE TABLE support_tickets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  status      ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ticket_messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   BIGINT UNSIGNED NOT NULL,
  sender_id   BIGINT UNSIGNED NOT NULL,
  message     TEXT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REFERRAL TRACKING
CREATE TABLE referrals (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id   BIGINT UNSIGNED NOT NULL,
  referred_id   BIGINT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  commission    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status        ENUM('pending','credited') NOT NULL DEFAULT 'pending',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- LOGIN THROTTLE (rate limiting)
CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier  VARCHAR(190) NOT NULL,   -- email or ip
  attempts    INT UNSIGNED NOT NULL DEFAULT 1,
  last_attempt DATETIME NOT NULL,
  INDEX idx_la_identifier (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. Key Flows

### 6.1 Purchase → Download
1. Buyer clicks Buy → `CartCheckout@process` creates `orders` (status `pending`) + `order_items`.
2. `PaymentService` initiates gateway order; buyer pays on gateway.
3. Gateway webhook → `Payment@webhook` verifies signature → order `paid`.
4. On paid: generate `licenses`, create `download_tokens` (expiry set), generate invoice PDF, increment `products.sales_count`.
5. Buyer visits `/download/{token}` → `Download@serve` validates token/owner/expiry → streams file.

### 6.2 Product Approval
1. Seller submits → product `pending`.
2. Admin reviews → `approved` (visible) or `rejected` (+reason).
3. Seller edits rejected product → resubmit → `pending`.

### 6.3 Average Rating
On review insert/delete, recompute `products.avg_rating = AVG(reviews.rating)` for that product (transactional update).

---

## 7. Third-Party Libraries (via Composer)
- **PDF invoices:** `dompdf/dompdf` or `tecnickcom/tcpdf`.
- **Payments:** `razorpay/razorpay` and/or `stripe/stripe-php`.
- **Env:** `vlucas/phpdotenv` (optional) for `.env` loading.

## 8. Deployment Notes
- Point Apache/Nginx document root to `/public` only.
- `.env`/config with real secrets excluded from VCS.
- Enable HTTPS, HSTS; set `storage/` non-web-accessible.
- Cron: sitemap regeneration, token cleanup, payout reminders.
