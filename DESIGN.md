# Design — Code.getxtra.in (Enterprise Edition)

**Project:** Code.getxtra.in — Enterprise Digital Products Marketplace
**Base URL:** https://www.code.getxtra.in
**Developer:** ANSHU E-MITRA AND CSC CENTER
**Stack:** Core PHP 8.3+ (custom modular, PSR-4/PSR-12), MySQL 8 (primary + read replicas, PDO), Redis, OpenSearch/Meilisearch, S3-compatible storage + CDN, Nginx + PHP-FPM, Docker, CI/CD
**Document Status:** Baseline v2.0 (Enterprise) — realizes REQUIREMENTS.md

---

## 1. Architecture Overview

Code.getxtra.in is a **modular monolith** (domain-oriented modules in one deployable) with a strict **layered architecture** and clean separation of concerns. It is designed to be **stateless** at the web tier so it can scale horizontally behind a load balancer, with Redis for shared state, MySQL for durable data (primary + replicas), object storage + CDN for files/media, a search engine for discovery, and a queue + workers for async work.

> Modular monolith is chosen over microservices for delivery speed and operational simplicity, while module boundaries keep a future extraction path open.

### 1.1 Layered Architecture
```
┌───────────────────────────────────────────────────────────┐
│ Presentation      Web views (templates) · REST API (/api/v1)│
├───────────────────────────────────────────────────────────┤
│ HTTP Layer        Front controller · Router · Middleware    │
│                   (auth, RBAC, CSRF, rate-limit, CORS, logs) │
├───────────────────────────────────────────────────────────┤
│ Application       Controllers · Application Services         │
│                   (use-cases, orchestration, transactions)   │
├───────────────────────────────────────────────────────────┤
│ Domain            Entities · Value Objects · Domain Services │
│                   · Policies (authorization) · Events        │
├───────────────────────────────────────────────────────────┤
│ Infrastructure    Repositories (PDO) · Cache · Queue ·       │
│                   Search · Storage(S3) · Gateways (pay/mail) │
└───────────────────────────────────────────────────────────┘
                 Cross-cutting: DI Container · Config ·
                 Logging · Metrics · Tracing · Feature Flags
```

### 1.2 Deployment / Infrastructure Topology
```
                         ┌─────────────┐
        Internet ───────►│    CDN      │  static assets, media, cached pages
                         └─────┬───────┘
                               ▼
                        ┌─────────────┐   TLS termination, security headers
                        │  WAF + LB   │   (Cloud LB / Nginx)
                        └─────┬───────┘
             ┌───────────────┼────────────────┐
             ▼               ▼                 ▼
       ┌──────────┐    ┌──────────┐      ┌──────────┐   stateless
       │ Web/PHP  │    │ Web/PHP  │ ...  │ Web/PHP  │   app nodes
       │  -FPM    │    │  -FPM    │      │  -FPM    │   (Nginx+FPM)
       └────┬─────┘    └────┬─────┘      └────┬─────┘
            └──────────┬────┴───────┬─────────┘
                       ▼            ▼            
                 ┌──────────┐  ┌──────────┐   ┌───────────────┐
                 │  Redis   │  │  Search  │   │ Object Storage │
                 │ cache/   │  │ (OpenS./ │   │  (S3) private  │
                 │ session/ │  │ Meili)   │   │  + CDN public  │
                 │ queue    │  └──────────┘   └───────────────┘
                 └────┬─────┘
                      │ (jobs)
                 ┌────▼───────────┐        ┌──────────────────────┐
                 │ Queue Workers  │        │  MySQL Primary (RW)   │
                 │ (AV, email,    │◄──────►│    │                  │
                 │ pdf, index,    │        │    ├─ Replica (RO)    │
                 │ webhooks)      │        │    └─ Replica (RO)    │
                 └────────────────┘        └──────────────────────┘
        External: Payment Gateways · Mail/SMS · AV scanner · OAuth IdP
        Observability: Logs (ELK/Loki) · Metrics (Prometheus/Grafana) · Traces (OTel)
```

### 1.3 Request Lifecycle (web)
1. CDN/LB terminates TLS, forwards to an app node; static/media served from CDN/S3.
2. Nginx → PHP-FPM → `public/index.php` bootstraps: load env/config, build DI container, init logger + request-id.
3. Router resolves route; **middleware pipeline** runs: request-id/logging → security headers → CORS → session (Redis) → auth → RBAC/policy → CSRF (web) / token (API) → rate-limit.
4. Controller invokes an **Application Service** (use-case) within a DB transaction where needed.
5. Service uses **repositories** (reads may target a replica), **cache**, domain logic, and dispatches **domain events** (e.g., `OrderPaid`).
6. Heavy/side-effect work is **enqueued** (email, invoice, indexing, webhooks) rather than done inline.
7. View is rendered with escaped output (web) or a JSON envelope (API); response returned with cache headers.

---

## 2. Directory Structure (PSR-4)

```
Code.getxtra.in/
├── public/                          # Web root (only this is exposed)
│   ├── index.php                    # Front controller
│   └── assets/                      # built/fingerprinted css,js (also on CDN)
├── src/                             # PSR-4 "App\\"
│   ├── Bootstrap/                   # app kernel, container, error handling
│   ├── Config/                      # config loaders (env-driven), feature flags
│   ├── Http/
│   │   ├── Kernel.php               # middleware pipeline
│   │   ├── Router.php               # cached route table + params
│   │   ├── Middleware/              # Auth, Rbac, Csrf, RateLimit, Cors, RequestId, SecurityHeaders
│   │   ├── Controllers/Web/         # Home, Auth, Product, Cart, Checkout, Download, Review, Wishlist
│   │   ├── Controllers/Seller/      # Dashboard, ProductManage, Version, Payout, Analytics
│   │   ├── Controllers/Admin/       # Moderation, Users, Categories, Disputes, Reports, Settings
│   │   └── Controllers/Api/V1/      # versioned REST controllers
│   ├── Application/                 # Application services (use-cases) per module
│   │   ├── Catalog/ Order/ Payment/ Payout/ Search/ Review/ Notification/ ...
│   ├── Domain/                      # Entities, Value Objects, Domain Services, Policies, Events
│   │   ├── Catalog/ Identity/ Order/ Ledger/ Review/ Support/ ...
│   ├── Infrastructure/
│   │   ├── Persistence/             # PDO connection mgr (RW/RO), repositories, UnitOfWork
│   │   ├── Cache/                   # Redis cache + tags/invalidation
│   │   ├── Queue/                   # queue driver + Job base + dispatcher
│   │   ├── Search/                  # OpenSearch/Meili client + indexer + MySQL fallback
│   │   ├── Storage/                 # S3 adapter + signed URLs
│   │   ├── Payment/                 # Razorpay/Stripe/PayPal adapters (common interface)
│   │   ├── Mail/ Sms/               # providers
│   │   ├── Auth/                    # password hasher, TOTP, OAuth clients
│   │   └── Observability/           # Logger, Metrics, Tracer
│   ├── Jobs/                        # ScanUpload, GenerateInvoice, SendEmail, IndexProduct, DispatchWebhook, RollupAnalytics
│   ├── Console/                     # CLI: migrate, seed, worker, schedule, reindex
│   └── Support/                     # helpers (e(), url(), money(), etc.)
├── resources/
│   ├── views/                       # layouts, web templates, emails
│   └── lang/                        # i18n string catalogs
├── database/
│   ├── migrations/                  # versioned, reversible
│   ├── seeds/                       # roles/permissions, categories, demo data
│   └── schema.sql                   # generated reference DDL
├── storage/                         # logs, tmp, compiled cache (deliverables live in S3)
├── tests/                           # Unit / Integration / E2E / Load
├── docker/                          # Dockerfiles, compose, nginx conf
├── openapi/                         # openapi.yaml (API v1 spec)
├── .github/workflows/               # CI/CD pipelines
├── composer.json  ·  phpstan.neon  ·  phpcs.xml  ·  phpunit.xml
├── .env.example
├── REQUIREMENTS.md · DESIGN.md · TASKS.md
```

---

## 3. Cross-Cutting Design

### 3.1 Dependency Injection & Config
- A small **PSR-11 container**; services registered via providers; constructor injection.
- **12-factor config** from env; typed config objects; **feature flags** resolved at runtime (Redis-backed, cached).

### 3.2 Caching Strategy (Redis)
| Cache | Content | Invalidation |
|-------|---------|--------------|
| Object cache | categories, product cards, seller aggregates | on write / TTL |
| Full-page/fragment | anonymous listing & product pages (edge + Redis) | tag-based on product update |
| Session store | user sessions | logout/expiry |
| Rate-limit | token buckets per IP/user/route | sliding window |
| Query results | expensive reports/aggregates | scheduled refresh |
Cache uses **key tagging** so a product update purges its card, listing fragments, and CDN paths.

### 3.3 Queue & Background Jobs
- Driver: Redis (dev/small) → RabbitMQ/SQS (scale). Base `Job` supports **retries + exponential backoff + DLQ + idempotency keys**.
- Jobs: `ScanUpload`, `GenerateInvoice`, `SendEmail/Sms`, `IndexProduct`, `MakeThumbnails`, `DispatchWebhook`, `RollupAnalytics`, `ClearSellerBalance`, `ReconcileSettlements`.
- **Scheduler (cron)**: sitemap build, token/session cleanup, wallet clearing after refund window, settlement reconciliation, report generation, search reindex.

### 3.4 Search Architecture
- On product approve/version change → emit event → `IndexProduct` job upserts into search engine.
- Query path: search engine for full-text + facets + typo tolerance; **MySQL FULLTEXT fallback** if engine unavailable (Req 6.4).

### 3.5 Storage & Downloads
- **Deliverables** in a **private S3 bucket**; media/thumbnails in a public bucket fronted by CDN.
- Downloads via **short-lived signed URLs** (or streaming proxy) after entitlement + license + token checks; real paths never exposed.

### 3.6 Security Architecture (defense in depth)
- **Edge:** WAF, TLS 1.2+, HSTS, rate limiting, bot protection.
- **App:** parameterized queries only; context-aware output encoding; strict CSP; CSRF tokens + SameSite; security headers (X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
- **Identity:** Argon2id hashing; TOTP MFA (mandatory for privileged roles); OAuth login; session/device management; progressive lockout.
- **Authorization:** RBAC (roles↔permissions) + **object-level policies**; back-office isolated + MFA.
- **Uploads:** whitelist + content-type inspection + async AV scan before purchasable; deliverables private.
- **Secrets:** secrets manager/Vault; no secrets in VCS.
- **Payments:** PCI **SAQ-A** — gateway-hosted card capture; signed webhooks; idempotent processing.
- **Compliance:** GDPR/DPDP consent, data export, erasure; retention/anonymization jobs.
- **Audit:** immutable `audit_logs` for privileged & money actions.

### 3.7 Observability
- **Logs:** structured JSON + correlation/request IDs → ELK/Loki.
- **Metrics:** RED (rate/errors/duration) + business KPIs (GMV, conversion, payment success) → Prometheus/Grafana.
- **Tracing:** OpenTelemetry spans across web→queue→jobs→external.
- **Health:** `/healthz` (liveness), `/readyz` (DB/cache/queue/search readiness).
- **Alerting:** error rate, P95 latency, queue backlog, payment-failure spikes, DLQ growth.

---

## 4. Routing (representative)

| Method | Path | Handler | Access |
|--------|------|---------|--------|
| GET | `/` | Web\Home@index | public |
| GET | `/products` · `/product/{slug}` | Web\Product | public |
| GET | `/search` | Web\Search@index | public |
| GET/POST | `/register` · `/login` · `/password/*` | Web\Auth | guest |
| POST | `/2fa/verify` | Web\Auth@twoFactor | auth |
| GET/POST | `/cart` · `/checkout` | Web\Cart/Checkout | buyer |
| POST | `/payments/{gw}/webhook` | Web\Payment@webhook | public (signed) |
| GET | `/downloads/{entitlement}` | Web\Download@serve | buyer(entitled) |
| GET/POST | `/seller/**` | Seller\* | seller(+KYC) |
| GET/POST | `/admin/**` | Admin\* | back-office(+MFA) |
| GET | `/api/v1/products` … | Api\V1\* | api-token |
| POST | `/api/v1/webhooks/test` | Api\V1\Webhook | api-token |
| GET | `/sitemap.xml` · `/healthz` · `/readyz` | system | public |

Unmatched → 404 (HTML/JSON by content negotiation).

---

## 5. Data Model (MySQL 8)

Engine InnoDB, `utf8mb4`. Money as `DECIMAL(12,2)` with explicit `currency` where relevant. Timestamps default `CURRENT_TIMESTAMP`. Soft-delete (`deleted_at`) on user-facing entities. FKs enforce integrity; hot columns indexed.

### 5.1 Core Identity & Access
```sql
CREATE TABLE users (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  status         ENUM('active','pending','suspended','deleted') NOT NULL DEFAULT 'pending',
  email_verified_at DATETIME NULL,
  two_factor_secret VARBINARY(255) NULL,        -- encrypted
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  locale         VARCHAR(10) NOT NULL DEFAULT 'en',
  referral_code  VARCHAR(20) NULL UNIQUE,
  referred_by    BIGINT UNSIGNED NULL,
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     DATETIME NULL,
  CONSTRAINT fk_users_ref FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE roles (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,             -- buyer, seller, support, moderator, finance, admin, super_admin
  label VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE              -- e.g. product.approve, payout.process
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permission (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_role (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_sessions (
  id           CHAR(64) PRIMARY KEY,            -- session id (also in Redis)
  user_id      BIGINT UNSIGNED NULL,
  ip           VARBINARY(16) NULL,
  user_agent   VARCHAR(255) NULL,
  last_seen_at DATETIME NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_tokens (                       -- email verify / password reset / oauth state
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       ENUM('email_verify','password_reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (type, token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(190) NOT NULL,             -- email or ip
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  locked_until DATETIME NULL,
  last_attempt DATETIME NOT NULL,
  INDEX idx_la (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE seller_profiles (
  user_id     BIGINT UNSIGNED PRIMARY KEY,
  display_name VARCHAR(150) NOT NULL,
  kyc_status  ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
  kyc_ref     VARCHAR(120) NULL,
  payout_method VARCHAR(40) NULL,               -- bank/upi/paypal
  payout_details_enc VARBINARY(1024) NULL,      -- encrypted
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 Catalog
```sql
CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
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
  FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_prod_status (status), INDEX idx_prod_cat (category_id),
  INDEX idx_prod_featured (is_featured), INDEX idx_prod_seller (seller_id),
  FULLTEXT INDEX ft_prod (title, short_desc, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_tag (
  product_id BIGINT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, tag_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE license_tiers (
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

CREATE TABLE product_files (                     -- media (S3 keys)
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  type ENUM('screenshot','doc','thumbnail') NOT NULL,
  storage_key VARCHAR(300) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_versions (                  -- deliverables (private S3)
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
```

### 5.3 Commerce (Cart, Orders, Ledger, Entitlements)
```sql
CREATE TABLE carts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  session_key VARCHAR(64) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_user (user_id),
  INDEX idx_cart_session (session_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cart_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  license_tier_id BIGINT UNSIGNED NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE coupons (
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
  starts_at DATETIME NULL, expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
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
  FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  INDEX idx_order_status (status), INDEX idx_order_buyer (buyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  seller_id BIGINT UNSIGNED NOT NULL,
  license_tier_id BIGINT UNSIGNED NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  commission DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  seller_earning DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (seller_id) REFERENCES users(id),
  INDEX idx_oi_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  gateway VARCHAR(30) NOT NULL,
  gateway_ref VARCHAR(120) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  status ENUM('created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'created',
  raw_payload JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_ref (gateway, gateway_ref),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE webhook_events (                    -- idempotent gateway/webhook intake
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(40) NOT NULL,
  event_id VARCHAR(120) NOT NULL,
  payload JSON NOT NULL,
  processed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wh (source, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE entitlements (                       -- what a buyer may download
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
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ent_buyer (buyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Double-entry style ledger for wallet/settlement accuracy
CREATE TABLE ledger_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type ENUM('platform','seller') NOT NULL,
  owner_id BIGINT UNSIGNED NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  pending_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY uq_acct (owner_type, owner_id, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  ref_type VARCHAR(30) NOT NULL,                 -- order, refund, payout, commission
  ref_id BIGINT UNSIGNED NULL,
  direction ENUM('credit','debit') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  balance_after DECIMAL(14,2) NOT NULL,
  memo VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  INDEX idx_le_acct (account_id), INDEX idx_le_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('requested','approved','processed','rejected') NOT NULL DEFAULT 'requested',
  gateway_ref VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payouts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seller_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  method VARCHAR(40) NULL,
  status ENUM('requested','processing','paid','rejected') NOT NULL DEFAULT 'requested',
  gateway_ref VARCHAR(120) NULL,
  note VARCHAR(255) NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.4 Engagement, Support, Ops
```sql
CREATE TABLE reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','published','rejected') NOT NULL DEFAULT 'pending',
  seller_reply TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review (product_id, user_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_rev_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wishlists (
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, product_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  subject VARCHAR(200) NOT NULL,
  category VARCHAR(50) NULL,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('open','pending','answered','closed') NOT NULL DEFAULT 'open',
  assigned_to BIGINT UNSIGNED NULL,
  sla_due_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ticket_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  attachment_key VARCHAR(300) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE disputes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  raised_by BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  status ENUM('open','under_review','resolved','refunded','rejected') NOT NULL DEFAULT 'open',
  resolution VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL,
  data JSON NOT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user (user_id, read_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  scopes VARCHAR(255) NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE webhooks (                          -- outbound subscriptions
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(300) NOT NULL,
  events VARCHAR(255) NOT NULL,
  secret_enc VARBINARY(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
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
  INDEX idx_audit_actor (actor_id), INDEX idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE jobs (                              -- durable queue backing / DLQ mirror
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue VARCHAR(60) NOT NULL,
  payload JSON NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  reserved_at DATETIME NULL,
  failed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_jobs_queue (queue, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE referrals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id BIGINT UNSIGNED NOT NULL,
  referred_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  commission DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('pending','credited') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE feature_flags (
  name VARCHAR(80) PRIMARY KEY,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_daily_stats (              -- analytics rollup (conversion, views)
  product_id BIGINT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sales BIGINT UNSIGNED NOT NULL DEFAULT 0,
  revenue DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (product_id, day),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. Key Sequence Flows

### 6.1 Purchase → Entitlement → Download (idempotent)
```
Buyer→Checkout: create Order(pending) + order_items (idempotency_key)
Checkout→Gateway: create payment intent (server-side)
Buyer↔Gateway: pays on hosted page
Gateway→/payments/{gw}/webhook: signed event
Webhook: verify signature → dedupe via webhook_events →
         mark payment captured + order paid (transaction) →
         write ledger_entries (platform + seller wallet, minus commission) →
         create entitlements + license keys →
         enqueue: GenerateInvoice, SendEmail, Index/stat update
Buyer→/downloads/{entitlement}: verify owner+license+limits →
         issue short-lived signed S3 URL (or stream)
```

### 6.2 Product Submit → Scan → Approve → Index
```
Seller submits → product pending, version scan_status=pending →
enqueue ScanUpload → AV result:
  clean → moderator approves (in_review→approved, published_at) → emit ProductApproved → IndexProduct job
  infected/error → block, notify seller
```

### 6.3 Refund
```
Support/Finance approves refund → gateway refund API →
reversing ledger_entries → adjust seller wallet (clawback if within window) →
entitlement policy (revoke/keep) → notify buyer
```

---

## 7. API Design (v1)
- Base: `/api/v1`; JSON only; consistent envelope `{ data, meta, error }`.
- Auth: `Authorization: Bearer <token>` or API key; **scoped** permissions; per-key rate limits.
- Pagination: cursor/keyset (`?cursor=`), `meta.next_cursor`.
- Errors: HTTP status + machine `code` + human `message`; validation errors list fields.
- Spec: **OpenAPI 3** in `openapi/openapi.yaml`; contract tests in CI.
- Outbound webhooks: signed (HMAC), retried with backoff; subscribers in `webhooks`.

---

## 8. Technology & Libraries (Composer)
- **Payments:** `razorpay/razorpay`, `stripe/stripe-php`, PayPal SDK — behind `PaymentGateway` interface.
- **PDF:** `dompdf/dompdf` (invoices).
- **Search:** OpenSearch PHP client / Meilisearch PHP SDK.
- **Storage:** `aws/aws-sdk-php` (S3-compatible).
- **Auth:** `spomky-labs/otphp` (TOTP), OAuth client.
- **Logging:** `monolog/monolog` (JSON handler).
- **Env/Config:** `vlucas/phpdotenv`.
- **Quality:** `phpunit/phpunit`, `phpstan/phpstan`, `squizlabs/php_codesniffer`, `vimeo/psalm` (optional).

## 9. Environments & Deployment
- **Containers:** Docker images for web (Nginx+FPM) and worker; `docker-compose` for local (app, mysql, redis, search, mailhog, minio).
- **Environments:** local → staging → production; config via env/secrets manager.
- **CI/CD (GitHub Actions):** lint → static analysis → unit/integration tests → build image → security scan → deploy staging → (approval) → deploy production; DB migrations run as a gated step.
- **Zero/low-downtime:** rolling deploys; health-gated; instant rollback to previous image.
- **Ops runbooks:** backup/restore drills, incident response, on-call alerts.

## 10. Design Decisions & Trade-offs
- **Modular monolith** over microservices: faster delivery, simpler ops; module boundaries preserve future split.
- **Custom framework**: full control and no framework lock-in; cost is that security/DI/routing must be engineered and tested rigorously (mitigated by tests + static analysis + reviews).
- **Ledger-based money**: accuracy and auditability over ad-hoc SUM columns.
- **Search engine + MySQL fallback**: relevance/scale with graceful degradation.
- **Signed URLs**: offload bandwidth to CDN/S3 while keeping deliverables private.
