# Requirements — Sell.getxtra.in

**Project:** Sell.getxtra.in — Digital Products Marketplace
**Base URL:** https://www.sell.getxtra.in
**Developer:** ANSHU E-MITRA AND CSC CENTER
**Tech Stack:** Core PHP 8.x (custom MVC, no framework), MySQL (PDO), Bootstrap/Tailwind (CDN), vanilla JS / jQuery, Apache/Nginx

---

## 1. Introduction

Sell.getxtra.in is a self-hosted digital marketplace where sellers list and sell digital products (scripts, themes, templates, code, docs) and buyers browse, purchase, and download them securely. The platform is built on custom Core PHP with a hand-rolled MVC structure and MySQL, so security, routing, and structure are handled manually.

### Actors / Roles
- **Guest** — unauthenticated visitor; can browse and search.
- **Buyer** — registered user; can purchase, download, review, wishlist.
- **Seller** — registered user with selling enabled; can list products, view earnings, request payouts.
- **Admin** — platform operator; approves products, manages users/categories, handles refunds/disputes.

### EARS Notation Key
- **Ubiquitous:** "The system SHALL ..."
- **Event-driven:** "WHEN <trigger>, the system SHALL ..."
- **State-driven:** "WHILE <state>, the system SHALL ..."
- **Optional:** "WHERE <feature included>, the system SHALL ..."
- **Unwanted:** "IF <condition>, THEN the system SHALL ..."

---

## 2. Requirements

### Requirement 1 — Platform Foundation & Routing
**User Story:** As a developer, I want a custom MVC foundation with clean routing, so that the application is organized and URLs are SEO-friendly.

#### Acceptance Criteria
1. The system SHALL implement a custom MVC structure with separate Models, Views, Controllers, and Config directories.
2. The system SHALL route all requests through a single front controller (index.php) using a custom router with `.htaccess` clean-URL rewriting.
3. The system SHALL load database credentials and environment settings from a central config file not committed with real secrets.
4. IF a route does not match any defined pattern, THEN the system SHALL render a 404 error page.
5. The system SHALL connect to MySQL using PDO with exception error mode enabled.

### Requirement 2 — Authentication & Sessions
**User Story:** As a user, I want to register and log in securely, so that my account and data are protected.

#### Acceptance Criteria
1. WHEN a user registers, the system SHALL hash the password using `password_hash()` (bcrypt) before storing it.
2. WHEN a user submits valid login credentials, the system SHALL verify with `password_verify()` and create an authenticated session.
3. The system SHALL regenerate the session ID on login to prevent session fixation.
4. IF login credentials are invalid, THEN the system SHALL reject the attempt and SHALL NOT reveal whether the email or password was wrong.
5. WHEN a user logs out, the system SHALL destroy the session and clear the session cookie.
6. The system SHALL enforce a minimum password strength and validate email format on registration.
7. WHERE email verification is enabled, the system SHALL require account confirmation before selling is allowed.

### Requirement 3 — Role-Based Access Control (RBAC)
**User Story:** As the platform, I want role-based access, so that buyers, sellers, and admins only reach permitted areas.

#### Acceptance Criteria
1. The system SHALL store a role (buyer/seller/admin) for every user.
2. WHILE a session is unauthenticated, the system SHALL redirect protected routes to the login page.
3. IF a buyer attempts to access seller-only or admin-only routes, THEN the system SHALL deny access with a 403 response.
4. The admin panel SHALL require a separate authenticated session with an admin role check on every request.

### Requirement 4 — Product Listing Module
**User Story:** As a seller, I want to create and manage product listings with media and files, so that buyers can discover and purchase my products.

#### Acceptance Criteria
1. WHEN a seller submits a product, the system SHALL capture title, description, category, tags, and tech stack.
2. WHEN a seller uploads media (thumbnail, screenshots, demo link/video), the system SHALL store files via `move_uploaded_file()` after validation.
3. WHEN a seller uploads a code/zip file, the system SHALL validate file type (extension whitelist), MIME type, and enforce a maximum file size.
4. The system SHALL allow uploading a documentation file per product.
5. The system SHALL support pricing with single or multiple license tiers and optional discount fields.
6. WHEN a product is saved, the system SHALL auto-generate a unique SEO slug and allow meta title/description entry.
7. The system SHALL auto-calculate and store the uploaded file size and allow entry of dependencies and difficulty level.
8. The system SHALL manage product status via an ENUM (`draft`, `pending`, `approved`, `rejected`).
9. WHEN a rejected product is edited, the system SHALL pre-fill the form from the database and allow resubmission (status → `pending`).
10. IF an uploaded file fails validation, THEN the system SHALL reject the upload and show a clear error without saving the product.

### Requirement 5 — Version Control & Changelog
**User Story:** As a seller, I want to publish product updates with a changelog, so that buyers can track versions.

#### Acceptance Criteria
1. WHEN a seller uploads a new version, the system SHALL store a version number and changelog entry linked to the product.
2. The system SHALL display the version history on the product detail page.
3. WHEN a new version is approved, the system SHALL make it the current downloadable version for existing buyers.

### Requirement 6 — Browsing, Search & Filters
**User Story:** As a buyer, I want to search and filter products, so that I can quickly find relevant items.

#### Acceptance Criteria
1. The system SHALL provide keyword search across product title/description using MySQL LIKE or FULLTEXT indexing.
2. The system SHALL support filtering by category, price range, tech stack, and rating via dynamically built parameterized WHERE clauses.
3. The system SHALL render a product detail page using a template/include approach.
4. The system SHALL display related products based on category/tags.
5. WHILE viewing a product, the system SHALL show aggregate rating computed via a JOIN/AVG query over reviews.
6. The system SHALL only list products with status `approved` to guests and buyers.

### Requirement 7 — Wishlist, Reviews & Ratings
**User Story:** As a buyer, I want to save products and leave reviews, so that I can track interests and share feedback.

#### Acceptance Criteria
1. WHEN a buyer adds a product to the wishlist, the system SHALL persist it linked to the user account.
2. IF a guest uses the wishlist, THEN the system SHALL store it in the session and offer to merge on login.
3. WHEN a buyer submits a review, the system SHALL store a rating (1–5) and comment in a reviews table.
4. IF a user has not purchased a product, THEN the system SHALL prevent them from posting a verified review (configurable).
5. The system SHALL recalculate the product average rating whenever a review is added or removed.

### Requirement 8 — Payments & Transactions
**User Story:** As a buyer, I want to pay securely and receive my files, so that I can obtain the products I bought.

#### Acceptance Criteria
1. WHEN a buyer checks out, the system SHALL create an order record and initiate payment via a PHP gateway SDK (Razorpay/Stripe) using server-side API calls.
2. WHEN a payment is confirmed by the gateway callback/webhook, the system SHALL mark the order paid and grant download access.
3. IF a payment fails or is abandoned, THEN the system SHALL keep the order in a `pending`/`failed` state and SHALL NOT grant downloads.
4. WHEN an order is completed, the system SHALL generate an invoice (PDF via TCPDF/DomPDF).
5. WHEN access is granted, the system SHALL generate a unique, expiring, token-based download link.
6. The system SHALL generate and store a license key per purchased license.
7. The system SHALL verify gateway webhook signatures before trusting payment status.

### Requirement 9 — Secure Downloads
**User Story:** As the platform, I want downloads served securely, so that files cannot be accessed without a valid purchase.

#### Acceptance Criteria
1. The system SHALL store product files outside the public web root or protect them via server rules.
2. WHEN a buyer requests a download, the system SHALL validate the token, ownership, and expiry before streaming the file via a PHP script with appropriate headers.
3. IF a download token is expired or invalid, THEN the system SHALL deny access.
4. The system SHALL never expose the real file path in URLs or HTML.

### Requirement 10 — Seller Tools & Dashboard
**User Story:** As a seller, I want a dashboard with earnings and analytics, so that I can manage my business.

#### Acceptance Criteria
1. WHILE authenticated as a seller, the system SHALL display only that seller's data (scoped by `seller_id`).
2. The system SHALL calculate total and per-product earnings using SUM queries over completed transactions.
3. WHEN a seller requests a payout, the system SHALL create a payout request processed manually by an admin initially.
4. The system SHALL track product views (counter incremented on detail-page load) and compute conversion rate.

### Requirement 11 — Admin Panel
**User Story:** As an admin, I want a management panel, so that I can moderate the marketplace.

#### Acceptance Criteria
1. WHEN an admin reviews a pending product, the system SHALL allow approval or rejection with a reason field, updating the status column.
2. The system SHALL allow toggling a product as featured (boolean column).
3. The system SHALL provide CRUD operations for users, categories, and tags.
4. WHEN a refund/dispute is raised, the system SHALL track its status through a dispute table.
5. The admin panel SHALL be reachable only via a separate login with an admin role check.

### Requirement 12 — Security & Data Integrity
**User Story:** As the platform owner, I want the application hardened, so that user data and files are protected.

#### Acceptance Criteria
1. The system SHALL use PDO prepared statements for all database queries to prevent SQL injection.
2. The system SHALL sanitize and escape all output to prevent XSS.
3. The system SHALL implement CSRF tokens on all state-changing forms and validate them server-side.
4. The system SHALL validate uploads by extension whitelist and MIME type; WHERE malware scanning is enabled, the system SHALL scan uploaded files.
5. The system SHALL be served over HTTPS (SSL) with secure, HttpOnly session cookies.
6. IF repeated failed logins are detected, THEN the system SHALL apply rate limiting / throttling.

### Requirement 13 — Growth Features
**User Story:** As the platform, I want coupons, referrals, SEO, and support, so that the marketplace can grow and retain users.

#### Acceptance Criteria
1. WHEN a valid coupon is applied at checkout, the system SHALL reduce the order total per the coupon rules and validate expiry/usage limits.
2. WHERE the affiliate program is enabled, the system SHALL track referrals via a referral code and attribute qualifying purchases.
3. The system SHALL output dynamic meta tags and auto-generate `sitemap.xml` via a PHP script, using clean URLs.
4. WHEN a user opens a support ticket, the system SHALL store it in a ticketing table and allow status updates and replies.

### Requirement 14 — Testing, Launch & Post-Launch
**User Story:** As the team, I want testing and observability, so that the launch is stable and maintainable.

#### Acceptance Criteria
1. The system SHALL be tested for SQL injection and XSS before launch.
2. WHERE automated tests are added, the system SHALL use PHPUnit for critical logic.
3. WHERE analytics is enabled, the system SHALL integrate Google Analytics.
4. The system SHALL index frequently queried MySQL columns and support query caching for performance as traffic grows.

---

## 3. Non-Functional Requirements
- **Performance:** Product listing and search pages SHOULD respond within ~500ms under normal load; MySQL indexes on searchable/filterable columns.
- **Scalability:** Architecture SHOULD allow adding a CDN for static assets and a load balancer as traffic grows.
- **Security:** OWASP Top 10 mitigations (injection, XSS, CSRF, broken auth, insecure file upload) are mandatory given the no-framework stack.
- **Compatibility:** Latest 2 versions of major browsers; responsive on mobile via Bootstrap/Tailwind.
- **Maintainability:** Consistent MVC layering; reusable helper functions for validation, CSRF, and DB access.
- **Backup:** Regular MySQL and uploaded-file backups.
