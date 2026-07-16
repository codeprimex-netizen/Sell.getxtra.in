# Implementation Tasks — Sell.getxtra.in

**Project:** Sell.getxtra.in — Digital Products Marketplace
**Base URL:** https://www.sell.getxtra.in
**Developer:** ANSHU E-MITRA AND CSC CENTER
**Stack:** Core PHP 8.x (custom MVC), MySQL (PDO), Bootstrap/Tailwind CDN, vanilla JS/jQuery

Each task references the requirement IDs from `REQUIREMENTS.md` it fulfills. Check items off as they are completed.

---

## Phase 1 — Planning & Research
- [ ] 1.1 Competitor analysis (CodeCanyon, Envato, Gumroad) — features & pricing
- [ ] 1.2 Finalize target audience and monetization model (commission %, fees)
- [ ] 1.3 Confirm tech stack & server (PHP 8.x, MySQL, Apache/Nginx) _(Req 1)_
- [ ] 1.4 Decide file storage: local `storage/` vs AWS S3 _(Req 9)_
- [ ] 1.5 Figma wireframes: homepage, product page, seller & admin dashboards

## Phase 2 — Core Backend Setup
- [ ] 2.1 Init repo, `composer.json`, `.env.example`, `.gitignore` _(Req 1)_
- [ ] 2.2 Create MVC folder structure (`app/`, `public/`, `storage/`, `database/`) _(Req 1)_
- [ ] 2.3 Front controller `public/index.php` + `.htaccess` clean URLs _(Req 1.2)_
- [ ] 2.4 Build custom `Router` with param support + 404 handling _(Req 1.2, 1.4)_
- [ ] 2.5 PDO `Database` singleton with exception mode + config loader _(Req 1.3, 1.5)_
- [ ] 2.6 Base `Controller`, `Model`, `Request`, `Session`, `Validator`, helpers _(Req 1, 12.1)_
- [ ] 2.7 Apply full `database/schema.sql` (all tables from DESIGN.md) _(Req 1.5)_
- [ ] 2.8 Auth: register/login/logout, `password_hash`/`verify`, session regen _(Req 2)_
- [ ] 2.9 RBAC middleware (buyer/seller/admin) + 403/redirect handling _(Req 3)_

## Phase 3 — Product Listing Module (Core Focus)
- [ ] 3.1 Product create form + handler (title, desc, category, tags, tech stack) _(Req 4.1)_
- [ ] 3.2 `UploadService`: media upload (thumbnail, screenshots, demo) via `move_uploaded_file()` _(Req 4.2)_
- [ ] 3.3 Code/zip upload with extension + MIME + size validation _(Req 4.3, 12.4)_
- [ ] 3.4 Documentation file upload _(Req 4.4)_
- [ ] 3.5 Pricing + license tiers + discount fields _(Req 4.5)_
- [ ] 3.6 `SlugService` auto-slug + SEO meta title/description _(Req 4.6)_
- [ ] 3.7 Auto file-size calc, dependencies, difficulty dropdown _(Req 4.7)_
- [ ] 3.8 Status flow ENUM (draft/pending/approved/rejected) _(Req 4.8)_
- [ ] 3.9 Edit & resubmit (pre-filled form from DB) _(Req 4.9)_
- [ ] 3.10 Version + changelog upload, current-version handling _(Req 5)_

## Phase 4 — Browsing & Buyer Experience
- [ ] 4.1 Product listing page (approved only) _(Req 6.6)_
- [ ] 4.2 Keyword search (LIKE / FULLTEXT) _(Req 6.1)_
- [ ] 4.3 Dynamic parameterized filters: category, price, tech stack, rating _(Req 6.2, 12.1)_
- [ ] 4.4 Product detail page (template/include) with version history _(Req 6.3, 5.2)_
- [ ] 4.5 Related products by category/tags _(Req 6.4)_
- [ ] 4.6 Wishlist (user-linked + guest session merge) _(Req 7.1, 7.2)_
- [ ] 4.7 Reviews & ratings + avg recalculation _(Req 7.3, 7.4, 7.5, 6.5)_

## Phase 5 — Payments & Transactions
- [ ] 5.1 Checkout → create order + order_items _(Req 8.1)_
- [ ] 5.2 `PaymentService` gateway integration (Razorpay/Stripe SDK) _(Req 8.1)_
- [ ] 5.3 Webhook handler with signature verification → mark paid _(Req 8.2, 8.7)_
- [ ] 5.4 Handle failed/abandoned payments (no download grant) _(Req 8.3)_
- [ ] 5.5 `InvoiceService` PDF generation (TCPDF/DomPDF) _(Req 8.4)_
- [ ] 5.6 `LicenseService` license key generation + storage _(Req 8.6)_
- [ ] 5.7 `DownloadTokenService` token-based expiring links _(Req 8.5)_
- [ ] 5.8 Secure download controller (validate token/owner/expiry, stream file) _(Req 9)_

## Phase 6 — Seller Tools
- [ ] 6.1 Seller dashboard scoped by `seller_id` _(Req 10.1)_
- [ ] 6.2 Earnings calculation (SUM over completed items) _(Req 10.2)_
- [ ] 6.3 Payout request system (admin-processed) _(Req 10.3)_
- [ ] 6.4 Analytics: view counter + conversion rate _(Req 10.4)_

## Phase 7 — Admin Panel
- [ ] 7.1 Separate admin login + role check on every request _(Req 11.5, 3.4)_
- [ ] 7.2 Product approval/rejection with reason _(Req 11.1)_
- [ ] 7.3 Featured product toggle _(Req 11.2)_
- [ ] 7.4 User management CRUD _(Req 11.3)_
- [ ] 7.5 Category/tag management CRUD _(Req 11.3)_
- [ ] 7.6 Refund/dispute handling + status tracking _(Req 11.4)_

## Phase 8 — Trust & Security
- [ ] 8.1 Audit: prepared statements everywhere (no string-built SQL) _(Req 12.1)_
- [ ] 8.2 Output escaping helper applied across all views (XSS) _(Req 12.2)_
- [ ] 8.3 CSRF token system + middleware on all state-changing forms _(Req 12.3)_
- [ ] 8.4 Harden file-upload validation (MIME + whitelist, optional malware scan) _(Req 12.4)_
- [ ] 8.5 SSL/HTTPS enforcement + secure/HttpOnly/SameSite cookies + HSTS _(Req 12.5)_
- [ ] 8.6 Ensure product files served only via PHP, real paths hidden _(Req 9.1, 9.4)_
- [ ] 8.7 Login rate limiting / throttle _(Req 12.6)_

## Phase 9 — Growth Features
- [ ] 9.1 Coupon system + checkout validation (expiry/usage) _(Req 13.1)_
- [ ] 9.2 Affiliate/referral tracking + attribution _(Req 13.2)_
- [ ] 9.3 Dynamic meta tags + `sitemap.xml` generator + clean URLs _(Req 13.3)_
- [ ] 9.4 Support ticket system (tickets + messages) _(Req 13.4)_

## Phase 10 — Testing & Launch
- [ ] 10.1 Security testing: SQL injection + XSS + CSRF _(Req 14.1)_
- [ ] 10.2 PHPUnit tests for critical logic (auth, pricing, tokens) _(Req 14.2)_
- [ ] 10.3 Beta launch with limited sellers
- [ ] 10.4 Bug fixing pass
- [ ] 10.5 Public launch + marketing

## Phase 11 — Post-Launch (Ongoing)
- [ ] 11.1 Google Analytics integration _(Req 14.3)_
- [ ] 11.2 Feature updates from feedback
- [ ] 11.3 MySQL indexing + query caching optimization _(Req 14.4)_
- [ ] 11.4 Scale: CDN + load balancer as traffic grows _(NFR: Scalability)_

---

## Timeline Estimate
| Phase | Duration | Focus |
|-------|----------|-------|
| 1 | 1–2 weeks | Planning & research |
| 2 | 2–3 weeks | MVC + DB + auth foundation |
| 3 | 3–4 weeks | Product listing (core) |
| 4 | 2–3 weeks | Browsing & buyer UX |
| 5 | 2 weeks | Payments & downloads |
| 6 | 2 weeks | Seller tools |
| 7 | 2 weeks | Admin panel |
| 8 | 2 weeks | Security hardening |
| 9 | 2–3 weeks | Growth features |
| 10 | 1–2 weeks | Testing & launch |
| 11 | Ongoing | Post-launch |

**Total:** ~5–6 months with a 2–3 PHP developer team.

> **Key note:** Custom PHP (no Laravel/CodeIgniter) means security and structure are handled manually — prepared statements, CSRF tokens, and input validation are mandatory everywhere.
