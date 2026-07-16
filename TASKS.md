# Implementation Tasks — Sell.getxtra.in (Enterprise Edition)

**Project:** Sell.getxtra.in — Enterprise Digital Products Marketplace
**Base URL:** https://www.sell.getxtra.in
**Developer:** ANSHU E-MITRA AND CSC CENTER
**Stack:** Core PHP 8.3+ (custom modular, PSR-4/PSR-12), MySQL 8 (+replicas), Redis, OpenSearch/Meilisearch, S3+CDN, Nginx+PHP-FPM, Docker, CI/CD
**Document Status:** Baseline v2.0 (Enterprise) — implements REQUIREMENTS.md, follows DESIGN.md

Each task references the requirement IDs (`Req n`) it fulfills. `[M]/[S]/[C]` = MoSCoW priority. Check items off as completed.

---

## Phase 0 — Discovery & Foundations _(Planning)_
- [ ] 0.1 Competitor & pricing analysis (CodeCanyon, Envato, Gumroad); define GMV/commission model **[M]**
- [ ] 0.2 Finalize scope, roles/permissions matrix, and MoSCoW backlog _(Req 3)_ **[M]**
- [ ] 0.3 Architecture decision records (ADRs): modular monolith, custom framework, ledger, search _(Design §10)_ **[M]**
- [ ] 0.4 Threat model + compliance scoping (OWASP ASVS L2, PCI SAQ-A, GDPR/DPDP) _(Req 14)_ **[M]**
- [ ] 0.5 Figma design system + wireframes (home, listing, product, cart/checkout, dashboards) **[M]**
- [ ] 0.6 Define SLOs/error budgets and KPI dashboards _(NFRs, Req 15)_ **[S]**

## Phase 1 — Platform Core & DevEx _(Foundation)_
- [ ] 1.1 Repo scaffolding: `composer.json` (PSR-4), `.env.example`, `.gitignore`, coding standards _(Req 1)_ **[M]**
- [ ] 1.2 App kernel + **DI container** + config loader (env, typed) _(Req 1.1, 1.4, 1.5)_ **[M]**
- [ ] 1.3 Front controller + **Router** (cached routes, params) + 404/JSON negotiation _(Req 1.3, 1.8)_ **[M]**
- [ ] 1.4 **Middleware pipeline**: RequestId, SecurityHeaders, CORS, Session(Redis), RateLimit _(Req 1.3, 14, 16)_ **[M]**
- [ ] 1.5 PDO connection manager (**RW primary / RO replica**), base Repository + UnitOfWork _(Req 16.3, 1.1)_ **[M]**
- [ ] 1.6 Structured JSON **logger** (Monolog) + correlation IDs; error handler _(Req 15.1)_ **[M]**
- [ ] 1.7 **Feature-flag** service (Redis-backed) _(Req 1.7)_ **[S]**
- [ ] 1.8 Migration runner + seeder (`console migrate/seed`) _(Req 22.1, 22.4)_ **[M]**
- [ ] 1.9 Dockerize (web + worker) + `docker-compose` (mysql, redis, search, minio, mailhog) _(Design §9)_ **[M]**

## Phase 2 — Identity, AuthN & RBAC _(Security foundation)_
- [ ] 2.1 Registration + **Argon2id** hashing + password policy/breach check _(Req 2.1)_ **[M]**
- [ ] 2.2 Login/logout, session regen, Redis session store, device/session list & revoke _(Req 2.2, 2.6, 2.9)_ **[M]**
- [ ] 2.3 Email verification + password reset (single-use expiring tokens) _(Req 2.3)_ **[M]**
- [ ] 2.4 **TOTP MFA** (enroll/verify), mandatory for privileged roles _(Req 2.4)_ **[M]**
- [ ] 2.5 OAuth 2.0 social login (Google) + account linking _(Req 2.5)_ **[S]**
- [ ] 2.6 Progressive lockout + auth rate limiting; generic error responses _(Req 2.7, 2.8)_ **[M]**
- [ ] 2.7 **RBAC**: roles/permissions, `user_role`, permission middleware + object-level **policies** _(Req 3)_ **[M]**
- [ ] 2.8 Back-office path isolation + MFA gate _(Req 3.4)_ **[M]**

## Phase 3 — Catalog & Product Listing _(Core)_
- [ ] 3.1 Product CRUD (rich text sanitized), categories/tags, slug + SEO/OG meta _(Req 4.1, 4.6)_ **[M]**
- [ ] 3.2 **S3 storage adapter**; media upload (thumbnail/gallery) → public bucket + CDN _(Req 4.2)_ **[M]**
- [ ] 3.3 Deliverable upload → **private bucket**, extension+MIME+size validation, checksum _(Req 4.3, 10.1)_ **[M]**
- [ ] 3.4 **Async AV scan** job; block purchase until `scan_status=clean` _(Req 4.4, 18.1)_ **[M]**
- [ ] 3.5 License tiers + discounts + scheduled sale windows _(Req 4.5)_ **[M]**
- [ ] 3.6 Draft autosave, file-size calc, difficulty/dependencies _(Req 4.7)_ **[S]**
- [ ] 3.7 Lifecycle state machine (draft→…→archived) + edit/resubmit _(Req 4.8, 4.9)_ **[M]**
- [ ] 3.8 **Versioning + changelog** (semver), current-version handling, buyer notify hook _(Req 5)_ **[M]**

## Phase 4 — Search, Discovery & Buyer UX
- [ ] 4.1 Search infra: OpenSearch/Meili client + index mapping _(Req 6.1)_ **[M]**
- [ ] 4.2 `IndexProduct` job on approve/version events (near-real-time) _(Req 6.1, 18.1)_ **[M]**
- [ ] 4.3 Full-text search: typo tolerance, ranking, highlighting _(Req 6.2)_ **[M]**
- [ ] 4.4 **Faceted filters** + sorting; **MySQL FULLTEXT fallback** _(Req 6.3, 6.4)_ **[M]**
- [ ] 4.5 Product detail page + version history + JSON-LD structured data _(Req 5.3, 20.3)_ **[M]**
- [ ] 4.6 Related/recommended + recently viewed _(Req 6.5)_ **[S]**
- [ ] 4.7 Wishlist (user + guest merge) _(Req 7.1)_ **[S]**
- [ ] 4.8 Reviews/ratings + **moderation queue** + verified-purchase + seller reply; aggregate via job _(Req 7.2–7.5)_ **[S]**

## Phase 5 — Cart, Checkout, Payments & Ledger
- [ ] 5.1 Persistent multi-item cart (user + guest merge) _(Req 8.1)_ **[M]**
- [ ] 5.2 Coupons/promotions engine (scope, limits, schedule) _(Req 8.2, 20.1)_ **[M]**
- [ ] 5.3 Tax/GST + multi-currency display _(Req 8.3)_ **[S]**
- [ ] 5.4 Idempotent order creation (idempotency keys) _(Req 8.4)_ **[M]**
- [ ] 5.5 **Payment abstraction** + Razorpay/Stripe/PayPal adapters (server-side, SAQ-A) _(Req 9.1, 9.2)_ **[M]**
- [ ] 5.6 **Signed webhook** intake + dedupe (`webhook_events`) + idempotent mark-paid _(Req 9.3, 14)_ **[M]**
- [ ] 5.7 Failed/abandoned payment handling (no entitlement) _(Req 9.4)_ **[M]**
- [ ] 5.8 **Double-entry ledger**: platform/seller accounts, commission, pending vs cleared _(Req 9.5, 11.4)_ **[M]**
- [ ] 5.9 Refunds (full/partial) + reversing ledger + entitlement policy _(Req 9.6)_ **[M]**
- [ ] 5.10 Settlement reconciliation report (Finance) _(Req 9.7)_ **[S]**

## Phase 6 — Entitlements & Secure Downloads
- [ ] 6.1 Entitlement creation on paid order + **license key** generation _(Req 10.2, 10.3)_ **[M]**
- [ ] 6.2 **Short-lived signed URL** (or stream proxy) with owner/license/limit/expiry checks _(Req 10.1, 10.2)_ **[M]**
- [ ] 6.3 License verification endpoint _(Req 10.3)_ **[S]**
- [ ] 6.4 Deny + audit on invalid/expired/over-limit; never expose paths _(Req 10.4, 10.5)_ **[M]**

## Phase 7 — Seller Console, KYC & Payouts
- [ ] 7.1 Seller onboarding + **KYC** gate before selling/payout _(Req 11.1)_ **[M]**
- [ ] 7.2 Seller dashboard (sales, revenue, wallet, views, conversion) from cached aggregates _(Req 11.2)_ **[M]**
- [ ] 7.3 Wallet balance (pending vs cleared) + `ClearSellerBalance` job after refund window _(Req 11.4, 18.3)_ **[M]**
- [ ] 7.4 Payout request → Finance workflow; optional gateway payout API _(Req 11.3, 11.5)_ **[M]**
- [ ] 7.5 Product analytics rollups (`product_daily_stats`) _(Req 11.2, 18.1)_ **[S]**

## Phase 8 — Back-Office / Admin Console
- [ ] 8.1 Moderation queue (product/version/review approve-reject + reason) _(Req 12.1)_ **[M]**
- [ ] 8.2 Featured/spotlight, category/tag CRUD, banners/content _(Req 12.2)_ **[M]**
- [ ] 8.3 User management (search, suspend, role assign, impersonate-with-audit) _(Req 12.3)_ **[M]**
- [ ] 8.4 Dispute/refund workflows + SLA timers _(Req 12.4, 21.2)_ **[M]**
- [ ] 8.5 Ops dashboards (GMV, top sellers, moderation backlog) _(Req 12.5)_ **[S]**
- [ ] 8.6 Settings + feature-flag management UI _(Req 1.7, 12)_ **[S]**

## Phase 9 — Async Jobs, Notifications & Scheduler
- [ ] 9.1 Queue driver + `Job` base (**retries/backoff/DLQ/idempotency**) + worker runtime _(Req 18.1, 18.2)_ **[M]**
- [ ] 9.2 Jobs: `GenerateInvoice` (DomPDF), `MakeThumbnails`, `DispatchWebhook` _(Req 9.5, 18.1, 19.4)_ **[M]**
- [ ] 9.3 Notifications: transactional email (SES/SMTP) templated + in-app; SMS (gated) _(Req 13.1–13.2)_ **[M]**
- [ ] 9.4 Notification preferences + unsubscribe _(Req 13.3)_ **[S]**
- [ ] 9.5 Scheduler (cron): sitemap, token/session cleanup, wallet clearing, reconciliation, reindex, reports _(Req 18.3)_ **[M]**

## Phase 10 — Public API & Integrations
- [x] 10.1 Versioned REST API `/api/v1` + JSON envelope + error codes _(Req 19.1)_ **[S]**
- [x] 10.2 API keys/OAuth tokens + scopes + per-key rate limits _(Req 19.2)_ **[S]**
- [x] 10.3 **OpenAPI 3** spec + contract tests _(Req 19.3)_ **[S]**
- [x] 10.4 Outbound signed webhooks (order paid, product approved, payout) + retries _(Req 19.4)_ **[S]**

## Phase 11 — Security Hardening & Compliance
- [x] 11.1 Audit: parameterized queries everywhere; SAST review _(Req 14.1)_ **[M]**
- [x] 11.2 Strict **CSP** + output encoding + security headers _(Req 14.2, 14.5)_ **[M]**
- [x] 11.3 CSRF tokens + SameSite cookies on all state changes _(Req 14.3)_ **[M]**
- [x] 11.4 Upload hardening (content-type + AV) verified end-to-end _(Req 14.4)_ **[M]**
- [x] 11.5 TLS/HSTS enforcement; secrets in **Vault/secrets manager** _(Req 14.5, 14.6)_ **[M]**
- [x] 11.6 Global **rate limiting** (auth/API/expensive) _(Req 14.7)_ **[M]**
- [x] 11.7 **GDPR/DPDP**: consent, data export, right-to-erasure + retention/anonymization jobs _(Req 14.8)_ **[M]**
- [x] 11.8 **Audit logging** for privileged & money actions (immutable) _(Req 3.6, 15.5)_ **[M]**
- [x] 11.9 Dependency + SAST scanning in CI (DAST scaffolded) _(Req 14.9, 23.1)_ **[M]**

## Phase 12 — Observability & Reliability
- [x] 12.1 Ship logs to central store (ELK/Loki) with request IDs _(Req 15.1)_ **[M]**
- [x] 12.2 Metrics (RED + KPIs) → Prometheus/Grafana dashboards _(Req 15.2)_ **[M]**
- [x] 12.3 Distributed tracing (OpenTelemetry) web→queue→jobs _(Req 15.3)_ **[S]**
- [x] 12.4 `/healthz` + `/readyz` (DB/cache/queue/search) _(Req 15.4)_ **[M]**
- [x] 12.5 Alerting (error rate, P95 latency, queue backlog, payment failures, DLQ) + on-call _(Req 15.6)_ **[M]**

## Phase 13 — Performance, Scale & HA
- [x] 13.1 Redis caching (object/fragment/page) + **tag-based invalidation** _(Req 16.1)_ **[M]**
- [x] 13.2 CDN for assets/media + cache headers + fingerprinting _(Req 16.2)_ **[M]**
- [x] 13.3 Route reads to **replicas**; fix N+1; keyset pagination _(Req 16.3, 16.4)_ **[M]**
- [x] 13.4 Stateless app tier behind LB; independent worker scaling _(Req 17.1, 17.2)_ **[M]**
- [x] 13.5 DB replication + documented failover _(Req 17.3)_ **[M]**
- [x] 13.6 **Load/performance testing** (k6/JMeter) vs SLOs; tune to P95 ≤ 400ms _(Req 16.5, 24.3)_ **[M]**
- [x] 13.7 Backup/restore + **DR** drill (RPO ≤ 15m, RTO ≤ 1h) _(Req 17.5, 22.2)_ **[M]**

## Phase 14 — CI/CD & Quality Gates
- [ ] 14.1 CI: lint (PHPCS) → static analysis (PHPStan/Psalm) → tests → build → security scan _(Req 23.1)_ **[M]**
- [ ] 14.2 Branch protection: required checks + review _(Req 23.2)_ **[M]**
- [ ] 14.3 CD: build Docker image → deploy staging → approval → production; gated migrations _(Req 23.3)_ **[M]**
- [ ] 14.4 Zero/low-downtime rolling deploy + rollback _(Req 23.4)_ **[M]**

## Phase 15 — Testing Strategy
- [ ] 15.1 Unit tests (PHPUnit) for domain/services + coverage threshold on critical modules _(Req 24.1)_ **[M]**
- [ ] 15.2 Integration tests (DB/cache/gateway sandboxes) _(Req 24.2)_ **[M]**
- [ ] 15.3 E2E core flows (register → buy → download) _(Req 24.2)_ **[M]**
- [ ] 15.4 Security tests (SQLi/XSS/CSRF/authz) _(Req 24.3)_ **[M]**

## Phase 16 — Launch & Post-Launch _(Ongoing)_
- [ ] 16.1 Beta with limited verified sellers + feedback loop **[M]**
- [ ] 16.2 Bug-bash + hardening pass **[M]**
- [ ] 16.3 Public launch + marketing; analytics (GA4/self-hosted) _(Req 20)_ **[M]**
- [ ] 16.4 i18n/localization rollout _(Req 20.4)_ **[C]**
- [ ] 16.5 Continuous optimization (query tuning, cache hit-rate, cost) _(Req 16, 17)_ **[S]**
- [ ] 16.6 Roadmap: affiliate expansion, more gateways, partner API GA _(Req 19, 20.2)_ **[S]**

---

## Timeline Estimate (enterprise scope)
| Phase | Duration | Focus |
|-------|----------|-------|
| 0 | 2–3 weeks | Discovery, ADRs, threat model, design system |
| 1 | 3–4 weeks | Platform core, DI, router, Docker, migrations |
| 2 | 3–4 weeks | Identity, MFA, RBAC/policies |
| 3 | 4–5 weeks | Catalog, S3 uploads, AV scan, versioning |
| 4 | 3–4 weeks | Search engine, facets, reviews, wishlist |
| 5 | 4–5 weeks | Cart, payments, ledger, refunds |
| 6 | 1–2 weeks | Entitlements & secure downloads |
| 7 | 2–3 weeks | Seller console, KYC, payouts |
| 8 | 2–3 weeks | Back-office/admin |
| 9 | 2–3 weeks | Queue, notifications, scheduler |
| 10 | 2–3 weeks | Public API & webhooks |
| 11 | 3 weeks | Security hardening & compliance |
| 12 | 2 weeks | Observability |
| 13 | 2–3 weeks | Performance, scale, HA, DR |
| 14 | 1–2 weeks | CI/CD & quality gates |
| 15 | ongoing | Testing (runs throughout) |
| 16 | ongoing | Launch & post-launch |

**Total:** ~9–12 months for full enterprise scope with a **4–6 person** team (backend, frontend, DevOps/SRE, QA). A leaner MVP (Must-only, phases 0–8 core) is achievable in ~5–6 months.

> **Key notes:**
> - Custom PHP (no full framework) → security, DI, routing, and reliability are engineered in-house; **static analysis + tests + reviews are non-negotiable quality gates**.
> - Prioritize by MoSCoW: ship **[M]** for a secure, scalable MVP; layer **[S]/[C]** post-launch.
> - Money is handled via a **double-entry ledger** and idempotent, signed webhooks — never ad-hoc updates.
> - Card data never touches app servers (gateway-hosted) to keep **PCI scope at SAQ-A**.
