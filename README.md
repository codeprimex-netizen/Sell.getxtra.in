# Sell.getxtra.in

Enterprise digital-products marketplace — buy and sell themes, plugins, templates,
UI kits and code, with instant, secure downloads and licensing.

Built in **custom Core PHP 8.x (no framework)** on **MySQL/PDO**, with a layered,
dependency-injected, PSR-4 architecture. Security, DI, routing, and reliability are
engineered in-house and covered by automated tests.

> **Developer:** ANSHU E-MITRA AND CSC CENTER · **URL:** https://sell.getxtra.in

---

## Features

- **Identity & RBAC** — registration, login, email verification, password reset,
  TOTP MFA (mandatory for privileged roles), device/session management, roles &
  permissions with object-level policies.
- **Catalog** — products with categories/tags, license tiers, versioning &
  changelogs, media (thumbnail + **screenshot gallery**), SEO slugs & JSON-LD,
  a draft→pending→approved lifecycle, and async AV scanning of deliverables.
- **Discovery** — search (Meilisearch or MySQL FULLTEXT fallback), faceted
  filters, reviews & ratings with moderation, wishlist, related & recently viewed.
- **Commerce** — cart, checkout, coupons, Razorpay/Stripe/offline gateways,
  a **double-entry ledger**, idempotent signed webhooks, refunds, invoices.
- **Fulfilment** — entitlements, signed/expiring secure download links, license
  verification API.
- **Seller & finance** — seller console, KYC, wallet, payouts on shared rails.
- **Back-office** — moderation, users, categories, coupons, disputes, settings,
  feature flags, reports.
- **Platform** — async jobs/queue + scheduler, notifications (in-app + email),
  a versioned **public REST API** (API keys + scopes + rate limits, OpenAPI 3)
  and **outbound signed webhooks**.
- **Security & compliance** — strict nonce CSP, CSRF, secrets manager,
  global + per-key rate limiting, **GDPR/DPDP** consent/export/erasure, immutable
  audit trail, SAST + dependency scanning in CI.
- **Observability** — structured JSON logs, Prometheus metrics + Grafana,
  OpenTelemetry tracing, `/healthz` + `/readyz`, alerting.
- **Performance & HA** — Redis caching with tag invalidation, CDN + asset
  fingerprinting, read replicas, keyset pagination, stateless tier, DR runbooks.
- **Growth** — i18n (en/hi), SEO sitemap/robots, privacy-aware analytics, and an
  **affiliate/referral program** with commission ledgering + payouts.

## Tech stack

- PHP 8.2–8.4 (custom framework), MySQL 8 (primary + read replica), Redis,
  S3-compatible object storage + CDN, Meilisearch (optional).
- Docker, Kubernetes, Prometheus/Grafana/Loki, GitHub Actions CI/CD.

## Requirements

- PHP ≥ 8.2 with `pdo_mysql`, `mbstring`, `intl` (and `redis` in production)
- Composer 2, MySQL 8, Redis (production)

## Installation

The fastest way to provision a fresh deployment is the built-in **installer**,
which checks requirements, creates the database, generates `APP_KEY`, writes
`.env`, runs migrations + seeders, and creates your first admin account.

**Option A — Web wizard.** After `composer install`, browse to
`https://sell.getxtra.in/install.php` and follow the steps
(welcome → requirements → database → configuration → finish). When it
completes, **delete `public/install.php`** for security (a
`storage/installed.lock` file also prevents re-runs).

**Option B — Headless CLI.**

```bash
composer install
php bin/console install \
  --db-host=127.0.0.1 --db-port=3306 --db-name=sell_getxtra \
  --db-user=root --db-pass=secret \
  --url=https://sell.getxtra.in --name="Sell.getxtra.in" \
  --admin-name="Admin" --admin-email=admin@sell.getxtra.in \
  --admin-password=change-me-please
```

## Quick start (manual)

```bash
cp .env.example .env
# set APP_KEY: php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
composer install
php bin/console migrate      # apply migrations
php bin/console seed         # roles/permissions, feature flags, categories, coupons
composer serve               # http://127.0.0.1:8000
```

Run the worker and scheduler alongside the web server:

```bash
php bin/console queue:work      # process queued jobs
php bin/console schedule:run    # run due scheduled tasks (invoke every minute via cron)
```

With Docker Compose (LB + web + workers + Redis + MySQL primary/replica):

```bash
docker compose -f deploy/ha/docker-compose.yml up --build
```

## Testing & quality

```bash
composer test        # all offline suites (php bin/run-tests.php)
composer lint        # PHPCS (PSR-12)
composer analyse     # PHPStan
composer syntax      # php -l across the tree
```

- Fast offline suites live in `tests/*.php` (run via `bin/run-tests.php`); PHPUnit
  unit/integration/e2e/security suites live in `tests/{Unit,Integration,E2E,Security}`.
- CI (`.github/workflows/ci.yml`) runs syntax → PHPCS → PHPStan → tests → build;
  `security.yml` runs dependency audit + SAST. See `docs/BRANCH_PROTECTION.md`.

## Console

```
php bin/console install [--db-host= --db-name= --db-user= --db-pass= --url= --admin-email= --admin-password= --force]
php bin/console migrate | rollback | seed
php bin/console queue:work [queue]
php bin/console schedule:run [--force]
php bin/console clear-balances
```

## Deployment & ops

- Production image: `deploy/Dockerfile` (multi-stage, PHP-FPM + OPcache).
- Kubernetes manifests + zero-downtime rollout/rollback: `deploy/k8s/`, `deploy/ROLLBACK.md`.
- CD pipeline (build → staging → approval → production, gated migrations): `.github/workflows/cd.yml`.
- Observability config (Prometheus/alerts/Grafana/Loki) + on-call runbook: `deploy/observability/`.
- DB replication/failover: `deploy/ha/FAILOVER.md`. Backups + DR: `deploy/backup/`.
- Load test (SLO P95 ≤ 400ms): `deploy/perf/k6-load-test.js`.

## Project layout

```
src/
  Bootstrap/      App bootstrap, DI container
  Config/         typed config, env, routes
  Domain/         entities + repository interfaces (framework-free)
  Application/    use-case services
  Infrastructure/ PDO repos, cache, queue, storage, observability, security
  Http/           kernel, router, middleware, controllers, views glue
resources/        views, lang catalogs, openapi.json
database/         migrations
deploy/           Dockerfile, k8s, ha, observability, perf, backup
tests/            offline suites + PHPUnit + fakes
```

## Key design notes

- **Custom PHP (no framework):** security, DI, routing, and reliability are
  engineered in-house — static analysis + tests + reviews are non-negotiable gates.
- **Money** is handled via a **double-entry ledger** and idempotent, signed
  webhooks — never ad-hoc balance updates.
- **Card data never touches app servers** (gateway-hosted) to keep PCI scope at SAQ-A.

## License

Proprietary © ANSHU E-MITRA AND CSC CENTER.
