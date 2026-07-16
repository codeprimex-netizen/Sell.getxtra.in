# Requirements — Sell.getxtra.in (Enterprise Edition)

**Project:** Sell.getxtra.in — Enterprise Digital Products Marketplace
**Base URL:** https://www.sell.getxtra.in
**Developer:** ANSHU E-MITRA AND CSC CENTER
**Tech Stack:** Core PHP 8.3+ (custom modular framework, PSR-4/PSR-12, no full framework), MySQL 8 (PDO, primary + read replicas), Redis (cache/session/queue), OpenSearch/Meilisearch (search), S3-compatible object storage + CDN, Nginx + PHP-FPM, Docker, CI/CD
**Document Status:** Baseline v2.0 (Enterprise)

---

## 1. Introduction

Sell.getxtra.in is an **enterprise-grade digital products marketplace** where verified sellers list and sell digital goods (scripts, themes, templates, plugins, source code, e-books, docs) and buyers discover, purchase, and securely download them. Although built on **custom Core PHP** (no Laravel/Symfony), it MUST meet enterprise expectations: high availability, horizontal scalability, strong security & compliance (OWASP ASVS, PCI-DSS SAQ-A, GDPR/DPDP), full observability, automated CI/CD, and a documented API surface.

### 1.1 Goals
- **Reliability:** 99.9% monthly uptime; graceful degradation.
- **Scale:** Serve spikes (launches/sales) via stateless app tier, caching, and async workers.
- **Security & Trust:** Defense-in-depth; auditable; least privilege.
- **Maintainability:** Layered architecture, tests, quality gates, IaC.
- **Time-to-market:** Delivered in well-defined phases with clear acceptance criteria.

### 1.2 Actors / Roles (RBAC)
| Role | Description |
|------|-------------|
| **Guest** | Unauthenticated visitor; browse, search, view public product pages. |
| **Buyer** | Registered user; cart, purchase, download, review, wishlist, tickets. |
| **Seller** | KYC-verified user; list/manage products, versions, view analytics, request payouts. |
| **Support Agent** | Handles tickets & disputes; limited read access to orders/users. |
| **Content Moderator** | Reviews/approves product submissions and flagged reviews. |
| **Finance/Accounts** | Manages payouts, refunds, ledgers, tax reports. |
| **Admin** | Platform operations; user/category management, feature flags. |
| **Super Admin** | Full control incl. role/permission management and system settings. |

Roles map to **granular permissions** (e.g., `product.approve`, `payout.process`, `user.suspend`); roles are permission bundles.

### 1.3 EARS Notation Key
- **Ubiquitous:** "The system SHALL ..."
- **Event-driven:** "WHEN <trigger>, the system SHALL ..."
- **State-driven:** "WHILE <state>, the system SHALL ..."
- **Optional:** "WHERE <feature included>, the system SHALL ..."
- **Unwanted:** "IF <condition>, THEN the system SHALL ..."

### 1.4 Priority (MoSCoW)
Each requirement is tagged **[M]** Must, **[S]** Should, **[C]** Could for phased delivery.

---

## 2. Functional Requirements

### Requirement 1 — Platform Foundation & Modular Architecture **[M]**
**User Story:** As an engineering team, we want a layered, testable foundation, so that the platform scales and stays maintainable without a heavy framework.

#### Acceptance Criteria
1. The system SHALL implement a layered architecture: **HTTP (controllers/middleware) → Application/Services → Domain → Infrastructure (repositories/gateways)**.
2. The system SHALL use **PSR-4 autoloading** (Composer) and follow **PSR-12** coding style.
3. The system SHALL route all HTTP traffic through a single front controller with a middleware pipeline and a compiled/cached route table.
4. The system SHALL load configuration from environment variables (12-factor); secrets SHALL NOT be committed to VCS.
5. The system SHALL provide a lightweight **dependency injection container** for service resolution.
6. The system SHALL support **environment profiles** (local, staging, production) with per-environment config.
7. The system SHALL expose a **feature-flag** mechanism to enable/disable features without redeploy.
8. IF a route/resource is not found, THEN the system SHALL return a structured 404 (HTML for web, JSON for API).

### Requirement 2 — Authentication & Identity **[M]**
**User Story:** As a user, I want secure, modern sign-in with MFA and recovery, so that my account is protected.

#### Acceptance Criteria
1. WHEN a user registers, the system SHALL hash passwords with **Argon2id** (bcrypt fallback) and enforce a password policy (length, breach check against known-compromised lists).
2. WHEN valid credentials are submitted, the system SHALL authenticate, regenerate the session ID, and create a server-side session stored in **Redis**.
3. The system SHALL support **email verification** and **secure password reset** via single-use, expiring tokens.
4. The system SHALL support **Two-Factor Authentication (TOTP)** and SHALL require it for privileged roles (Admin/Finance/Super Admin).
5. WHERE social login is enabled, the system SHALL support **OAuth 2.0** (Google) with account linking.
6. The system SHALL let users view and revoke **active sessions/devices**.
7. IF credentials are invalid, THEN the system SHALL respond generically (no user enumeration) and record the attempt.
8. IF repeated failed logins exceed a threshold, THEN the system SHALL apply **progressive lockout / rate limiting** keyed by account + IP.
9. WHEN a user logs out, the system SHALL invalidate the server-side session and clear cookies.

### Requirement 3 — Authorization & RBAC **[M]**
**User Story:** As the platform, I want granular role/permission control, so that each actor accesses only what they should.

#### Acceptance Criteria
1. The system SHALL model **roles and permissions** (many-to-many) and assign one or more roles per user.
2. Middleware SHALL enforce permission checks per route/action; unauthorized access SHALL return **403**.
3. WHILE unauthenticated, protected routes SHALL redirect (web) or return **401** (API).
4. Back-office (admin/moderation/finance) SHALL be isolated under a separate path and SHALL require MFA.
5. The system SHALL enforce **object-level authorization** (e.g., sellers act only on their own products; buyers only on their own orders).
6. All privileged actions SHALL be recorded in an **audit log** (Req 15).

### Requirement 4 — Product Listing & Catalog **[M]**
**User Story:** As a seller, I want rich product listings with media, files, and versions, so that buyers can evaluate and purchase confidently.

#### Acceptance Criteria
1. WHEN a seller submits a product, the system SHALL capture title, short/long description (rich text, sanitized), category, tags, tech stack, difficulty, and dependencies.
2. The system SHALL accept media (thumbnail, gallery screenshots, demo URL/video) and store them in **object storage (S3)** served via **CDN**.
3. WHEN a deliverable file (zip/code) is uploaded, the system SHALL validate extension whitelist, MIME (via content inspection), and size cap, and SHALL store it in a **private bucket** (not web-accessible).
4. Uploaded deliverables SHALL be **queued for asynchronous malware/AV scanning**; a product SHALL NOT be purchasable until the scan passes.
5. The system SHALL support **single and multiple license tiers** (e.g., Regular/Extended) with independent pricing and optional discounts and scheduled sale windows.
6. WHEN a product is saved, the system SHALL auto-generate a unique SEO slug and capture meta title/description and Open Graph fields.
7. The system SHALL auto-calculate file size and support draft autosave.
8. The system SHALL manage lifecycle status: `draft → pending → in_review → approved → rejected → suspended → archived`.
9. WHEN a rejected product is edited, the system SHALL pre-fill from persisted data and allow resubmission.
10. IF any upload fails validation or scanning, THEN the system SHALL block publication and surface a clear, actionable error.

### Requirement 5 — Versioning & Changelog **[M]**
**User Story:** As a seller, I want to publish versioned updates, so that buyers get the latest and can review history.

#### Acceptance Criteria
1. WHEN a seller uploads a new version, the system SHALL store version number (semver), changelog, and deliverable, linked to the product.
2. WHEN a new version is approved, the system SHALL mark it current and **notify** existing buyers (Req 13).
3. The system SHALL retain prior versions and expose version history on the product page and in the buyer's downloads.

### Requirement 6 — Search & Discovery **[M]**
**User Story:** As a buyer, I want fast, relevant search and faceted filtering, so that I quickly find products.

#### Acceptance Criteria
1. The system SHALL index approved products into a **search engine (OpenSearch/Meilisearch)** with near-real-time updates via async indexing.
2. The system SHALL provide full-text search with typo tolerance, ranking, and highlighting.
3. The system SHALL provide **faceted filters** (category, price range, tech stack, rating, license type) and sorting (relevance, newest, best-selling, price, rating).
4. IF the search engine is unavailable, THEN the system SHALL **degrade gracefully** to a MySQL FULLTEXT fallback.
5. The system SHALL show related/recommended products and personalized "recently viewed".
6. The system SHALL only surface `approved` (and scan-passed) products to guests/buyers.

### Requirement 7 — Reviews, Ratings & Wishlist **[S]**
**User Story:** As a buyer, I want to save products and leave verified reviews, so that I can decide and share feedback.

#### Acceptance Criteria
1. WHEN a buyer adds to wishlist, the system SHALL persist it per user; guests SHALL use a session wishlist merged on login.
2. WHEN a buyer who purchased submits a review, the system SHALL store a rating (1–5) + comment and mark it **verified purchase**.
3. IF a user has not purchased, THEN the system SHALL prevent verified reviews (config controls unverified reviews).
4. Reviews SHALL pass a **moderation queue** (spam/abuse) before publication where enabled.
5. The system SHALL maintain a denormalized aggregate rating updated via events/jobs and allow sellers to respond to reviews.

### Requirement 8 — Cart & Checkout **[M]**
**User Story:** As a buyer, I want a multi-item cart with coupons and taxes, so that I can purchase smoothly.

#### Acceptance Criteria
1. The system SHALL support a **persistent multi-item cart** (per user; session for guests, merged on login).
2. WHEN a coupon is applied, the system SHALL validate code, scope, min-order, usage limits, and expiry, and recompute totals.
3. The system SHALL compute **taxes/GST** and support **multi-currency** display with a base settlement currency.
4. The system SHALL create an idempotent order at checkout and prevent duplicate charges via idempotency keys.
5. IF an item becomes unavailable/suspended during checkout, THEN the system SHALL remove it and notify the buyer before payment.

### Requirement 9 — Payments, Wallet & Refunds **[M]**
**User Story:** As a buyer/seller, I want secure payments, accurate settlements, and refunds, so that money is handled correctly.

#### Acceptance Criteria
1. The system SHALL integrate **multiple payment gateways** (Razorpay, Stripe, PayPal) behind a common payment abstraction, using server-side APIs only.
2. The system SHALL minimize PCI scope (**SAQ-A**): card data handled by the gateway (hosted fields/redirect); the platform SHALL NOT store PAN/CVV.
3. WHEN a gateway webhook confirms payment, the system SHALL verify the **signature**, mark the order paid **idempotently**, and grant entitlements.
4. IF payment fails/aborts, THEN the order SHALL remain `pending/failed` and no entitlement SHALL be granted.
5. WHEN an order is paid, the system SHALL record a **double-entry ledger** transaction, credit the seller wallet (minus commission/fees/taxes), and generate a PDF invoice asynchronously.
6. The system SHALL support **full/partial refunds** with corresponding reversing ledger entries and entitlement revocation policy.
7. The system SHALL reconcile gateway settlements against internal ledgers (reporting for Finance).

### Requirement 10 — Entitlements & Secure Downloads **[M]**
**User Story:** As the platform, I want downloads gated to entitled buyers, so that files can't be leaked.

#### Acceptance Criteria
1. Deliverables SHALL be stored in **private object storage**; the system SHALL serve them via **short-lived signed URLs** or a streaming proxy.
2. WHEN an entitled buyer requests a download, the system SHALL verify ownership, license validity, and per-token limits/expiry before granting access.
3. The system SHALL generate and store a **license key** per purchased license and expose a license verification endpoint.
4. IF a token/link is expired, over-limit, or unauthorized, THEN the system SHALL deny access and log it.
5. The system SHALL never expose real storage paths or bucket internals to clients.

### Requirement 11 — Seller Console, KYC & Payouts **[M]**
**User Story:** As a seller, I want onboarding, analytics, and reliable payouts, so that I can run my business.

#### Acceptance Criteria
1. WHILE authenticated as a seller, the system SHALL scope all data to that seller and require **KYC verification** before selling/payout.
2. The system SHALL show a dashboard: sales, revenue, wallet balance, views, conversion, top products (from analytics aggregates, cached).
3. WHEN a seller requests a payout, the system SHALL validate available (cleared) balance, create a payout request, and route it to Finance for processing.
4. The system SHALL maintain a **seller wallet ledger** distinguishing pending vs. cleared (post-refund-window) balances.
5. WHERE automated payouts are enabled, the system SHALL disburse via gateway payout APIs and record references.

### Requirement 12 — Back-Office / Admin Console **[M]**
**User Story:** As back-office staff, I want role-scoped tools, so that I can moderate and operate the marketplace.

#### Acceptance Criteria
1. The system SHALL provide a **moderation queue** for product/version/review approval with reason capture.
2. The system SHALL support featured/spotlight toggles, category/tag CRUD, and content/banner management.
3. The system SHALL provide user management (search, suspend, impersonate-with-audit, role assignment).
4. The system SHALL provide dispute/refund workflows with status tracking and SLA timers.
5. The system SHALL expose operational dashboards (sales, GMV, top sellers, moderation backlog).
6. Every back-office mutation SHALL be recorded in the audit log with actor, before/after, and timestamp.

### Requirement 13 — Notifications & Communications **[S]**
**User Story:** As a user, I want timely notifications, so that I stay informed.

#### Acceptance Criteria
1. The system SHALL send **transactional email** (verification, receipts, payout, product updates) via a provider (SES/SMTP) using templated, queued messages.
2. The system SHALL provide **in-app notifications** and support **SMS** for critical events (config-gated).
3. The system SHALL respect user **notification preferences** and include unsubscribe handling for non-critical mail.
4. All outbound messages SHALL be dispatched via the **async queue** with retries and dead-letter handling.

### Requirement 14 — Security & Compliance **[M]**
**User Story:** As the platform owner, I want strong, auditable security and legal compliance, so that data and funds are protected.

#### Acceptance Criteria
1. The system SHALL use **parameterized queries/ORM-safe access** everywhere (no dynamic SQL string concatenation).
2. The system SHALL apply context-aware **output encoding** and a strict **Content-Security-Policy** to prevent XSS.
3. The system SHALL enforce **CSRF tokens** on state-changing web requests and use SameSite cookies.
4. The system SHALL validate/scan all uploads (whitelist + content-type + AV) and store deliverables privately.
5. The system SHALL enforce **TLS 1.2+**, HSTS, secure/HttpOnly cookies, and recommended security headers.
6. The system SHALL manage secrets via a **secrets manager/Vault**, not source or plaintext env in VCS.
7. The system SHALL apply **rate limiting** on auth, API, and expensive endpoints (Redis-based).
8. The system SHALL comply with **GDPR/DPDP**: consent, data export, and right-to-erasure workflows; and define data-retention policies.
9. The system SHALL keep PCI scope to **SAQ-A** and undergo periodic dependency and security scanning (SAST/DAST/deps).
10. IF a security event (privilege change, suspicious login, mass download) occurs, THEN the system SHALL log and alert.

### Requirement 15 — Observability & Auditability **[M]**
**User Story:** As operators, we want logs, metrics, traces, and audit trails, so that we can detect and diagnose issues.

#### Acceptance Criteria
1. The system SHALL emit **structured JSON logs** with correlation/request IDs, shipped to centralized logging (ELK/OpenSearch/Loki).
2. The system SHALL expose **metrics** (RED/USE) and dashboards (Prometheus/Grafana) and business KPIs.
3. WHERE tracing is enabled, the system SHALL support **distributed tracing** (OpenTelemetry) across web→queue→jobs.
4. The system SHALL provide **health/readiness endpoints** for the app, DB, cache, queue, and search.
5. The system SHALL maintain an **immutable audit log** of security- and money-sensitive actions.
6. The system SHALL define **alerting** rules (error rate, latency, queue backlog, payment failures) with on-call routing.

### Requirement 16 — Performance & Caching **[M]**
**User Story:** As a user, I want fast pages, so that browsing and buying feel instant.

#### Acceptance Criteria
1. The system SHALL cache hot data (categories, product cards, aggregates) in **Redis** with explicit invalidation on writes.
2. The system SHALL serve static assets and media via **CDN** with cache headers and fingerprinting.
3. The system SHALL use DB **read replicas** for read-heavy queries and connection pooling.
4. The system SHALL apply pagination/keyset pagination for large lists and avoid N+1 queries.
5. Product listing/search P95 latency SHALL be **≤ 400ms** server-side under target load (see NFRs).

### Requirement 17 — Scalability & High Availability **[M]**
**User Story:** As the platform, I want to scale horizontally and survive failures, so that we stay up during spikes.

#### Acceptance Criteria
1. The application tier SHALL be **stateless** (sessions/cache in Redis) to allow horizontal scaling behind a load balancer.
2. The system SHALL run **background workers** separately from web nodes and scale them independently.
3. The system SHALL support **DB replication** (primary + replicas) and documented failover.
4. The system SHALL degrade gracefully when non-critical dependencies (search, mail) are down.
5. The system SHALL define **backup, restore, and DR** with target **RPO ≤ 15 min** and **RTO ≤ 1 hour**.

### Requirement 18 — Asynchronous Processing & Jobs **[M]**
**User Story:** As the platform, I want heavy work off the request path, so that responses stay fast and reliable.

#### Acceptance Criteria
1. The system SHALL provide a **queue** (Redis/RabbitMQ/SQS) and worker runtime for jobs: AV scan, invoice/PDF generation, email/SMS, search indexing, thumbnail processing, analytics rollups, webhooks dispatch.
2. Jobs SHALL support **retries with backoff**, idempotency, and a **dead-letter queue**.
3. The system SHALL support **scheduled jobs (cron)**: sitemap generation, token/session cleanup, wallet clearing, reconciliation, report generation.
4. WHERE a job repeatedly fails, THEN the system SHALL alert operators.

### Requirement 19 — Public API & Integrations **[S]**
**User Story:** As an integrator/partner, I want a documented API, so that I can integrate programmatically.

#### Acceptance Criteria
1. The system SHALL expose a **versioned REST API** (`/api/v1`) returning JSON with consistent error envelopes.
2. The API SHALL authenticate via **API keys / OAuth tokens**, enforce **rate limits**, and scope permissions.
3. The system SHALL publish an **OpenAPI 3** specification and keep it in sync with implementation.
4. The system SHALL support **outbound webhooks** (order paid, product approved, payout processed) with signed payloads and retries.

### Requirement 20 — Growth, SEO & Localization **[S]**
**User Story:** As the business, I want growth levers and SEO, so that we acquire and retain users.

#### Acceptance Criteria
1. The system SHALL support **coupons/promotions** with rules (percent/fixed, scope, schedules, usage caps).
2. WHERE the **affiliate/referral** program is enabled, the system SHALL track referral codes and attribute qualifying purchases with commission ledgering.
3. The system SHALL provide SEO: clean URLs, dynamic meta/OG tags, JSON-LD structured data, auto **sitemap.xml**, and canonical tags.
4. WHERE i18n is enabled, the system SHALL support **localization** (strings, currency, date/number formats).

### Requirement 21 — Support & Disputes **[S]**
**User Story:** As a user, I want support and fair dispute handling, so that issues get resolved.

#### Acceptance Criteria
1. WHEN a user opens a ticket, the system SHALL persist it with category/priority and support threaded replies and attachments.
2. The system SHALL track **SLA timers** and escalate breaches to Support/Admin.
3. The system SHALL link disputes to orders and drive refund/resolution workflows (Req 9/12).

### Requirement 22 — Data Management, Migrations & DR **[M]**
**User Story:** As operators, I want versioned schema and safe data ops, so that changes are reliable and recoverable.

#### Acceptance Criteria
1. The system SHALL manage schema via **versioned, reversible migrations** applied in CI/CD.
2. The system SHALL run **automated encrypted backups** (DB + object storage) with periodic restore drills.
3. The system SHALL define **data-retention** and **anonymization** policies (logs, PII, deleted accounts).
4. The system SHALL support **seed/fixtures** for non-production environments.

### Requirement 23 — CI/CD & Quality Gates **[M]**
**User Story:** As the team, I want automated pipelines, so that we ship safely and repeatably.

#### Acceptance Criteria
1. The system SHALL provide a **CI pipeline**: install → lint (PHP_CodeSniffer) → static analysis (PHPStan/Psalm) → tests → build → security scan.
2. Merges to protected branches SHALL require **passing checks and review**.
3. The system SHALL build **Docker images** and deploy via automated, repeatable **CD** to staging then production (with approval).
4. The system SHALL support **zero-/low-downtime deploys** and fast **rollback**.

### Requirement 24 — Testing Strategy **[M]**
**User Story:** As the team, I want a layered test suite, so that regressions are caught early.

#### Acceptance Criteria
1. The system SHALL include **unit tests** (PHPUnit) for domain/services with a target coverage threshold on critical modules.
2. The system SHALL include **integration tests** (DB, cache, gateway sandboxes) and **end-to-end** tests for core flows (register→buy→download).
3. The system SHALL include **security tests** (SQLi/XSS/CSRF/authz) and **load/performance tests** (k6/JMeter) against SLOs.
4. The system SHALL run tests automatically in CI (Req 23).

---

## 3. Non-Functional Requirements (with SLOs)

| Category | Target |
|----------|--------|
| **Availability** | 99.9% monthly uptime for core purchase/download flows. |
| **Latency** | P95 ≤ 400ms server-side for listing/search/detail; P99 ≤ 800ms. Checkout API P95 ≤ 700ms (excl. gateway). |
| **Throughput** | Sustain ≥ 500 req/s at target infra; scale horizontally for sale spikes. |
| **Scalability** | Stateless app tier; add nodes/workers without downtime; DB read replicas. |
| **Durability** | RPO ≤ 15 min, RTO ≤ 1 hour; encrypted backups with restore drills. |
| **Security** | OWASP ASVS L2; PCI-DSS SAQ-A; TLS 1.2+; MFA for privileged roles; secrets in vault. |
| **Privacy/Compliance** | GDPR/DPDP: consent, export, erasure; documented retention. |
| **Observability** | Centralized logs, metrics, health checks; alerting with on-call. |
| **Accessibility** | WCAG 2.1 AA for key buyer flows. |
| **Compatibility** | Latest 2 versions of major browsers; responsive/mobile-first. |
| **Maintainability** | PSR-12, static analysis clean, ≥ agreed coverage on critical code. |
| **Localization** | Multi-currency display; i18n-ready string catalog. |

---

## 4. Assumptions & Constraints
- Custom PHP (no full framework) → security, DI, routing, and structure are engineered in-house and rigorously tested.
- Card data never touches app servers (gateway-hosted) to keep PCI scope minimal.
- Object storage (S3-compatible) + CDN are available in the target environment; local disk is a dev-only fallback.
- Managed Redis and MySQL (with replicas) are available in staging/production.

## 5. Out of Scope (initial release)
- Native mobile apps (API is provided for future use).
- Marketplace-wide real-time chat.
- On-platform code execution / sandboxed live previews.
