# Audit Findings Register

Use this file as the live register during audit execution.

## Status Legend

- `open`
- `in_progress`
- `resolved`
- `accepted_risk`
- `deferred`
- `false_positive`

## Findings

#### AUD-001

- Status: `resolved`
- Category: `security`
- Severity: `high`
- Location: `src/Support/Http/Request.php:220`, `src/Support/Http/Middleware.php:715`, `src/Support/Security/LoginRateLimiter.php:158`
- Summary: IP-based security decisions trust `X-Forwarded-For` and `X-Real-IP` from any client without a trusted-proxy boundary.
- Evidence: `Request::getClientIp()`, `Middleware::getClientIp()`, and `LoginRateLimiter::clientIp()` all prefer client-supplied forwarding headers over `REMOTE_ADDR` with no proxy allowlist or server-side normalization.
- Impact: A client can spoof its apparent IP address to bypass IP-based login throttling, distort audit and approval logs, and weaken impersonation hijack detection that depends on request IP matching.
- Recommended fix: Only trust forwarding headers when the request comes from a configured trusted proxy or when the web server has already normalized client IP. Otherwise use `REMOTE_ADDR`. Centralize the logic so rate limiting, logging, and session security all use the same trusted source.
- Actual fix: Added shared trusted-proxy-aware resolution in `src/Support/Http/IpAddressResolver.php` and routed `Request`, `Middleware`, and `LoginRateLimiter` through it. Forwarded headers are now only honored when `REMOTE_ADDR` matches `TRUSTED_PROXIES`.
- Verification: `php tests/IpAddressResolverTest.php`; `php -l src/Support/Http/IpAddressResolver.php`; `php -l src/Support/Http/Request.php`; `php -l src/Support/Http/Middleware.php`; `php -l src/Support/Security/LoginRateLimiter.php`
- Residual risk: Deployments behind a reverse proxy must set `TRUSTED_PROXIES` correctly or the app will fall back to the proxy IP rather than the originating client IP.
- Re-verified: `2026-05-10` (Phase 1) — `IpAddressResolver` remains the single source for `Request`, `Middleware`, and `LoginRateLimiter`. Original fix holds. Related new issue with leftmost-XFF parsing is opened as `AUD-072`, not a regression of this finding.

#### AUD-002

- Status: `resolved`
- Category: `security`
- Severity: `high`
- Location: `src/Support/Auth/PasswordResetRepository.php:22`, `src/Support/Auth/EmailVerificationRepository.php:22`
- Summary: Password reset tokens and email verification tokens are stored and looked up in plaintext.
- Evidence: `createToken()` writes raw `token` values to `password_resets` and `email_verifications`, and `findValidToken()` queries those same raw values directly instead of hashing them.
- Impact: Any read access to the application database exposes still-valid reset and verification tokens, which can be used directly for account takeover or account activation without needing the original email message.
- Recommended fix: Store only a hash of each token, compare by hashing the presented token, and invalidate or rotate any existing plaintext tokens during rollout. Apply the same approach consistently to all bearer-style recovery tokens.
- Actual fix: New password reset and email verification tokens are now stored as SHA-256 hashes, while lookup and mark-used paths accept either the legacy plaintext form or the new hashed form to preserve compatibility during rollout.
- Verification: `php -l src/Support/Auth/PasswordResetRepository.php`; `php -l src/Support/Auth/EmailVerificationRepository.php`; `php -l tests/AuthTokenRepositorySecurityTest.php`; `php tests/AuthTokenRepositorySecurityTest.php` returned `SKIPPED: pdo_sqlite extension is not available.`
- Residual risk: Legacy plaintext rows already stored in the database remain plaintext until they expire or are explicitly rotated/migrated.
- Re-verified: `2026-05-10` (Phase 1) — both repositories still hash on write and accept either form on lookup. Holds.

#### AUD-003

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `src/Services/Tracking/TrackingService.php:42`, `database/migrations/055_job_tracking_links.sql:3`, `src/Services/Invoice/InvoiceService.php:457`, `database/migrations/018_invoice_public_tokens.sql:34`
- Summary: Public access tokens for tracking links and invoice links are persisted in plaintext rather than hashed-at-rest.
- Evidence: `job_tracking_links` stores a raw `token` column and `TrackingService` inserts and queries it directly. Invoice public access also resolves by direct `public_token` equality, and the migration seeds public invoice tokens directly into the `invoices` table.
- Impact: Database read exposure immediately yields active tracking links and public invoice links. Those tokens grant direct access to customer/job/invoice data without any second factor.
- Recommended fix: Store only token hashes for public bearer links, compare on hashed lookup, and rotate existing live tokens. Keep only short non-sensitive display codes in plaintext when necessary.
- Actual fix: New invoice public tokens and job tracking link tokens are now stored as SHA-256 hashes in the existing columns. Lookup and revoke paths accept either the legacy plaintext form or the new hashed form so existing live links continue to work during rollout.
- Verification: `php tests/PublicLinkHashingContractTest.php`; `php -l src/Services/Invoice/InvoiceService.php`; `php -l src/Services/Tracking/TrackingService.php`; `php -l tests/PublicLinkHashingContractTest.php`
- Residual risk: Legacy plaintext token rows already stored in the database remain plaintext until they are rotated, expired, or replaced.

#### AUD-004

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `routes/api.php:4096`
- Summary: The short-code estimate redirect uses untrusted `Origin` or `Referer` headers to construct the `Location` header.
- Evidence: `/e/{shortCode}` sets `$baseUrl` from `Origin` or `Referer` and then emits `Location: {baseUrl}/estimate/view?code={shortCode}`.
- Impact: An attacker can supply a malicious header value and turn the endpoint into an open redirect, which is useful for phishing, trust abuse, and link laundering through the application domain.
- Recommended fix: Build redirects from a server-side canonical application URL or a validated internal path only. Do not derive redirect targets from request-controlled headers.
- Actual fix: The redirect now uses `APP_URL` as the canonical base instead of request-controlled `Origin` or `Referer` headers.
- Verification: `php -l routes/api.php`
- Residual risk: `APP_URL` must remain correct for each deployed environment or redirects may point to the wrong canonical host.

#### AUD-005

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `routes/api.php:538`
- Summary: The public estimate-request upload flow moves user-supplied files to disk without enforcing an explicit MIME/extension allowlist or upload size limit before persistence.
- Evidence: `/api/public/estimate-request` iterates over `$_FILES['photos']`, preserves the original extension into the stored filename, and calls `move_uploaded_file()` directly. MIME is detected only after the file is already saved, and the detected type is recorded rather than validated.
- Impact: Attackers can submit arbitrary files through a public endpoint. Depending on deployment and downstream file handling, this increases risk of malware hosting, storage abuse, or unintended execution/exposure if the upload directory is later served by the web tier.
- Recommended fix: Validate file count, size, extension, and server-detected MIME before moving the upload. Restrict this path to a narrow image allowlist if the endpoint is intended for photos only, and store uploads outside any directly served path.
- Actual fix: Added `PublicEstimatePhotoUploadValidator` and moved the public estimate-request photo flow to validate upload status, file size, and server-detected MIME before persistence. Saved filenames now use a randomized basename plus an extension derived from the validated MIME instead of the user-supplied filename.
- Verification: `php tests/PublicEstimatePhotoUploadValidatorTest.php`; `php -l src/Services/EstimateRequest/PublicEstimatePhotoUploadValidator.php`; `php -l routes/api.php`; `php -l tests/PublicEstimatePhotoUploadValidatorTest.php`
- Residual risk: Invalid files are skipped rather than failing the full estimate-request submission, so monitoring should still watch for repeated abusive upload attempts.

#### AUD-008

- Status: `resolved`
- Category: `error_handling`
- Severity: `high`
- Location: `src/Services/Invoice/PaymentProcessingService.php:141`, `src/Services/Payment/SquareGateway.php:364`, `src/Services/Payment/PayPalGateway.php:327`
- Summary: Valid Square and PayPal webhook events can be accepted but never reconciled because normalized webhook data often omits `invoice_id`, and refund events would be misrouted through the payment-recording path if reconciliation later succeeded.
- Evidence: `PaymentProcessingService::handleWebhook()` previously only recorded results when the normalized payload already contained `invoice_id`. Stripe supplied that field, but Square only returned fields such as `order_id` and PayPal returned sale/payment identifiers. The same method also routed every webhook with a `status` field through `recordPayment()`, including refund events.
- Impact: Successful payment and refund webhooks can be silently dropped or applied through the wrong persistence path, which breaks invoice balance updates, refund auditability, and payment reconciliation.
- Recommended fix: Resolve invoice ownership server-side from trusted stored checkout/payment records and route refund events through refund persistence instead of the payment writer.
- Actual fix: `PaymentProcessingService` now resolves `invoice_id` from explicit references, existing payment records, and stored checkout-session metadata. It also logs unmatched handled events for investigation and routes refund webhooks into `recordRefund()`. Square webhook normalization now preserves `reference_id`, and PayPal normalization now preserves parent payment and invoice reference identifiers to support reconciliation.
- Verification: `php tests/PaymentWebhookReconciliationTest.php`; `php -l src/Services/Invoice/PaymentProcessingService.php`; `php -l src/Services/Payment/SquareGateway.php`; `php -l src/Services/Payment/PayPalGateway.php`; `php -l tests/PaymentWebhookReconciliationTest.php`
- Residual risk: Historical payment sessions created before these metadata improvements still rely on whatever provider identifiers were already stored, so some older unmatched events may require manual reconciliation.
- Re-verified: `2026-05-10` (Phase 1) — server-side resolution of `invoice_id` and refund routing through `recordRefund()` are still in place. Holds.

#### AUD-009

- Status: `resolved`
- Category: `error_handling`
- Severity: `high`
- Location: `src/Services/Invoice/PaymentProcessingService.php:279`, `database/install/install.sql:268`, `database/install/install.sql:854`
- Summary: Duplicate webhook deliveries are not idempotent at the payment/refund persistence layer, and unmatched handled events had no automated recovery path after initial ingestion.
- Evidence: The service attempted to use `ON DUPLICATE KEY UPDATE` for `payments`, but the schema does not define a unique key on `transaction_id` or `reference`, so duplicate webhook deliveries could insert multiple payment rows and reapply invoice balance updates. `refunds` likewise had no duplicate guard on `refund_id`, and unmatched webhook events were only logged without any replay mechanism.
- Impact: Provider retries can double-record payments or refunds, distort invoice balances, and leave previously unmatched but otherwise valid events stranded until someone reconciles them manually.
- Recommended fix: Add application-level idempotency around payment/refund persistence keyed by trusted provider identifiers, prevent regressive invoice state transitions from duplicate/non-terminal events, and replay previously unmatched events once enough local linkage data exists to resolve them safely.
- Actual fix: `PaymentProcessingService` now deduplicates payment writes by existing transaction/reference on the target invoice, deduplicates refunds by `refund_id`, avoids reapplying already successful payment amounts, and skips regressive invoice-state transitions when a duplicate non-success event arrives after a successful payment. Unmatched handled events now log full normalized webhook data, and session/payment writes trigger `recoverUnmatchedWebhookEvents()` to replay recoverable audit-log entries once a provider session/payment mapping exists.
- Verification: `php tests/PaymentWebhookReconciliationTest.php` returned `SKIPPED: pdo_sqlite extension is not available.`; `php -l src/Services/Invoice/PaymentProcessingService.php`; `php -l tests/PaymentWebhookReconciliationTest.php`
- Residual risk: Application-level idempotency still depends on stable provider identifiers already being present in webhook normalization; if a provider changes identifiers or historical rows contain inconsistent transaction/reference values, some edge cases may still require manual reconciliation.
- Re-verified: `2026-05-10` (Phase 1) — idempotency guards still in `PaymentProcessingService`. Holds.

#### AUD-010

- Status: `resolved`
- Category: `error_handling`
- Severity: `high`
- Location: `src/Services/Invoice/PaymentProcessingService.php:353`
- Summary: Refund ingestion updates invoice status coarsely without adjusting `amount_paid`, `balance_due`, or `paid_at`, so partial refunds leave invoice balances incorrect and full refunds can preserve stale paid state.
- Evidence: `recordRefund()` previously inserted the refund row and then unconditionally set `invoices.status = 'refunded'`. It did not reduce `amount_paid`, restore `balance_due`, or clear `paid_at` when the invoice was no longer fully paid.
- Impact: Refund webhook/application processing can leave invoices showing the wrong outstanding balance and wrong payment state, which cascades into public payment portals, dashboards, deposits, and follow-up collections logic.
- Recommended fix: Apply refunds as balance deltas against the invoice, derive the resulting invoice status from the remaining paid amount and balance due, and make refund replay idempotent so duplicate deliveries do not repeatedly reduce the balance.
- Actual fix: `PaymentProcessingService` now applies refunded amounts through `syncInvoiceAfterRefund()`, using delta-based logic against any existing refund record. Refunds reduce `amount_paid`, restore `balance_due`, clear `paid_at` when the invoice is no longer fully paid, and set invoice status back to `partial` or `pending` as appropriate instead of forcing a blanket `refunded` state.
- Verification: `php tests/PaymentWebhookReconciliationTest.php` returned `SKIPPED: pdo_sqlite extension is not available.`; `php -l src/Services/Invoice/PaymentProcessingService.php`; `php -l tests/PaymentWebhookReconciliationTest.php`
- Residual risk: The broader invoice domain still does not have a first-class invoice status for fully refunded/credited documents, so business reporting may still need a more explicit refund/credit state model later.
- Re-verified: `2026-05-10` (Phase 1) — refund balance sync via `syncInvoiceAfterRefund()` still in place. Holds.

#### AUD-011

- Status: `resolved`
- Category: `error_handling`
- Severity: `medium`
- Location: `src/Services/Invoice/PaymentProcessingService.php:767`, `database/migrations/097_payment_webhook_events.sql:1`
- Summary: Unmatched webhook recovery depended on replaying generic `audit_logs`, which is an imprecise and expensive source for payment reconciliation state and did not give webhook deliveries a first-class persistence model.
- Evidence: `recoverUnmatchedWebhookEvents()` previously scanned `audit_logs` for `payment.webhook_unmatched` and `payment.webhook_recovered` entries, then re-decoded the embedded normalized payloads. That path mixes observability and operational recovery concerns, and it scales poorly as audit history grows.
- Impact: Older unmatched payment events are harder to backfill efficiently, operational recovery depends on audit-retention behavior, and webhook troubleshooting lacks a dedicated event ledger with provider IDs, attempts, and processing timestamps.
- Recommended fix: Introduce a dedicated webhook-events store for payment deliveries, persist normalized webhook identifiers/status there, and use it as the primary unmatched/recovery source with compatibility fallback for historical audit-log-only events.
- Actual fix: Added `payment_webhook_events` in [097_payment_webhook_events.sql](/var/www/phparm/database/migrations/097_payment_webhook_events.sql), [install.sql](/var/www/phparm/database/install/install.sql), and [dashboard/install.sql](/var/www/phparm/src/react/views/dashboard/install.sql). `PaymentProcessingService` now records normalized webhook events into that table when present, uses provider-native event IDs when available, and replays unmatched events from the dedicated store before falling back to historical `audit_logs`.
- Verification: `php -l src/Services/Invoice/PaymentProcessingService.php`; `php -l src/Services/Payment/StripeGateway.php`; `php -l src/Services/Payment/SquareGateway.php`; `php -l src/Services/Payment/PayPalGateway.php`; `php -l tests/PaymentWebhookReconciliationTest.php`; `php tests/PaymentWebhookReconciliationTest.php` returned `SKIPPED: pdo_sqlite extension is not available.`
- Residual risk: Existing historical unmatched events recorded only in `audit_logs` still rely on the fallback path until the new migration is applied and fresh webhook traffic begins populating `payment_webhook_events`.

#### AUD-012

- Status: `resolved`
- Category: `error_handling`
- Severity: `high`
- Location: `src/Services/Invoice/InvoiceService.php:870`, `src/Services/Invoice/InvoiceService.php:901`, `database/install/install.sql:268`, `src/react/views/dashboard/install.sql:353`
- Summary: Manual/back-office invoice payments used a different persistence contract than gateway payments, which could both violate the installed schema and reduce invoice balances for failed or pending payment attempts.
- Evidence: `InvoiceService::insertPayment()` previously inserted only `amount`, `method`, `reference`, `status`, and `metadata`, while the installed schema defines `payments.transaction_id NOT NULL` and historically `gateway NOT NULL`. `syncInvoiceBalance()` also summed every payment row regardless of status, so failed manual entries still reduced `balance_due`.
- Impact: Manual payment recording could fail on some installs, and when it succeeded it could leave invoice balances/statuses inconsistent with the gateway payment flow by counting non-successful attempts as paid money.
- Recommended fix: Normalize manual payment inserts to the full payments schema, generate fallback transaction/reference identifiers when absent, and only treat successful payment statuses as balance-affecting.
- Actual fix: `InvoiceService` now writes schema-valid manual payment rows with normalized `gateway`, `method`, `transaction_id`, `reference`, and `status` fields. Balance sync now sums only successful payment statuses, reverts invoices back to `pending` when no successful payments remain, and suppresses undeposited-funds entries for non-successful manual payment attempts. I also aligned the install SQL snapshots so the payments table definition matches the migrated shape.
- Verification: `php tests/InvoiceManualPaymentConsistencyTest.php` returned `SKIPPED: pdo_sqlite extension is not available.`; `php -l src/Services/Invoice/InvoiceService.php`; `php -l tests/InvoiceManualPaymentConsistencyTest.php`; `php -l database/install/install.sql`
- Residual risk: Manual payment and gateway payment flows still live in separate services, so a future refactor should likely extract a shared payment-recording policy to eliminate duplicated status semantics entirely.
- Re-verified: `2026-05-10` (Phase 1) — manual-payment normalization still in place. Holds.

#### AUD-013

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `public/index.php:52`, `routes/api.php:1`, `routes/cms.php:1`
- Summary: Every API request paid the bootstrap cost of loading both the API route graph and the CMS route graph even when the request could never match a CMS route.
- Evidence: [public/index.php](/var/www/phparm/public/index.php) unconditionally required both [routes/api.php](/var/www/phparm/routes/api.php) and [routes/cms.php](/var/www/phparm/routes/cms.php) before dispatch. The API route file alone is 9,862 lines, and CMS bootstrap also initializes cache/auth/page-controller state that is irrelevant for `/api/*` and `/health`.
- Impact: API requests carry avoidable PHP parse/bootstrap overhead on every hit, increasing latency and reducing throughput on the dominant request class.
- Recommended fix: Load the CMS route set only for non-API requests, and keep API/health requests on the leaner route/bootstrap path.
- Actual fix: `public/index.php` now loads `routes/cms.php` only for non-API, non-health requests.
- Verification: `php -l public/index.php`
- Residual risk: API requests still pay the cost of constructing the large API route graph on every request because routes are not yet compiled or cached.

#### AUD-014

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Invoice/PaymentProcessingService.php:447`, `src/Services/Invoice/PaymentProcessingService.php:527`
- Summary: Payment reconciliation used broad row scans for duplicate detection and invoice/session resolution during webhook handling.
- Evidence: `findExistingPaymentRecord()` previously selected every payment row for an invoice and scanned in PHP. `findInvoiceIdByPaymentSession()` selected all `payment_sessions` rows for a provider, decoded JSON metadata row-by-row, and searched for identifiers in PHP.
- Impact: As payment history grows, webhook handling time degrades with invoice/provider row count instead of staying close to constant-time for common exact-match cases.
- Recommended fix: Push exact-match filtering into SQL first, and only fall back to metadata decoding on a narrowed candidate set.
- Actual fix: `findExistingPaymentRecord()` now performs targeted SQL lookup with exact transaction/reference predicates and `LIMIT 1`. `findInvoiceIdByPaymentSession()` now first checks exact `session_id` matches in SQL, then narrows metadata fallback to rows whose metadata text contains one of the candidate identifiers before decoding JSON in PHP.
- Verification: `php -l src/Services/Invoice/PaymentProcessingService.php`
- Residual risk: Metadata-based fallback is still less efficient than fully indexed relational linkage for `payment_id` and `order_id`, so very large payment-session tables would still benefit from explicit indexed columns or lookup tables.

#### AUD-015

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:3628`, `routes/api.php:4176`
- Summary: Two estimate read endpoints used an N+1 query pattern to load estimate items, issuing one additional query per estimate job.
- Evidence: The authenticated estimate detail route and the public estimate-view route both fetched all estimate jobs first, then reused a prepared statement inside a loop to query `estimate_items` once per job.
- Impact: Estimates with many jobs incurred query count growth proportional to job count, increasing latency and database load on frequently viewed estimate detail pages.
- Recommended fix: Batch-load estimate items for all jobs in one query and group them in PHP by `estimate_job_id`.
- Actual fix: Added a shared `loadEstimateJobsWithItems` helper in `routes/api.php` that fetches all jobs, loads all matching items with a single `IN (...)` query, and attaches grouped items to each job. Both estimate detail endpoints now use that helper.
- Verification: `php -l routes/api.php`
- Residual risk: The endpoints still build full nested estimate payloads synchronously, so very large estimates could benefit further from slimmer list/detail payload separation or response caching.

#### AUD-016

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:200`, `routes/cms.php:24`
- Summary: Route bootstrap eagerly loaded custom role permissions from the database even on requests that never touched authorization-sensitive handlers.
- Evidence: Both `routes/api.php` and `routes/cms.php` called `RolePermissions::fromDatabase(...)` while defining routes, before any route matching occurred.
- Impact: Public/API requests paid avoidable database/bootstrap cost just to construct authorization metadata that many requests never use.
- Recommended fix: Defer custom role-permission loading until an auth service or gate actually needs it.
- Actual fix: Both route files now wrap role-permission loading in lazy `RolePermissions` proxies, so the database-backed permission load only happens on first real authorization use.
- Verification: `php -l routes/api.php`; `php -l routes/cms.php`
- Residual risk: Requests that do need authorization still pay the database lookup once per request because role permissions are not yet cached across requests.

#### AUD-017

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Support/SettingsRepository.php:130`, `routes/api.php:299`
- Summary: Default settings seeding performed one existence query per configured key during API route bootstrap.
- Evidence: `SettingsRepository::seedDefaults()` previously called `exists()` inside a loop for every configured default, causing N database round trips before route dispatch on every request path that seeded defaults.
- Impact: Request bootstrap time scaled with the number of configured defaults instead of staying near constant-time, wasting database capacity on already-initialized environments.
- Recommended fix: Batch-load existing setting keys once, then insert only the truly missing defaults.
- Actual fix: `SettingsRepository::seedDefaults()` now fetches all existing keys in a single `IN (...)` query and only calls `set()` for missing defaults.
- Verification: `php -l src/Support/SettingsRepository.php`
- Residual risk: Default seeding still runs during request bootstrap rather than deployment/bootstrap tasks, so missing defaults are handled more efficiently but the responsibility still lives on the hot path.

#### AUD-018

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:8008`, `routes/api.php:8649`, `src/Support/SettingsRepository.php:84`
- Summary: Storage notice PDF and template preview endpoints performed multiple serial settings lookups for related shop/template keys during a single request.
- Evidence: Both handlers called `settingsRepository->get()` repeatedly for `shop.address`, `shop.name`, `shop.phone`, and template-specific keys, causing several round trips to the `settings` table per request.
- Impact: These endpoints paid avoidable query overhead in an already synchronous request path that also performs PDF/template rendering work.
- Recommended fix: Add batched settings reads and fetch the needed keys in one query per request.
- Actual fix: Added `SettingsRepository::getMany()` and switched both storage notice handlers to load their related settings in one batched read before building the PDF/preview payload.
- Verification: `php -l src/Support/SettingsRepository.php`; `php -l routes/api.php`
- Residual risk: Other route handlers still use repeated `settingsRepository->get()` patterns, so the same batched approach should be applied opportunistically elsewhere on hot paths.

#### AUD-019

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:3705`, `routes/api.php:4096`, `routes/api.php:4298`, `routes/api.php:4550`
- Summary: Several estimate and workorder detail endpoints performed serial enrichment queries for customer, vehicle, and technician summaries.
- Evidence: These handlers fetched the base estimate/workorder payload first, then issued separate `SELECT ... WHERE id = :id` lookups for related customer, vehicle, and sometimes technician rows.
- Impact: Detail endpoints paid extra round trips and connection work for small related lookups that can be satisfied together, increasing latency on frequently viewed records.
- Recommended fix: Batch the related entity lookups into a single summary query and reuse it across the affected handlers.
- Actual fix: Added a shared `loadEntityParties` helper in `routes/api.php` that resolves customer, vehicle, and technician summaries in one query. The authenticated estimate detail endpoint, both public estimate detail endpoints, and the workorder detail endpoint now use that helper instead of issuing multiple serial lookups.
- Verification: `php -l routes/api.php`
- Residual risk: The affected endpoints still assemble some additional data synchronously, so further gains would come from moving more detail composition into shared repository/service queries rather than route-layer enrichment.

#### AUD-020

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:2187`, `routes/api.php:8961`
- Summary: The CMS route block eagerly constructed cache and controller/service objects during route registration even when the current request never matched a CMS endpoint.
- Evidence: `routes/api.php` created `CMSCacheService`, `CategoryController`, `PageController`, `MenuController`, `MediaController`, and `CMSApiController` unconditionally near the top of route registration, then captured those instances across both public and authenticated CMS handlers.
- Impact: Every request paid the object-construction and dependency-wiring cost for the full CMS API graph, including non-CMS traffic that only needed unrelated API routes.
- Recommended fix: Defer CMS object construction until the first CMS handler actually needs the dependency, while keeping a single reused instance per request.
- Actual fix: Replaced the eager CMS cache/controller/API-controller setup in `routes/api.php` with shared lazy proxy instances. The CMS cache service and controllers are now instantiated on first method call instead of during route definition.
- Verification: `php -l routes/api.php`
- Residual risk: `routes/api.php` still defines a very large number of closures and some non-CMS groups still instantiate services inside route-group setup, so further bootstrap reductions will require the same lazy-loading treatment in other route domains.

#### AUD-021

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:2372`
- Summary: The authenticated dashboard route group eagerly instantiated dashboard, branch, inventory-notification, and PartsTech dependencies during route registration even when the request did not hit those endpoints.
- Evidence: The dashboard/authenticated group created `DashboardService`, `DashboardController`, `BranchController`, `InventoryPullRequestRepository`, and `PartsTechService` at group setup time before any `/api/dashboard`, `/api/branches`, or `/api/partstech/*` route was matched.
- Impact: Every authenticated request paid unnecessary object-construction and dependency wiring cost for dashboard and integration code paths that are irrelevant to most requests.
- Recommended fix: Convert those dependencies to shared lazy instances so construction happens on first use inside the matched handler instead of during route bootstrap.
- Actual fix: Updated the authenticated dashboard group in `routes/api.php` to build the dashboard controller, branch controller, inventory pull-request repository, and PartsTech service through the shared lazy-service helper.
- Verification: `php -l routes/api.php`
- Residual risk: Other authenticated route groups in `routes/api.php`, including customer and inventory domains, still construct controllers and repositories eagerly at group definition time.

#### AUD-022

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:2587`, `routes/api.php:2662`, `routes/api.php:2838`, `routes/api.php:2880`
- Summary: Several adjacent authenticated route groups still eagerly constructed controllers, repositories, and import services during route registration even when those domains were not used by the current request.
- Evidence: The customer, service-type, CSV-import, and inventory groups each created their controller/service graphs at group setup time, including inventory low-stock, lookup, transfer, and import dependencies, before any matching route handler ran.
- Impact: Authenticated requests unrelated to customer or inventory workflows still paid object-construction and dependency-wiring cost for those domains, increasing API bootstrap latency on the largest route file.
- Recommended fix: Apply the shared lazy-service pattern to these groups so their dependencies are instantiated only when the matched route first uses them.
- Actual fix: Converted the customer controller, service-type controller, CSV import services, inventory controller, inventory lookup controller, stock-order controller, and transfer controller in `routes/api.php` to shared lazy instances.
- Verification: `php -l routes/api.php`
- Residual risk: The vehicle-master/authenticated block still eagerly constructs a heavier VIN-decoder and normalization stack, and additional route domains later in `routes/api.php` may still have the same pattern.

#### AUD-023

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:2732`
- Summary: The authenticated vehicle-master route group eagerly built the vehicle repository, VIN decoder stack, normalization job, vehicle controller, and customer-vehicle service during route registration.
- Evidence: The vehicle block loaded `config/vin_decoder.php`, instantiated `VinDecoderFactory`, created the decoder chain, created `VehicleNormalizationJob`, then built `VehicleMasterController` and `CustomerVehicleService` before any `/api/vehicles*` route had matched.
- Impact: Every authenticated request paid the setup cost for VIN decoding and vehicle normalization dependencies even when the request had nothing to do with vehicle APIs.
- Recommended fix: Move the vehicle-master dependency graph behind shared lazy instances so vehicle routes alone incur that setup work.
- Actual fix: Updated the vehicle-master route group in `routes/api.php` so `VehicleMasterController` and `CustomerVehicleService` are created lazily on first use, including deferred VIN-decoder config loading and normalization job setup.
- Verification: `php -l routes/api.php`
- Residual risk: Additional authenticated route groups later in `routes/api.php` may still instantiate their controllers and repositories during route definition, so the bootstrap pass should continue further down the file.

#### AUD-024

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:3427`, `routes/api.php:4040`, `routes/api.php:4113`
- Summary: Core-return, reminder-campaign, and public-invoice routes eagerly built full service/controller graphs during route registration even when the request did not target those features.
- Evidence: `routes/api.php` instantiated `CoreReturnService` and `CoreReturnController` for all authenticated requests, built the reminder notification/scheduler/controller stack for all admin/manager authenticated requests, and created the public invoice payment/service/PDF stack before any matching public invoice route was hit.
- Impact: Both authenticated and public traffic paid avoidable object-construction and dependency-wiring cost for specialized domains that are only used by a small subset of routes.
- Recommended fix: Move those route-level dependency graphs behind the shared lazy-service helper so only matching routes pay their bootstrap cost.
- Actual fix: Converted the core-return controller, reminder campaign controller stack, and public invoice controller in `routes/api.php` to lazily instantiated shared instances.
- Verification: `php -l routes/api.php`
- Residual risk: The authenticated invoice and workorder route groups still build larger dependency graphs eagerly and remain the strongest remaining route-bootstrap targets.

#### AUD-025

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:4431`, `routes/api.php:4517`
- Summary: The authenticated invoice and workorder route groups eagerly constructed the largest remaining payment, messaging, tracking, repository, and controller graphs during route registration.
- Evidence: `routes/api.php` built the invoice payment gateway/controller stack and the workorder messaging, financial, repository, tracking, notification, and controller stack before any `/api/invoices*` or `/api/workorders*` route matched.
- Impact: Authenticated requests unrelated to invoices or workorders still paid the heaviest remaining object-construction and dependency-wiring cost on the main API bootstrap path.
- Recommended fix: Move the invoice controller, onsite payment controller, workorder controller, workorder repository, tracking service, and workorder status notifications behind lazy route-level factories so only matched invoice/workorder requests pay that setup cost.
- Actual fix: Converted the invoice and workorder route groups in `routes/api.php` to lazily instantiate their captured controller/service/repository dependencies, including deferred payment gateway, messaging, tracking, and notification graph construction.
- Verification: `php -l routes/api.php`
- Residual risk: The appointment/user/messaging/dispatch block further down `routes/api.php` still eagerly constructs another large shared graph and is the next strongest route-bootstrap target.

#### AUD-026

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:4934`, `routes/api.php:5445`
- Summary: The appointment, user, messaging, driver-dispatch, tracking, and advanced-dispatch route sections eagerly built another large shared graph during route registration.
- Evidence: `routes/api.php` instantiated the appointment controller stack, user/role controllers, messaging and masked-SMS controllers, driver-dispatch controller, tracking service, and the advanced-dispatch ETA/recommendation/waterfall/geofencing/audit services before those routes were matched.
- Impact: Requests unrelated to appointments, staff messaging, or dispatch features still paid substantial object-construction, config-loading, and dependency-wiring overhead on API bootstrap.
- Recommended fix: Defer those captured controllers and services behind the shared lazy-service helper so the route graph only materializes when the corresponding route family is invoked.
- Actual fix: Converted the appointment, user, role, messaging, masked-SMS, driver-dispatch, tracking, and advanced-dispatch route dependencies in `routes/api.php` to lazy shared instances, including deferred notification and dispatch config loading.
- Verification: `php -l routes/api.php`
- Residual risk: Additional later authenticated groups in `routes/api.php`, including VIN decoder settings, inspection, and other downstream domains, may still instantiate controllers/services eagerly during route definition.

#### AUD-027

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `routes/api.php:6203`, `routes/api.php:6704`, `routes/api.php:6810`, `routes/api.php:6928`, `routes/api.php:8772`
- Summary: Several later route groups in the lower half of `routes/api.php` still eagerly instantiated controllers and service graphs during route registration.
- Evidence: The inspection, QC/truck-checklist, warranty, credit-account, customer-retention, time-tracking/payroll/leave/labor-task, and admin settings/bank-feed/notification-template groups each constructed their controller/service dependencies before any matching route handler ran.
- Impact: Even after earlier bootstrap reductions, unrelated requests still paid avoidable object-construction and config-loading cost from these downstream route domains.
- Recommended fix: Continue applying the shared lazy-service pattern to the remaining lower-file route groups so dependency graphs are created only when their route family is invoked.
- Actual fix: Converted the lower-half inspection, inspection-estimate bridge, QC, truck-checklist, driver-shift, warranty, credit-account, customer-retention, time-tracking/payroll/leave/labor-task, and admin settings/bank-feed/notification-template dependencies in `routes/api.php` to lazy shared instances.
- Verification: `php -l routes/api.php`
- Residual risk: The remaining performance work is less about route bootstrap and more about targeted request-path query patterns, repeated settings reads, and any still-eager edge groups not yet converted near the end of `routes/api.php`.

#### AUD-028

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:300`, `routes/api.php:7704`
- Summary: Two remaining request-path settings loaders still performed serial reads for small related key sets instead of a single batched lookup.
- Evidence: The reCAPTCHA config loader fetched four `integrations.recaptcha.*` keys with separate `settingsRepository->get()` calls, and the storage-fee automation route fetched `storage.daily_fee` and `storage.gate_fee` as separate reads during an already heavy workflow.
- Impact: These handlers paid avoidable extra trips to the `settings` table on requests that already do meaningful synchronous work, adding small but repeatable latency and DB overhead.
- Recommended fix: Switch the remaining small related-key lookups to `SettingsRepository::getMany()` so each request path fetches the needed settings in one query.
- Actual fix: Updated the reCAPTCHA config loader and the storage-fee automation route in `routes/api.php` to load their related settings via `SettingsRepository::getMany()`.
- Verification: `php -l routes/api.php`
- Residual risk: The remaining performance opportunities are now more endpoint-specific, such as serial enrichment queries or repeated config/template initialization in individual handlers, rather than obvious repeated settings reads.

#### AUD-029

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:2207`, `routes/api.php:3981`, `routes/api.php:4235`
- Summary: Estimate sharing and public-estimate actions repeatedly rebuilt the same notification/link/share service stack inside individual handlers and used `COUNT(*)` where only existence was needed.
- Evidence: The share-by-email and share-by-SMS handlers each recreated notification dispatcher, messaging, estimate repository, editor, approval audit, public-link, and share-service objects per request, and the public estimate view handler used `SELECT COUNT(*) FROM estimate_signatures` only to determine whether any signature existed.
- Impact: These estimate-facing endpoints paid avoidable object-construction cost and a heavier aggregate query than necessary on user-facing request paths.
- Recommended fix: Reuse shared request-local lazy estimate share/public-link services across the related handlers and replace the signature count with an existence check.
- Actual fix: Added shared lazy `EstimatePublicLinkService` and `EstimateShareService` helpers in `routes/api.php`, switched the estimate share/public-estimate action handlers to use them, and replaced the signature `COUNT(*)` query with a `SELECT 1 ... LIMIT 1` existence check helper.
- Verification: `php -l routes/api.php`
- Residual risk: Other user-facing handlers still initialize notification/template stacks inline and may benefit from the same consolidation where the path is hot enough to justify it.

#### AUD-030

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:698`, `routes/api.php:4192`
- Summary: Public estimate-request and related public estimate handlers still rebuilt small notification/settings helpers inline on each request.
- Evidence: The estimate-request submission route created a fresh notification dispatcher stack inside the request handler, and the adjacent public estimate routes created ad hoc settings repository usage rather than reusing the existing request-level helpers already present in the route file.
- Impact: These public customer-facing paths paid avoidable object-construction and helper setup cost on every request, even though equivalent request-local shared helpers already existed.
- Recommended fix: Reuse shared lazy notification/settings helpers on these public estimate paths instead of rebuilding the same dispatcher/repository stack inline.
- Actual fix: Added a shared lazy default notification dispatcher in `routes/api.php`, switched the public estimate-request submission route to reuse it, and routed the related public estimate settings reads through the existing request-level `SettingsRepository` instance.
- Verification: `php -l routes/api.php`
- Residual risk: Additional public-facing handlers may still initialize notification/config/template objects inline where consolidation would help, but the remaining wins are narrower and should be prioritized by traffic and latency data.

#### AUD-031

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:4392`
- Summary: The public estimate-by-code endpoint still used an aggregate signature count and an ad hoc settings repository despite equivalent lightweight helpers already existing in the same route file.
- Evidence: The handler queried `COUNT(*) FROM estimate_signatures` only to determine whether any signature existed and instantiated a new `SettingsRepository` just to read `documents.terms.estimates`.
- Impact: The public estimate short-code path paid unnecessary query work and helper construction on a customer-facing request path.
- Recommended fix: Reuse the shared signature-existence helper and request-level settings repository already present in `routes/api.php`.
- Actual fix: Updated the public estimate-by-code handler in `routes/api.php` to use the shared `estimateHasSignature` existence helper and the existing request-level `SettingsRepository`.
- Verification: `php -l routes/api.php`
- Residual risk: Remaining performance opportunities are now mostly isolated endpoint-level issues rather than repeated patterns across the public estimate flow.

#### AUD-032

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:5902`
- Summary: The VIN decoder settings routes still loaded `config/vin_decoder.php`, conditionally created `PartsTechService`, and built `VinDecoderFactory` during route registration.
- Evidence: The VIN decoder settings group initialized the decoder config and factory before any `/api/settings/vin-decoder*` route matched, even though those routes are admin-only and relatively infrequent.
- Impact: Every authenticated request still paid a small amount of config-loading and object-construction overhead from an admin-only settings feature.
- Recommended fix: Defer VIN decoder config and factory creation until one of the VIN decoder settings/statistics routes is actually invoked, and reuse the existing request-level `SettingsRepository` for updates.
- Actual fix: Replaced the eager VIN decoder config/factory setup in `routes/api.php` with a cached config loader closure plus a lazy `VinDecoderFactory`, and switched the settings update handler to reuse the existing `SettingsRepository`.
- Verification: `php -l routes/api.php`
- Residual risk: The remaining performance issues are now mostly endpoint-specific SQL and inline helper work rather than obvious route-definition initialization blocks.

#### AUD-033

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:1738`, `routes/api.php:1912`, `routes/api.php:5208`
- Summary: Several auth and invitation email flows still rebuilt the same notification dispatcher stack inline on each request.
- Evidence: The forgot-password, resend-verification, and user-invite handlers each loaded `config/notifications.php` and instantiated `TemplateEngine`, `NotificationLogRepository`, and `NotificationDispatcher` directly inside the request handler before sending mail.
- Impact: These user-facing auth flows paid repeated object-construction and config-loading cost for the same mail dispatcher setup, despite equivalent shared helpers already existing in the route file.
- Recommended fix: Route these email flows through the shared default notification dispatcher so the same request-local mail stack is reused.
- Actual fix: Updated the forgot-password, resend-verification, and user-invite handlers in `routes/api.php` to use the shared lazy default notification dispatcher instead of rebuilding the dispatcher stack inline.
- Verification: `php -l routes/api.php`
- Residual risk: Remaining performance work is now mostly about SQL/query shaping and a few isolated inline helper initializations, not repeated mail-dispatch construction patterns.

#### AUD-034

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:8194`
- Summary: The storage notice send endpoint performed a separate existence lookup before issuing the update that could already determine whether the record existed.
- Evidence: The handler first executed `SELECT id FROM lien_notices WHERE id = ?`, then ran the `UPDATE lien_notices ... WHERE id = ?`, and only then fetched the updated notice payload.
- Impact: Each send request paid an avoidable extra database round trip on an admin/storage workflow that already does synchronous follow-up work.
- Recommended fix: Use the update result itself to detect a missing row and skip the pre-update existence query.
- Actual fix: Updated the storage notice send handler in `routes/api.php` to rely on `rowCount()` from the `UPDATE` statement for not-found detection before fetching the updated notice record.
- Verification: `php -l routes/api.php`
- Residual risk: Other storage/admin handlers still use similar check-then-write patterns and could be tightened opportunistically, but the remaining issues are incremental rather than systemic.

#### AUD-035

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:8049`
- Summary: The storage fee delete endpoint performed a separate existence query before deleting, even though the delete target and downstream idempotency key were already known from the route parameter.
- Evidence: The handler first queried `SELECT id FROM storage_fees WHERE id = ?`, then issued `DELETE FROM storage_fees WHERE id = ?`, and finally used the fetched ID only to rebuild the same `storage-fee-{id}` idempotency key that could be derived from the request path directly.
- Impact: Each delete request paid an avoidable extra database round trip on an admin/storage workflow.
- Recommended fix: Rely on the delete statement’s affected-row count for not-found detection and derive the idempotency key directly from the route ID.
- Actual fix: Updated the storage fee delete handler in `routes/api.php` to use `rowCount()` from the `DELETE` statement for not-found detection and to build the financial-entry idempotency key directly from the route ID.
- Verification: `php -l routes/api.php`
- Residual risk: Similar check-then-write patterns may still exist in other admin/storage endpoints, but the remaining wins are incremental and should be taken opportunistically.

#### AUD-036

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:6130`
- Summary: The VIN decoder logs endpoint always ran a second `COUNT(*)` query for pagination metadata even when the current page size already proved the total result count.
- Evidence: After fetching the current page of `vin_decode_log` rows, the handler unconditionally executed `SELECT COUNT(*) as total FROM vin_decode_log ...` regardless of whether the returned row count was already less than the requested limit.
- Impact: Trailing pages and small result sets paid an unnecessary second aggregate query on an admin/reporting endpoint.
- Recommended fix: Infer `total` directly when the fetched row count is smaller than the requested page size and only issue the count query when the page is full.
- Actual fix: Updated the VIN decoder logs handler in `routes/api.php` to skip the `COUNT(*)` query when the current page contains fewer rows than the requested limit, deriving `total` from `offset + count(rows)` in that case.
- Verification: `php -l routes/api.php`
- Residual risk: Other paginated admin/reporting endpoints may still use unconditional second queries for metadata even when the current page already implies the total.

#### AUD-037

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:6702`, `routes/api.php:7788`
- Summary: Two authenticated admin flows were still constructing fresh `SettingsRepository` instances even though the route file already maintains a shared request-level repository.
- Evidence: The dispatch settings update handler instantiated `new \App\Support\SettingsRepository($connection)` inside the route callback, and the lazy payroll export controller builder did the same while the surrounding route graph already had `$settingsRepository` available.
- Impact: Each request to those admin routes paid a small but avoidable object-construction cost and duplicated access to the same request-scoped settings dependency pattern used elsewhere in the file.
- Recommended fix: Reuse the existing request-level `SettingsRepository` in both the dispatch settings handler and the payroll export controller factory.
- Actual fix: Updated `routes/api.php` so the dispatch settings save route and the lazy payroll export controller both use the existing shared `$settingsRepository` instead of creating new repository instances.
- Verification: `php -l routes/api.php`
- Residual risk: Remaining performance work is now mostly endpoint-level SQL/query shaping rather than repeated repository construction patterns.

#### AUD-038

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:7650`, `routes/api.php:7884`, `routes/api.php:8149`, `routes/api.php:8676`
- Summary: The storage and auction handlers repeated the same `impound_cases` lookup by `case_number` inline across multiple routes instead of sharing one request-local resolver.
- Evidence: The storage fee create route, storage fee update route, lien notice create route, and auction lot create route each prepared and executed `SELECT id FROM impound_cases WHERE case_number = ? LIMIT 1` independently in their own handler bodies.
- Impact: This duplicated the same query-preparation and lookup path across several adjacent admin workflows, adding small repeated request overhead and making the route file harder to optimize consistently.
- Recommended fix: Centralize the case-number-to-ID resolution into one shared helper closure for the storage and auction block and reuse it across those handlers.
- Actual fix: Added a shared `resolveImpoundCaseIdByNumber` helper in `routes/api.php` and switched the storage fee create/update, lien notice create, and auction lot create handlers to use it instead of duplicating the same lookup code inline.
- Verification: `php -l routes/api.php`
- Residual risk: This reduces duplicated resolver work, but the remaining performance opportunities in the storage/admin area are now more likely to be endpoint-specific SQL shape issues than repeated lookup scaffolding.

#### AUD-039

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:9722`, `routes/api.php:9788`
- Summary: The CMS 404 log and redirect list endpoints always ran a second total-count query even when the current page already proved the total result count.
- Evidence: After fetching a page of 404 logs or redirects, both handlers unconditionally called repository `count()` methods for pagination metadata, even when `count(results) < per_page` meant the current page was already the final page.
- Impact: Small result sets and trailing admin pages paid an unnecessary second database query on each request.
- Recommended fix: Reuse the short-page optimization from the VIN decoder logs endpoint by deriving `total` from `offset + count(results)` when the fetched page is smaller than `per_page`, and only run the repository count query for full pages.
- Actual fix: Updated the `/api/404-logs` and `/api/redirects` handlers in `routes/api.php` to skip the extra count query on short/final pages and infer the total directly in those cases.
- Verification: `php -l routes/api.php`
- Residual risk: Other paginated admin endpoints may still use unconditional total-count queries, but the remaining work is now endpoint-by-endpoint rather than a repeated broad pattern.

#### AUD-040

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Payroll/PayrollExportService.php:25`
- Summary: The payroll export history service always executed a total-count query even when the fetched page already proved the total number of rows.
- Evidence: `PayrollExportService::list()` ran `SELECT COUNT(*) FROM payroll_exports` before fetching the page, even though a page returning fewer than `limit` rows already establishes that `offset + rowCount` is the full total.
- Impact: Small export histories and trailing pages paid an unnecessary aggregate query on each admin request.
- Recommended fix: Fetch the page first and only run the `COUNT(*)` query when the page is full; otherwise derive `total` directly from `offset + count(rows)`.
- Actual fix: Updated `PayrollExportService::list()` to load the requested page first, infer `total` on short/final pages, and fall back to the `COUNT(*)` query only when needed.
- Verification: `php -l src/Services/Payroll/PayrollExportService.php`
- Residual risk: Other service-layer paginated endpoints may still compute totals unconditionally and should be reviewed individually.

#### AUD-041

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/TimeTracking/TimeTrackingService.php:98`
- Summary: The time tracking list service always executed a total-count query before fetching the requested page, even when the page result itself could already prove the total.
- Evidence: `TimeTrackingService::list()` prepared and executed `SELECT COUNT(*) ...` against the filtered `time_entries` join set before the page query, despite the fact that a page returning fewer than `limit` rows already establishes `offset + rowCount` as the total.
- Impact: Small result sets and trailing pages paid an unnecessary aggregate query on a likely hotter admin workflow.
- Recommended fix: Fetch the requested page first and only run the `COUNT(*)` query when the page is full; otherwise derive `total` directly from `offset + count(rows)`.
- Actual fix: Updated `TimeTrackingService::list()` to infer `total` from the fetched page on short/final pages and fall back to the existing count query only when needed.
- Verification: `php -l src/Services/TimeTracking/TimeTrackingService.php`
- Residual risk: Similar unconditional total-count patterns may still remain in other service-layer list endpoints, especially leave requests and financial pagination.

#### AUD-042

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/LeaveRequests/LeaveRequestService.php:24`
- Summary: The leave-request list service always executed a filtered total-count query before fetching the requested page, even when the page result itself could already prove the total.
- Evidence: `LeaveRequestService::list()` prepared and executed `SELECT COUNT(*) ...` against the filtered `leave_requests` join set before the page query, despite the fact that a page returning fewer than `limit` rows already establishes `offset + rowCount` as the total.
- Impact: Small result sets and trailing pages paid an unnecessary aggregate query on each leave-request admin view.
- Recommended fix: Fetch the requested page first and only run the `COUNT(*)` query when the page is full; otherwise derive `total` directly from `offset + count(rows)`.
- Actual fix: Updated `LeaveRequestService::list()` to infer `total` from the fetched page on short/final pages and fall back to the filtered count query only when needed.
- Verification: `php -l src/Services/LeaveRequests/LeaveRequestService.php`
- Residual risk: Other paginated service paths, especially financial entry pagination, may still compute totals unconditionally and should be reviewed individually.

#### AUD-043

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Financial/FinancialEntryService.php:203`
- Summary: The paginated financial entry query always executed a filtered total-count query before returning results, even when the fetched page itself could already prove the total.
- Evidence: `FinancialEntryService::query()` computed `SELECT COUNT(*) FROM (...)` whenever pagination was enabled, despite the fact that a page returning fewer than `per_page` rows already establishes `offset + rowCount` as the total.
- Impact: Small result sets and trailing pages paid an unnecessary aggregate query on financial history views.
- Recommended fix: Fetch the page first and only run the filtered count query when the page is full; otherwise derive `total` directly from `offset + count(entries)`.
- Actual fix: Updated `FinancialEntryService::query()` to build a reusable filtered base SQL, infer `total` from the fetched page on short/final pages, and fall back to the filtered count query only when needed.
- Verification: `php -l src/Services/Financial/FinancialEntryService.php`
- Residual risk: The broad repeated pagination-count pattern is largely reduced now, so the remaining performance work should focus more on endpoint-specific SQL shape or expensive synchronous workflows.

#### AUD-044

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:719`, `src/Services/EstimateRequest/EstimateRequestProcessor.php:46`
- Summary: The public estimate request flow re-read data it had just created, issuing extra database queries for the draft estimate number and uploaded-photo count.
- Evidence: After `EstimateRequestProcessor::processRequest()` created the draft estimate, the route executed `SELECT number FROM estimates WHERE id = :id` just to populate email data. The same handler also called `EstimateRequestRepository::getMedia()` purely to count uploaded photos even though it had just inserted those media rows in the same request.
- Impact: Every successfully processed public estimate request paid unnecessary follow-up queries on a customer-facing path that already performs synchronous writes, uploads, and notifications.
- Recommended fix: Return the generated estimate number directly from the processor and track uploaded-photo count during the upload loop so the notification path can reuse in-memory values.
- Actual fix: Updated `EstimateRequestProcessor::processRequest()` to return `estimate_number`, changed the public estimate request route in `routes/api.php` to reuse that value, and replaced the media recount query with a local `uploadedPhotoCount` tracked during upload handling.
- Verification: `php -l routes/api.php`; `php -l src/Services/EstimateRequest/EstimateRequestProcessor.php`
- Residual risk: The public estimate request path still does substantial synchronous work, so any further performance gains there would likely come from larger workflow changes rather than eliminating small follow-up reads.

#### AUD-045

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `routes/api.php:8377`, `routes/api.php:8490`, `routes/api.php:8701`, `routes/api.php:8761`
- Summary: Several storage and auction write handlers re-queried the just-written row only to build the API response from values the request already had in memory.
- Evidence: The storage impound-case create/update routes and auction lot create/update routes each issued a follow-up `SELECT ... WHERE id = ?` immediately after `INSERT` or `UPDATE`, even though the response shape consisted of fields already present in the request payload, generated IDs, and locally computed timestamps.
- Impact: These admin write paths paid an extra round trip after every successful write, adding avoidable latency to synchronous storage and auction workflows.
- Recommended fix: Assemble the response payload directly from the inserted/updated values, generated IDs, and computed timestamps instead of re-reading the row immediately after the write.
- Actual fix: Updated the storage impound-case and auction lot create/update handlers in `routes/api.php` to return assembled response payloads directly and removed the immediate post-write row fetches. A small `nullableFloat` helper keeps numeric response fields consistent where nullable values are involved.
- Verification: `php -l routes/api.php`
- Residual risk: This removes the extra write-follow-up read pattern from these handlers, but the broader storage/admin area may still have heavier synchronous workflows that need deeper SQL or workflow-level tuning.

#### AUD-046

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Customer/CustomerRepository.php:233`
- Summary: The customer retention query always executed its grouped total-count query even when the fetched page already proved the total result count.
- Evidence: `CustomerRepository::findInactiveCustomers()` fetched the retention page and then unconditionally ran a second `COUNT(*)` over a grouped subquery, despite the fact that a page returning fewer than `limit` rows already establishes `offset + rowCount` as the total.
- Impact: Small retention result sets and trailing pages paid an unnecessary grouped aggregate query on report requests.
- Recommended fix: Infer `total` from `offset + count(rows)` when the fetched page is shorter than `limit`, and only run the grouped count query when the page is full.
- Actual fix: Updated `CustomerRepository::findInactiveCustomers()` to skip the grouped count query on short/final pages and derive the total directly in those cases.
- Verification: `php -l src/Services/Customer/CustomerRepository.php`
- Residual risk: The remaining performance work is now more likely to be report-specific aggregate/query design or synchronous workflow cost rather than the repeated pagination-count pattern.

#### AUD-047

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Customer/CustomerRepository.php:271`
- Summary: The customer retention repository still ran its grouped total-count query for full exports even when it had already loaded the complete result set into memory.
- Evidence: `findInactiveCustomers()` is used with `limit = null` for retention export paths. In that mode it fetches all matching rows first, but previously still executed the grouped `COUNT(*)` subquery afterward even though `count(rows)` already provided the exact total.
- Impact: Full customer retention exports paid an unnecessary extra grouped aggregate query after loading the entire dataset.
- Recommended fix: When `limit` is `null`, treat the fetched row count as the total and skip the grouped count query entirely.
- Actual fix: Updated `CustomerRepository::findInactiveCustomers()` so full export calls with `limit = null` now use `count(rows)` as the total and avoid the second grouped count query.
- Verification: `php -l src/Services/Customer/CustomerRepository.php`
- Residual risk: Report/export paths in other domains may still do similar “fetch all rows, then count again” work and should be reviewed individually.

#### AUD-048

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/TimeTracking/LaborTaskService.php:29`
- Summary: The labor-task list service always executed a filtered total-count query before returning the requested page, even when the fetched page already proved the total.
- Evidence: `LaborTaskService::list()` prepared and executed `SELECT COUNT(*) ...` against the filtered labor-task join set before the page query, despite the fact that a page returning fewer than `limit` rows already establishes `offset + rowCount` as the total.
- Impact: Small result sets and trailing pages paid an unnecessary aggregate query on labor-task administration screens.
- Recommended fix: Fetch the requested page first and only run the `COUNT(*)` query when the page is full; otherwise derive `total` directly from `offset + count(rows)`.
- Actual fix: Updated `LaborTaskService::list()` to infer `total` from the fetched page on short/final pages and fall back to the filtered count query only when needed.
- Verification: `php -l src/Services/TimeTracking/LaborTaskService.php`
- Residual risk: Remaining performance work is now less about repeated pagination-count patterns and more about endpoint-specific aggregates or synchronous workflow cost.

#### AUD-049

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Workorder/WorkorderController.php:50`
- Summary: The workorder list controller always requested a separate repository total count after fetching the current page, even when the current page already proved the total.
- Evidence: `WorkorderController::index()` called `repository->list(...)` and then unconditionally called `repository->count(...)`, despite the fact that a page returning fewer than `limit` workorders already establishes `offset + rowCount` as the total.
- Impact: Small result sets and trailing pages paid an unnecessary second query on a likely common workorder listing path.
- Recommended fix: Infer `total` from `offset + count(workorders)` when the current page is shorter than `limit`, and only call `repository->count(...)` when the page is full.
- Actual fix: Updated `WorkorderController::index()` to skip the repository count query on short/final pages and derive the total directly in those cases.
- Verification: `php -l src/Services/Workorder/WorkorderController.php`
- Residual risk: The broader workorder list path still does per-row enrichment and sub-estimate loading, so future performance gains there are more likely to come from shaping that enrichment work rather than further count-query cleanup.

#### AUD-050

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Workorder/WorkorderController.php:58`, `src/Services/Workorder/WorkorderService.php:518`
- Summary: The workorder list performed an N+1 query pattern for sub-estimates by loading them once per workorder during list response assembly.
- Evidence: `WorkorderController::index()` mapped each listed workorder and called `WorkorderService::getSubEstimates($workorder->id)` inside the loop, producing one `SELECT * FROM estimates WHERE workorder_id = ...` query per listed row.
- Impact: Workorder list requests with many rows paid a growing number of extra queries, adding avoidable latency and database load on a common operational screen.
- Recommended fix: Batch-load sub-estimates for the current page’s workorder IDs in one query and group them in memory before building the response.
- Actual fix: Added `WorkorderService::getSubEstimatesForWorkorders()` to fetch all page sub-estimates in one `IN (...)` query, and updated `WorkorderController::index()` to reuse that grouped result instead of calling `getSubEstimates()` per row.
- Verification: `php -l src/Services/Workorder/WorkorderController.php`; `php -l src/Services/Workorder/WorkorderService.php`
- Residual risk: The workorder list still performs per-row enrichment beyond sub-estimates, so additional wins may remain in `enrichWorkorder()` or other related lookups.

#### AUD-051

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Workorder/WorkorderController.php:637`, `src/Services/Workorder/WorkorderRepository.php:213`
- Summary: The workorder stats endpoint issued five separate count queries for closely related status buckets that can be computed in one grouped query.
- Evidence: `WorkorderController::stats()` called `repository->count(...)` separately for `pending`, `in_progress`, `on_hold`, `completed`, and again for `total_active`, even though all of those numbers are derived from the same status dimension and base filter set.
- Impact: Each workorder stats request paid multiple redundant database round trips on an operational dashboard path.
- Recommended fix: Replace the repeated status-specific count calls with one repository method that groups counts by status for the requested filter scope, then derive `total_active` in memory.
- Actual fix: Added `WorkorderRepository::countByStatuses()` to fetch grouped status counts in one query and updated `WorkorderController::stats()` to use it, computing `total_active` from the grouped result instead of issuing a fifth query.
- Verification: `php -l src/Services/Workorder/WorkorderController.php`; `php -l src/Services/Workorder/WorkorderRepository.php`
- Residual risk: The workorder stats path is much tighter now, but other dashboard/report endpoints may still have similar repeated count patterns that should be reviewed separately.

#### AUD-052

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Dashboard/DashboardService.php:101`
- Summary: The dashboard KPI path computed invoice totals and invoice tax with two separate aggregate queries over the same filtered invoice scope.
- Evidence: `DashboardService::kpis()` first queried invoice totals (`SUM(total)`, `AVG(total)`, `SUM(amount_paid)`, `SUM(balance_due)`) and then issued a second query against the same invoice join/filter set solely to compute `SUM(i.tax)`.
- Impact: Each KPI request paid an avoidable extra aggregate query on a likely high-traffic dashboard endpoint.
- Recommended fix: Fold invoice tax into the existing invoice totals aggregate so the KPI path scans the filtered invoice scope only once.
- Actual fix: Updated the invoice aggregate query in `DashboardService::kpis()` to include `SUM(i.tax) AS total_tax` and reused that value for `taxTotals['invoices']`, removing the second invoice-tax query.
- Verification: `php -l src/Services/Dashboard/DashboardService.php`
- Residual risk: The dashboard KPI path still executes multiple domain-specific aggregates by design, so the remaining wins there would require larger query consolidation tradeoffs rather than isolated duplicate-query cleanup.

#### AUD-053

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Financial/FinancialReportService.php:160`
- Summary: The financial report path computed billable minutes and paid minutes with two separate aggregates over the same filtered `time_entries` range.
- Evidence: `FinancialReportService::summary()` first queried `SUM(duration_minutes)` for all non-rejected entries, then issued a second query against the same date range solely to compute the approved subset.
- Impact: Each financial summary request paid an avoidable extra aggregate scan over `time_entries`.
- Recommended fix: Fold both metrics into one aggregate query using conditional sums over the shared date range.
- Actual fix: Replaced the separate billable and paid time-entry queries in `FinancialReportService::summary()` with a single aggregate query that returns both `billable_minutes` and `paid_minutes`.
- Verification: `php -l src/Services/Financial/FinancialReportService.php`
- Residual risk: The financial summary still performs several domain-specific aggregates by design, so further gains there would come from broader report-level consolidation rather than another small duplicate-query fix.

#### AUD-054

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Dashboard/DashboardService.php:82`
- Summary: The dashboard KPI path computed estimate status counts and estimate tax with two separate aggregate queries over the same filtered estimate scope.
- Evidence: `DashboardService::kpis()` first queried grouped estimate status counts, then issued a second query against the same filtered estimate set solely to compute `SUM(e.tax)`.
- Impact: Each KPI request paid an avoidable extra aggregate scan over estimates.
- Recommended fix: Include estimate tax in the grouped status-count query and accumulate the tax total from those grouped rows instead of issuing a second estimate-tax query.
- Actual fix: Updated the grouped estimate status query in `DashboardService::kpis()` to also return `SUM(e.tax) AS total_tax`, then summed that grouped tax data in memory to populate `taxTotals['estimates']`.
- Verification: `php -l src/Services/Dashboard/DashboardService.php`
- Residual risk: The dashboard KPI path still has multiple domain-specific aggregates by design, so the remaining wins there would require broader consolidation tradeoffs rather than another isolated duplicate-query cleanup.

#### AUD-055

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Dashboard/DashboardService.php:320`
- Summary: The dashboard WIP aging query evaluated the same expensive status-age expression twice for every matching workorder row.
- Evidence: `DashboardService::wipAging()` used the full `TIMESTAMPDIFF(...subquery...)` expression once to compute `age_days` and then repeated the same expression again inside the bucket `CASE` statement.
- Impact: Each WIP aging request paid extra CPU/database work on a query that already includes a correlated subquery against `workorder_status_history`.
- Recommended fix: Compute `age_days` once in an inner subquery, then bucket that derived value in the outer query.
- Actual fix: Reworked the WIP aging SQL in `DashboardService::wipAging()` so the correlated age expression is calculated once in an `aged` subquery and the bucketing logic runs against that derived `age_days` value.
- Verification: `php -l src/Services/Dashboard/DashboardService.php`
- Residual risk: The WIP aging report still depends on a correlated history lookup per workorder; further gains there would require a broader data-model or materialization change rather than SQL deduplication alone.

#### AUD-056

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Workorder/WorkorderRepository.php:344`
- Summary: Workorder technician assignment and priority updates re-read the same workorder immediately after the update even though the repository already had the pre-update model in memory and only a small subset of fields changed.
- Evidence: `assignTechnician()` and `updatePriority()` each called `find($id)` before the update for validation/logging and then called `find($id)` again after the update solely to return the updated workorder model.
- Impact: These common operational updates paid an unnecessary extra round trip after every successful write.
- Recommended fix: Reuse the loaded workorder model, update the changed fields in memory, set a fresh `updated_at`, and return a new `Workorder` instance without re-querying the row.
- Actual fix: Updated `assignTechnician()` and `updatePriority()` in `WorkorderRepository` to return a reconstructed `Workorder` built from the already-loaded model plus the changed fields, removing the post-update `find($id)` calls.
- Verification: `php -l src/Services/Workorder/WorkorderRepository.php`
- Residual risk: Other workorder repository update paths may still re-read rows after writes when they need fields not already loaded, so further cleanup there should be evaluated case by case.

#### AUD-057

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Workorder/WorkorderRepository.php:445`
- Summary: Workorder detail assembly loaded job items with an N+1 pattern by querying items once per job.
- Evidence: `WorkorderRepository::getJobsWithItems()` fetched all jobs, then called `getJobItems($job->id)` inside a loop, issuing one `SELECT * FROM workorder_items ...` query per workorder job.
- Impact: Workorder detail and related endpoints paid a growing number of extra queries as job count increased.
- Recommended fix: Batch-load all job items for the workorder’s job IDs in one `IN (...)` query and group them in memory by `workorder_job_id`.
- Actual fix: Updated `WorkorderRepository::getJobsWithItems()` to fetch all page job items in one batched query and group them by job ID before building the response.
- Verification: `php -l src/Services/Workorder/WorkorderRepository.php`
- Residual risk: Other evidence/detail flows around workorder jobs may still load slices independently, but the core jobs-with-items path is no longer N+1.

#### AUD-058

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Workorder/WorkorderRepository.php:255`, `src/Services/Workorder/WorkorderRepository.php:475`
- Summary: Workorder and workorder-job status updates both re-read the same row after the write even though the repository already had enough data to construct the updated model.
- Evidence: `updateStatus()` loaded the workorder before updating it for validation/history and then called `find($id)` again after the update. `updateJobStatus()` updated the row and then immediately selected it again to log and return the updated model.
- Impact: Common lifecycle transitions paid an unnecessary extra read after each successful status change.
- Recommended fix: Reuse the preloaded row/model, apply the changed status and timestamps in memory, and return a reconstructed model without issuing a second fetch.
- Actual fix: Updated `updateStatus()` to return a reconstructed `Workorder` from the already-loaded model, and updated `updateJobStatus()` to fetch the current job row once before the write, reuse it for logging, and return a reconstructed `WorkorderJob` after applying the changed fields in memory.
- Verification: `php -l src/Services/Workorder/WorkorderRepository.php`
- Residual risk: Some update paths may still need post-write reads when database-side defaults or triggers materially change returned fields, but these status transitions no longer require them.

#### AUD-059

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Financial/ReconciliationService.php:236`
- Summary: The reconciliation session summary query used six separate scalar subqueries to compute counts and totals that can be aggregated in grouped derived tables instead.
- Evidence: `ReconciliationService::sessionSummary()` independently queried bank transaction count/total, ledger entry count/total, matched count, and discrepancy count via separate scalar subqueries tied to the same session.
- Impact: Each summary request paid multiple redundant scans across reconciliation and ledger tables.
- Recommended fix: Replace the scalar subqueries with grouped derived tables joined once to the session row, using conditional aggregates where possible.
- Actual fix: Reworked `sessionSummary()` to join grouped aggregates for bank transactions, reconciliation matches, and ledger entries, collapsing the repeated scalar subqueries into one summary query.
- Verification: `php -l src/Services/Financial/ReconciliationService.php`
- Residual risk: Ledger totals still depend on date-range joins against `financial_entries`; any further gains there would require indexing or broader reconciliation-summary materialization rather than SQL shape cleanup alone.

#### AUD-060

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Workorder/WorkorderRepository.php:58`, `src/Services/Dashboard/DashboardService.php:290`, `database/migrations/098_workorder_status_history_composite_index.sql:1`
- Summary: Workorder status-age filtering and WIP aging relied on a correlated lookup into `workorder_status_history`, causing repeated history scans across list, count, and aging queries.
- Evidence: `WorkorderRepository::list()` and `count()` used `SELECT MAX(created_at) FROM workorder_status_history ... WHERE workorder_id = ... AND to_status = workorders.status` inside the age expression, and `DashboardService::wipAging()` used the same correlated pattern before bucketing rows by age.
- Impact: Workorder list/count filters and WIP aging paid repeated correlated history lookups, which scale poorly as workorder and status-history volumes grow.
- Recommended fix: Replace the correlated lookup with a grouped derived table keyed by `(workorder_id, to_status)`, join it once into the outer query, and support that access pattern with a composite `(workorder_id, to_status, created_at)` index.
- Actual fix: Reworked `WorkorderRepository::list()` and `count()` plus `DashboardService::wipAging()` to use a grouped history join instead of correlated lookups, added migration [098_workorder_status_history_composite_index.sql](/var/www/phparm/database/migrations/098_workorder_status_history_composite_index.sql), and updated both install snapshots to include the new composite history index.
- Verification: `php -l src/Services/Workorder/WorkorderRepository.php`; `php -l src/Services/Dashboard/DashboardService.php`; `php -l src/Services/Financial/ReconciliationService.php`
- Residual risk: This removes the correlated-query shape, but long-term workorder analytics may still benefit from a materialized current-status timestamp if history growth becomes very large.

#### AUD-061

- Status: `resolved`
- Category: `security`
- Severity: `high`
- Location: `routes/cms.php:188`, `routes/cms.php:204`, `routes/cms.php:283`, `public/index.php:54`
- Summary: Legacy `/cms/admin/*` HTML routes bypass the application auth and CSRF middleware model and delegate directly to `AdminController`.
- Evidence: `public/index.php` loads `routes/cms.php` only for non-API requests, after `routes/api.php` has already attached its global auth/CSRF middleware to the API router only. In `routes/cms.php`, the `/cms/admin/*` routes directly instantiate `new AdminController()` for dashboard, page, component, template, cache, settings, and user-management actions without any route middleware, `AccessGate` assertion, or CSRF validation at the route layer.
- Impact: The legacy CMS admin surface relies entirely on opaque controller internals for authentication, authorization, and CSRF handling. If any controller action is incomplete or inconsistent, sensitive CMS admin functions become directly reachable or CSRFable with no defense-in-depth at the application route boundary.
- Recommended fix: Put `/cms/admin/*` behind explicit route-layer protection. Require authenticated CMS/admin session state for non-login routes, fail closed on missing auth, and enforce CSRF validation on state-changing legacy CMS form posts before controller dispatch.
- Actual fix: Retired the legacy HTML admin path in `routes/cms.php`. `GET /cms/admin*` now redirects to the SPA-admin replacement paths under `/cp/cms` or `/cp/login`, and legacy `POST /cms/admin*` requests now fail closed with HTTP 410 instead of dispatching to `AdminController`.
- Verification: `php -l routes/cms.php`
- Residual risk: Existing bookmarks or automation that still submit directly to `/cms/admin*` must be updated to the SPA-admin and `/api/cms` flows.
- Re-verified: `2026-05-10` (Phase 1) — `routes/cms.php:225-249` confirms `GET /cms/admin*` still redirects and `POST /cms/admin*` still returns 410 (no `new AdminController` instantiation in the file). Holds.

#### AUD-062

- Status: `resolved`
- Category: `error_handling`
- Severity: `medium`
- Location: `config/cms.php:35`, `src/CMS/CMSBootstrap.php:106`, `src/Services/CMS/CMSAuthBridge.php:134`
- Summary: The CMS session bridge and the legacy CMS bootstrap use different session assumptions, which can leave the HTML admin surface operating on a different session cookie than the authenticated app session.
- Evidence: `CMSAuthBridge::initializeCMSSession()` calls `session_start()` with the default PHP session name and writes `cms_user_*` values into that session. `CMSBootstrap::initSession()` instead sets `session_name('fixitforus_cms')` from `config/cms.php` before starting the legacy CMS session. That means the JWT/app-authenticated API path and the legacy `/cms/admin` path do not clearly share the same session namespace.
- Impact: CMS session bridging can fail or behave inconsistently across the API and legacy HTML admin surfaces, causing broken access, confusing dual-session behavior, or authentication state that does not line up with the intended app user session.
- Recommended fix: Standardize the CMS/session integration model. Either reuse the same secure app session for CMS bridging or explicitly isolate the legacy CMS session and perform a deliberate, validated handoff between them. Do not rely on implicit `session_start()` behavior with different session names.
- Actual fix: Normalized the CMS bootstrap to use the existing app session namespace instead of forcing a separate `fixitforus_cms` session cookie. `config/cms.php` now points the admin prefix at `/cp/cms`, `CMSBootstrap::initSession()` no longer overrides the session name, and `cms_admin_url()` now emits the SPA admin path instead of the retired `/cms/admin` path.
- Verification: `php -l src/CMS/CMSBootstrap.php`; `php -l config/cms.php`
- Residual risk: The external legacy CMS implementation is not present in this workspace, so any remaining references to the retired `/cms/admin` path outside this repo still need to be updated operationally.

#### AUD-006

- Status: `resolved`
- Category: `security`
- Severity: `high`
- Location: `routes/api.php:1862`, `src/Services/Payment/SquareGateway.php:159`, `src/Services/Payment/PayPalGateway.php:135`
- Summary: Public Square and PayPal webhook endpoints can process payloads without a verified signature when the signature header is missing, and verification is skipped entirely when the corresponding secret/ID is not configured.
- Evidence: The public webhook routes call `handleWebhook()` directly. In `SquareGateway::handleWebhook()` and `PayPalGateway::handleWebhook()`, verification only runs inside `if ($signature && ...)`, so an empty signature bypasses verification logic instead of failing closed. Both verifier helpers also explicitly return success when the webhook secret/key is unset.
- Impact: An attacker can post forged webhook payloads to public payment webhook endpoints and potentially trigger payment state changes or payment record creation whenever the downstream payload contains enough accepted fields. Even when invoice reconciliation is incomplete, this still undermines trust in payment-event ingestion.
- Recommended fix: Require signature/header presence on every public webhook request, fail closed when webhook credentials are missing, and reject unsigned or partially signed requests before any payload processing.
- Actual fix: Updated webhook routes to pass full verification context and changed Stripe, Square, and PayPal gateway handlers to fail closed. Webhook handlers now require the signature header, and Square/PayPal also require their configured webhook credential before processing any payload.
- Verification: `php -l src/Services/Payment/StripeGateway.php`; `php -l src/Services/Payment/SquareGateway.php`; `php -l src/Services/Payment/PayPalGateway.php`; `php -l src/Services/Invoice/PaymentProcessingService.php`; `php -l src/Services/Invoice/InvoiceController.php`; `php -l routes/api.php`
- Residual risk: Deployments must provide valid Stripe webhook secret, Square webhook signature key, and PayPal webhook ID or public webhook delivery will now fail by design.
- Re-verified: `2026-05-10` (Phase 1) — `SquareGateway::handleWebhook()` (`src/Services/Payment/SquareGateway.php:169`) and `PayPalGateway::handleWebhook()` (`src/Services/Payment/PayPalGateway.php:145`) both throw on missing signature header before any payload processing. Fail-closed behavior holds.

#### AUD-007

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `src/Support/Http/Request.php:66`, `routes/api.php:1862`, `src/Services/Payment/StripeGateway.php:128`, `src/Services/Payment/SquareGateway.php:328`
- Summary: Webhook signature verification is built on transformed request data rather than the original raw request body and request URL.
- Evidence: `Request::capture()` reads `php://input`, decodes JSON, and only exposes the parsed array. The payment webhook routes pass `$request->body()` to gateway handlers. Stripe then verifies `json_encode($payload)` instead of the raw body, and Square signs against `env('APP_URL', '')` rather than the actual notification URL used by the request.
- Impact: Providers that sign the exact raw payload or full notification URL may fail verification unpredictably. In practice this pushes teams toward weakening or bypassing verification to keep webhooks working, which increases the chance of accepting forged events later.
- Recommended fix: Preserve the raw request body in the request object, pass it through to webhook verifiers, and verify against the exact endpoint URL/provider-specific canonical string rather than reconstructed values.
- Actual fix: `Request` now preserves the original raw body and exposes `rawBody()` and `fullUrl()`. Payment webhook routes pass that data into the payment service, Stripe now verifies the original raw body, and Square now verifies against the original raw body plus the exact request URL instead of reconstructed values.
- Verification: `php tests/RequestWebhookSupportTest.php`; `php -l src/Support/Http/Request.php`; `php -l src/Services/Payment/PaymentGatewayInterface.php`; `php -l routes/api.php`
- Residual risk: If an environment terminates TLS or rewrites host/scheme before PHP sees the request, the application must still receive the externally correct webhook URL for providers that sign the notification URL.

## Audit v2 — Phase 1 (Security Delta)

The following findings are produced by Phase 1 of the v2 audit (`docs/audit-v2-plan.md`,
`docs/audit-v2-baseline.md`). They cover code added or substantively changed since the
v1 closeout (`2026-04-08`). Re-verifications of prior findings are recorded in-place on the
original entries above with a `Re-verified:` line.

#### AUD-063

- Status: `resolved`
- Category: `security`
- Severity: `high`
- Location: `src/Services/VoiceNotes/VoiceNoteService.php`, `src/Services/VoiceNotes/VoiceNoteUploadService.php`, `src/Services/VoiceNotes/VoiceNoteController.php`, `src/Support/Ulid.php`, `routes/modules/voice_notes.php`, `config/voice_notes.php`, `database/migrations/186_voice_notes_upload_metadata.sql`
- Summary: The voice-note record endpoint accepted an attacker-controlled `audio_path` string from the request body and the default transcriber resolved it as a filesystem path with no root confinement.
- Evidence: `VoiceNoteService::record()` read `audio_path` directly from `$payload`, validated only that it did not contain `..` segments, and persisted the value into `voice_notes.audio_path`. The shipped `HeuristicTranscriber` was constructed in `routes/modules/voice_notes.php` with no `storageRoot` argument; its `resolve()` method then returned the supplied path verbatim and read `<audio_path>.txt` from disk. There was no upload pipeline in this route module — the path was fully under client control.
- Impact: Any authenticated user with `voice_notes.create` could (a) plant rows referencing arbitrary absolute filesystem paths, and (b) cause the transcriber to read any `*.txt` file the PHP process could access, including operator-managed sidecars under attacker-controlled subdirectories. Downstream UIs that surface or download the audio path inherit the same trust gap (path traversal via absolute paths).
- Recommended fix: Replace the string-payload model with a real upload pipeline (multipart upload → server-generated path under a configured `voice_notes` storage root). Always prefix with the storage root, reject absolute paths, and reject paths whose `realpath()` escapes the root. Inject a non-empty `storageRoot` into `HeuristicTranscriber` in production wiring and assert it is non-empty in the constructor when the binding is "real".
- Actual fix (two passes):
  - **Phase 2 (2026-05-10):** Closed the immediate exploit windows. (1) `VoiceNoteService::record()` rejected absolute paths (POSIX `/`, Windows `C:\`) and null bytes, in addition to the existing `..` check. (2) `HeuristicTranscriber` started requiring a non-empty `storageRoot`; the empty-root mode was gated behind an explicit `allowEmptyRootForTests: true` flag. (3) `HeuristicTranscriber::resolve()` canonicalises both the storage root and the candidate's parent directory with `realpath()` and refuses any sidecar whose canonical parent does not live under the canonical root. (4) `routes/modules/voice_notes.php` wires `storage/private/voice_notes` (auto-created with mode 0750) as the production root.
  - **R-01 (2026-05-12):** Replaced the client-supplied `audio_path` with a server-managed multipart upload pipeline. Migration 186 added `audio_mime VARCHAR(64) NULL` + `audio_sha256_hash CHAR(64) NULL` (plus an `idx_vn_audio_sha` index for future dedupe scans). New `App\Support\Ulid` generates 26-char Crockford-base32 ULIDs for stable, time-sortable filenames. New `VoiceNoteUploadService` is the only supported way to land an audio file: it validates the `$_FILES` shape, sniffs MIME via `finfo_buffer` on the first 2 KiB (the client-supplied `type` is entirely ignored), rejects anything outside the audio allowlist, clamps the byte size at 25 MB (configurable via `voice_notes.max_upload_bytes`), computes sha256, generates `{yyyy}/{mm}/{user_id}/{ulid}.{ext}` where `{ext}` comes from the sniffed MIME (not the uploaded filename), and `move_uploaded_file()`s the tmp file into place. `VoiceNoteService::record()` now takes `($actor, $upload, $payload)`; two new guards (`assertNoClientStoragePayload()` and `assertUploadShape()`) reject any payload still carrying `audio_path`/`audio_mime`/`audio_size_bytes`/`audio_sha256_hash`/`audio_format` and any malformed upload struct. `VoiceNoteController::recordNote()` takes `($actor, $file, $payload)`; `getNote()` synthesises an `audio_url => /api/voice-notes/{id}/audio` so the React detail modal never sees a raw path. A new `GET /api/voice-notes/{id}/audio` endpoint streams the bytes behind the `voice_notes.view` permission with `Cache-Control: private, max-age=0, no-store` and `X-Content-Type-Options: nosniff`.
- Verification:
  - **Phase 2:** `php tests/VoiceNoteServiceTest.php` — 60 tests passed, including 6 cases for the path/null-byte/empty-root rejections.
  - **R-01:** `php tests/VoiceNoteServiceTest.php` — 61 cases pass (model + repo + tag repo + service + controller + permission gates, all on the new upload struct; six new cases assert the rejection of every client-supplied storage field plus the upload-shape and sha256-length guards). `php tests/VoiceNoteUploadServiceTest.php` — 22 cases pass: happy path (file lands on disk, sha256 matches `hash_file('sha256', src)`, path follows `yyyy/mm/user_id/ULID.ext`), MIME rejection (text bytes, junk octet-stream, spoofed `Content-Type`), size cap, empty file, missing tmp_name, UPLOAD_ERR_PARTIAL, authorless actor, ULID uniqueness, original-name traversal/control-char sanitisation, and `resolveStoredFile()` containment (`..` refused, absolute path refused, null byte refused, symlink-escape caught by `realpath()`).
- Residual risk: None. The path-as-input contract is gone — any external client that still POSTs JSON with `audio_path` will get a 400. The web/mobile recorder UI was already sending multipart with an `audio` file part, so the in-tree clients keep working unchanged.
- Re-verified: 2026-05-12 (R-01 shipped — architectural follow-up complete)

#### AUD-064

- Status: `partially-resolved`
- Category: `security`
- Severity: `high`
- Location: `src/Services/Contracts/ContractSigningService.php:160`, `src/Services/Estimate/EstimatePublicLinkService.php:198`, `src/Services/Contracts/ContractPublicLinkRepository.php`
- Summary: E-sign public links are not single-use and are never invalidated on signature capture, so the same link can be replayed to attach additional signatures (or attribute fraudulent ones to other parties).
- Evidence: `ContractSigningService::captureSignature()` resolves the link, writes a `contract_signatures` row, optionally transitions status, but never updates the `contract_public_links` row to record consumption or to invalidate the token. `EstimatePublicLinkService::captureSignature()` follows the same pattern (no `signed_at` / `consumed_at` write to the link). The link remains valid until `expires_at` (which is optional and may be `NULL`).
- Impact: Anyone who obtains the link or its 10-character `short_code` (see AUD-065) — including an over-the-shoulder observer, a forwarded email, or an MITM in transit — can submit additional `signer_name`/`signer_email` payloads with whatever consent text they choose. Audit rows accumulate but no defense-in-depth prevents impersonation of co-signers or repeated signing on a contract that has already activated. There is also no rate limit on the public capture endpoint.
- Recommended fix: For single-party signing, mark the link `consumed_at` on first successful capture and reject subsequent captures. For multi-party flows, issue one link per intended signer (each bound to that signer's email at issue time, optionally with a magic-link verification step), and reject any capture whose `signer_email` does not match the link binding. Apply per-link rate limiting on the public capture endpoint.
- Actual fix: Migration `185_public_link_single_use.sql` adds `consumed_at` + `consumed_by_signature_id` columns (with a `consumed_at` index) to both `contract_public_links` and `estimate_public_links`. `ContractPublicLinkRepository::claim()` does an atomic `UPDATE … WHERE consumed_at IS NULL` and reports winner-vs-loser via `rowCount()`; `attachSignature()` then backfills the signature pointer. `ContractSigningService::captureSignature()` performs an optimistic check on `$link->consumed_at` after validation, then calls `claim()` to close the race window before the signature INSERT. `EstimatePublicLinkService::captureSignature()` mirrors the same flow inline (`claimLink()` / `attachSignatureToLink()` private helpers — the estimate side keeps its inline-SQL convention rather than gaining a new repository class). View / approve / reject paths intentionally remain ungated so customers can still reload the estimate after signing. The architectural recommendation to issue one link per signer (with email binding and per-link rate limiting) is deferred to `docs/audit-v2-recommendations.md` because it would change the public link issuance API and cross-cuts the in-shop multi-party flow that today shares one link.
- Verification: `php tests/EsignSingleUseTest.php` (10 assertions, in-memory SQLite mirroring the post-migration schema) verifies fresh links start `consumed_at NULL`, the first `claim()` returns true and stamps the row, the second returns false, `attachSignature()` populates the signature pointer without disturbing `consumed_at`, independent links are independently claimable, and both `findByTokenHash()` / `findByShortCode()` lookups carry the new columns. `php tests/ContractSigningServiceTest.php` adds three replay-path assertions (token replay rejected with "already been used", short-code replay rejected, internal/authenticated signing path independent of link consumption) and updates the prior co-signer test to assert single-use semantics + signature_id backfill. All 23 contract-signing scenarios + 10 single-use assertions pass.
- Residual risk: Two items remain open and are tracked in `docs/audit-v2-recommendations.md`. (1) Multi-party signing currently has no first-class flow — issuing one link per signer requires the audit-v2 follow-up to land, and until then any contract with multiple genuine signers must use multiple separate links issued from the admin UI. (2) Per-link / per-IP rate limiting on the public capture endpoint is not implemented; a leaked-but-unconsumed link is still vulnerable to a single race-of-one capture by whoever wins the request, and short-code enumeration (AUD-065) is still in scope. The single-use claim does, however, prevent the more dangerous scenario where a leaked already-signed link is replayed weeks later to graft a fraudulent signer.
- Re-verified: 2026-05-10 (Phase 2)

#### AUD-065

- Status: `open`
- Category: `security`
- Severity: `medium`
- Location: `src/Services/Estimate/EstimatePublicLinkService.php:43`, `src/Services/Estimate/EstimatePublicLinkService.php:480`, `src/Services/Contracts/ContractSigningService.php:41`, `src/Services/Contracts/ContractSigningService.php:337`, `routes/api.php:4279`
- Summary: Public estimate and contract links accept a 10-character SHA-256 prefix (`short_code`) as the sole bearer credential on `/e/{shortCode}` and on the contract short-code path, providing only ~40 bits of token entropy.
- Evidence: `EstimatePublicLinkService::issueLink()` derives `$shortCode = substr(hash('sha256', $token), 0, 10)` and `resolveLink()` resolves links by `short_code` alone (no separate token required). `ContractSigningService` follows the identical pattern with `SHORT_CODE_LEN = 10`. The `/e/{shortCode}` route handler in `routes/api.php` has no rate limiting on the lookup. The longer 256-bit `secure_url` token exists but is optional for both endpoints.
- Impact: 40 bits of token entropy is below the modern bearer-token threshold (≥128 bits is standard). With public deployment and no per-IP throttling on `/e/{shortCode}`, a determined attacker can brute-force valid short codes against a known target estimate/contract universe. Birthday-style enumeration becomes practical at scale (>1M issued links).
- Recommended fix: Require the long `token` for any state change (signature capture, comment add, approve/reject). Restrict `/e/{shortCode}` to an unauthenticated *redirect* that bounces to `/estimate/view?token=…` only when the looked-up link is still within an unexpired short window after issue (or, simpler: deprecate short codes for new links and require the token everywhere). Add per-IP rate limiting on the public lookup paths.
- Actual fix:
- Verification:
- Residual risk:

#### AUD-066

- Status: `open`
- Category: `security`
- Severity: `medium`
- Location: `src/Services/Contracts/ContractSigningService.php:189`, `src/Services/Estimate/EstimatePublicLinkService.php:213`
- Summary: The `document_hash` recorded with each signature is computed at sign-time, not at link-issue-time, so the document the signer "agreed to" is whatever the contract/estimate state is at the moment of capture — not what was presented at issue.
- Evidence: `ContractSigningService::captureSignature()` calls `$this->hashContractSnapshot($contract)` *after* calling `requireContract($link->contract_id)` (line 168) — i.e., the snapshot reflects the current row, not the row as of `link.created_at`. Same pattern in `EstimatePublicLinkService::captureSignature()` (line 213, `generateDocumentHash($estimate->toArray())`). There is no stored snapshot or hash on the `*_public_links` rows for verification.
- Impact: An internal actor (or anyone with `contracts.update` / `estimates.update`) can modify the contract/estimate between issue and signature capture; the resulting `document_hash` is bound to the modified content. Forensic reconstruction of "what did the signer actually see" relies on event log replay rather than a stored hash, and there is no mechanism to detect mid-flight tampering.
- Recommended fix: Snapshot the contract/estimate at link-issue time (compute and persist `document_hash` on the link row), then on capture (a) compare current vs. issue-time hash, (b) refuse capture if they differ unless the caller has an `override_changed=true` flag and the override is logged, (c) record both hashes on the signature row.
- Actual fix:
- Verification:
- Residual risk:

#### AUD-067

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `src/Services/Portal/PortalBillingService.php:58`, `src/Services/Portal/PortalBillingService.php:322`, `src/Services/Portal/PortalContractService.php:41`, `src/Services/Portal/PortalContractService.php:81`, `src/Services/Portal/PortalWorkorderService.php:61`
- Summary: Portal billing, contract, and workorder services scope reads to `portal_account.company_id` only and never call `PortalAuthService::assertSiteAccess()`, so a portal account narrowed to specific `allowed_site_ids` still sees company-wide invoices, contracts, and workorders.
- Evidence: `PortalBillingService::listInvoices()` calls `customers->listIdsForCompany($account->company_id)` and iterates without site filtering. `loadScopedInvoice()` only checks `customer.company_id === account->company_id`. `PortalContractService` filters by `'company_id' => $account->company_id` and re-checks `$contract->company_id !== $account->company_id` — no site assertion. `PortalWorkorderService` has the same pattern. By contrast `PortalUploadService`, `PortalRequestWizardService`, `PortalMessagingService`, `PortalEtaPromiseService`, and `PortalAssetViewService` all call `assertSiteAccess()` correctly.
- Impact: A portal account whose `scope.allowed_site_ids = [5]` is intended to be limited to one site; today they can read every billing invoice, contract, and workorder belonging to other sites in the same company. This violates the principle the rest of the portal services already implement.
- Recommended fix: Either (a) require site filtering for billing/contract/workorder reads when `account->scope` defines `allowed_site_ids` (resolve site through customer→site mapping for invoices, through `contract_sites` for contracts, and through `workorders.site_id` for workorders), or (b) document explicitly that these three surfaces are intentionally company-scoped and reject scope payloads that try to narrow them.
- Actual fix: Implemented option (a) under a **strict** policy. New `PortalAccount::allowsRowWithSite(array|int|null $siteIds)` returns false when `allowed_site_ids` is set and `$siteIds` is null/empty — i.e., a row whose site cannot be resolved is excluded for narrowed accounts (no legacy passthrough flag). Bulk site resolvers added: `SiteAssetRepository::resolveSiteIdsForAssetIds()` for invoices/workorders (the real path is `transactional_doc.site_asset_id → site_assets.site_id`, since neither `customers.site_id` nor `workorders.site_id` exist post-migration-156), and `ContractRepository::listSiteIdsForContractIds()` plus a SQL `EXISTS (SELECT 1 FROM contract_sites…)` clause pushed into `ContractRepository::search()` so paginated counts/results match the filter. `PortalBillingService` and `PortalWorkorderService` got an optional positional `?SiteAssetRepository $siteAssets` constructor arg (added at the end so existing callsites keep working), and `loadScoped*`/`list*ForPortal()` now call `allowsRowWithSite()` after the company check. `PortalContractService::listForPortal()` forwards `allowed_site_ids` into `ContractRepository::search()`; `getForPortal()` resolves via `listSiteIdsForContractIds([$contract->id])` for ANY-match. Cross-site rejections re-use the existing `"…belongs to a different company"` `UnauthorizedException` message so the response does not leak whether the row exists.
- Verification: `php tests/PortalSiteScopingTest.php` — 11 cases covering: unscoped account sees all rows including legacy NULL-site invoices/workorders/contracts; scoped account excludes both other-site rows and unresolvable rows; cross-site `getInvoice`/`getWorkorder`/`getContract` raise the same opaque `UnauthorizedException` as cross-company; multi-site contracts match via ANY of their linked sites; unscoped accounts skip the `site_assets` lookup entirely (no extra query). All cases pass.
- Residual risk: **Breaking shape change** for any portal account that has `allowed_site_ids` set against a legacy auto-shop install where `site_asset_id` is universally NULL on invoices/workorders — those rows will now be excluded from the portal listings. The existing legacy-shop installs that have not adopted the multi-site schema run with `allowed_site_ids = NULL` (the unscoped/all-sites mode) so the practical impact is zero, but operators who set `allowed_site_ids` on a legacy account will see an empty list until they backfill `site_asset_id` or revert the account to unscoped. This is the deliberate trade-off chosen over a passthrough flag — silent passthrough was the bug in the original recommendation and re-introducing it would defeat the fix. Brute-force enumeration of valid invoice/workorder/contract IDs by a narrowed account is bounded by the same `"…different company"` error already used for cross-company rejections.
- Re-verified: 2026-05-11 (R-05 implementation)

#### AUD-068

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `src/Support/Auth/StepUpService.php:50`, `src/Support/Auth/TotpService.php:27`
- Summary: Step-up TOTP verification has no replay/reuse protection — a single captured 6-digit code is valid across the configurable verification window (~90 seconds with the default `±1` window) and can be submitted multiple times to mint multiple step-up stamps.
- Evidence: `TotpService::verifyCode()` accepts the code if it matches *any* of `current ± window` slots without recording which slot was consumed. `StepUpService::verify()` then INSERTs a new `auth_step_up_verifications` row on every successful verification. There is no `last_used_counter` per user, no per-code dedupe, and no failed-attempt tracking on the `/api/auth/step-up` endpoint.
- Impact: An attacker who shoulder-surfs or sniffs one TOTP code (e.g., from a screenshot, an over-the-shoulder glance, or a leaked screen recording) can submit it within the window to gain step-up freshness. Combined with AUD-069 below, the freshness then permits sensitive settings writes from the attacker's session.
- Recommended fix: Track the consumed TOTP counter (`floor(time/period) ± window`) per user in the `users` row or in a small `totp_consumed_counters` table; reject any subsequent verify against a counter slot already consumed. Optionally add per-user/per-IP failed-attempt limits on `/api/auth/step-up` to bound brute-force across stolen sessions.
- Actual fix: Added `TotpService::matchCounter()` returning the matched counter slot (the bool `verifyCode()` API stays intact for the 6 login-flow callsites). `StepUpService::verify()` now persists that counter into a new nullable `auth_step_up_verifications.totp_counter` column, and migration 183 adds a UNIQUE `(user_id, totp_counter)` index so a replay of the same code surfaces as SQLSTATE 23000, which `verify()` translates into a returned `false`. Defense lives at the DB layer so a race between two parallel `/api/auth/step-up` calls with the same code can produce at most one accepted insert.
- Verification: `php tests/StepUpReplayDefenseTest.php` — exercises first-use success, same-code replay rejection, no second row on replay, invalid-code rejection, second-user independence, freshness reflection, and the empty-secret throw. All assertions pass against an in-memory SQLite mirroring the post-migration schema.
- Residual risk: Pre-migration rows have `totp_counter = NULL`; MySQL allows multiple NULLs in a UNIQUE index, so historical replays are not retroactively rejected (acceptable — no way to reconstruct what counter consumed each row). Brute-force limiting on `/api/auth/step-up` is out of scope for this fix.
- Re-verified: 2026-05-10 (Phase 2 fix)

#### AUD-069

- Status: `resolved`
- Category: `security`
- Severity: `medium`
- Location: `src/Support/Auth/StepUpService.php:67`, `src/Support/Auth/StepUpService.php:50`
- Summary: A step-up verification is keyed only on `user_id` — `isFresh()` does not bind to the session/JWT, the IP, or the User-Agent that performed the verification. A step-up performed on one device satisfies sensitive endpoints called from any other device for the same user within the freshness window.
- Evidence: `verify()` records `ip_address` and `user_agent` columns but `isFresh()` only reads `verified_at` ordered by `verified_at DESC LIMIT 1`. There is no `WHERE ip = :ip` or `WHERE session_id = :sid`. The 5-minute `FRESHNESS_SECONDS` constant applies user-wide.
- Impact: If an attacker obtains a valid session token (XSS, leaked JWT, hijacked cookie) within 5 minutes of a legitimate step-up by the real user, the attacker inherits the step-up freshness without producing a TOTP code. This is exactly the property step-up was introduced to *defeat* (a session-level compromise of a sensitive write path).
- Recommended fix: Bind the step-up to the JWT/session identifier — record the JWT `jti` (or session id) on `verify()`, require an exact match on `isFresh()`. As a defense-in-depth secondary check, also bind to a stable client fingerprint (e.g., `sha256(ip + user_agent_family)`) and reject mismatches. Invalidate all step-ups for a user on logout, password change, and 2FA-secret rotation.
- Actual fix: Migration 184 adds a nullable `session_fingerprint VARCHAR(64)` column to `auth_step_up_verifications`. `Middleware::auth()` computes a stable fingerprint at auth time — `sess:sha256(session_id)` for PHP-session auth, `jwt:sha256(token)` for cookie or bearer JWT — and stamps it on the request as the `auth_session_id` attribute. `StepUpService::verify()` persists that fingerprint, and `isFresh()`/`assertFresh()`/`remainingSeconds()` filter on it (`WHERE user_id = :uid AND session_fingerprint = :fp`). The four route callsites (`/api/auth/step-up`, `/api/auth/step-up/status`, `/api/settings/dispatch`, `/api/bank-feeds/authorize`, the four `/api/settings/notifications/*` test endpoints) and `SettingsController::update`/`bulkUpdate` now thread the request attribute through.
- Verification: `php tests/StepUpReplayDefenseTest.php` extended to cover the binding — proves `isFresh(uid, sessionA) === true` while `isFresh(uid, sessionB) === false` immediately after the same verify, that `assertFresh` throws `StepUpRequiredException` for a foreign session, and that `remainingSeconds` returns 0 for a foreign session. All assertions pass against an in-memory SQLite mirroring the post-migration schema.
- Residual risk: Pre-migration rows have `session_fingerprint = NULL`; the freshness queries explicitly require an exact `=` match, so SQL three-valued logic excludes legacy rows from non-null lookups (i.e., the worst case is one extra TOTP prompt for a user mid-flow at upgrade time). The fingerprint changes when the access token rotates on `/api/auth/refresh` — that's intentional; refresh implies a logical session boundary even when the refresh token family is the same. Step-up does NOT survive logout (because logout invalidates the source token), but rows are not actively purged on logout/password-change/2FA-rotation; a future hardening could DELETE-where-fingerprint-matches on those events to bound the auth_step_up_verifications row count.
- Re-verified: 2026-05-10 (Phase 2 fix)

#### AUD-070

- Status: `resolved`
- Category: `security`
- Severity: `low`
- Location: `src/Services/Crm/CrmController.php:538-564`
- Summary: When a stored alarm/gate code ciphertext fails authentication (tampered, key-rotated, or corrupted), `tryDecrypt()` swallows the failure and returns `null`. The reveal endpoint then reports the field as "not set" instead of surfacing tamper/integrity failures to the operator or audit log.
- Evidence: `CrmController::tryDecrypt()` catches `\Throwable` and returns `null`. `revealSiteCodes()` then logs `site.codes.viewed` with whichever fields decrypted successfully and silently omits the failed ones. There is no separate `site.codes.decrypt_failed` audit event, and no telemetry distinguishes "code never set" from "code present but failed authentication".
- Impact: Tampering of `alarm_code_encrypted` / `gate_code_encrypted` (e.g., a malicious DBA truncating the payload, or an unintended key rotation) is invisible at the application layer. Operators see "no code set" and either re-enter the code (overwriting evidence) or proceed without one. Forensic detection of crypto-layer tampering is lost.
- Recommended fix: Distinguish "absent" (`alarm_code_encrypted IS NULL`) from "present-but-undecryptable" (non-null ciphertext, `decrypt()` throws). On the latter path, emit a high-severity audit event (`site.codes.decrypt_failed`) and return an explicit error code in the response so the UI can surface "code stored but cannot be decrypted — admin attention required".
- Actual fix: `CrmController::decryptCodeField()` (`src/Services/Crm/CrmController.php:538-564`) replaces the old `tryDecrypt()` swallow-and-return-null. It returns `{value, status}` where status is one of `absent | ok | key_unavailable | decrypt_failed`, and emits `site.codes.decrypt_failed` audit events for both the missing-key and authentication-failure paths. `revealSiteCodes()` propagates the per-field status into the response so the UI can show "stored but cannot be decrypted" instead of "not set." Landed in commit `dd85214` (Phase 2) on `2026-05-10`.
- Verification: `php -l src/Services/Crm/CrmController.php`. No dedicated unit test was added for the decrypt-failure branches because they require a constructed crypto failure that the existing test fakes do not synthesize; behavioral verification is via the audit event being emitted from a code path that previously swallowed silently. Code review confirms the four return paths are mutually exclusive and only `ok` carries a non-null value.
- Residual risk: A future addition of a new ciphertext field that re-introduces the silent-catch pattern would not be caught by current tests. The `decryptCodeField()` helper is the only sanctioned path; ad-hoc `try { decrypt() } catch {}` calls in this controller should be rejected at review.

#### AUD-071

- Status: `open`
- Category: `security`
- Severity: `low`
- Location: `src/Support/Crypto/FieldCipher.php:25`, `src/Services/Crm/CrmController.php:526`, `src/Services/Integrations/IntegrationService.php:121`
- Summary: A single environment key (`SITE_CODES_ENCRYPTION_KEY`) covers two unrelated domains (CRM site alarm/gate codes, third-party integration credentials) with no key versioning, no rotation path, and no domain separation.
- Evidence: `FieldCipher::__construct()` defaults to `SITE_CODES_ENCRYPTION_KEY`; both call sites construct the cipher with the default. The on-disk format is `base64(nonce || ciphertext)` with no version byte and no key id. There is no AAD/context binding (libsodium `crypto_secretbox` does not provide AAD; an `xchacha20poly1305_ietf_*` variant would).
- Impact: Rotating the key for one domain forces simultaneous re-encryption of the other. A future leak of `SITE_CODES_ENCRYPTION_KEY` yields plaintext for both site operational data and partner API credentials. Without a version byte, online rotation (decrypt-with-old, re-encrypt-with-new) requires schema changes.
- Recommended fix: (a) Use distinct env keys per domain (`SITE_CODES_ENCRYPTION_KEY` and `INTEGRATION_CREDENTIALS_ENCRYPTION_KEY`), (b) prefix ciphertexts with a 1-byte version + 4-byte key-id so rotation can decrypt-old / encrypt-new in place, (c) consider switching to `crypto_aead_xchacha20poly1305_ietf_*` so a domain-binding string can be added as AAD.
- Actual fix:
- Verification:
- Residual risk:

#### AUD-072

- Status: `resolved`
- Category: `security`
- Severity: `low`
- Location: `src/Support/Http/IpAddressResolver.php:22`
- Summary: When the request comes from a trusted proxy, the resolver returns the *leftmost* valid IP from `X-Forwarded-For`. Standard proxy practice (AWS ELB/ALB, nginx `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for`) is to *append* the real client IP to the end of the chain, so a malicious client that pre-supplies its own `X-Forwarded-For: spoofed_ip` will produce a chain like `spoofed_ip, real_client, proxy_internal` — the resolver returns `spoofed_ip`.
- Evidence: `IpAddressResolver::resolve()` lines 22–30 iterate the comma-split header and `return` the first valid IP. There is no concept of a trusted-proxy chain length to skip from the right.
- Impact: Once `TRUSTED_PROXIES` is configured (a precondition for IP normalization to engage at all), the resolved client IP can be spoofed by any client that includes its own `X-Forwarded-For` header. This re-introduces the audit/rate-limiting-bypass risk that AUD-001 was meant to close.
- Recommended fix: Skip from the right by the configured trusted-proxy chain length: walk `X-Forwarded-For` right-to-left, drop entries that match a trusted-proxy CIDR, return the first untrusted entry. Document deployment-side that the configured proxy must always *append* the client IP (not trust client-supplied headers verbatim).
- Actual fix: `IpAddressResolver::resolve()` (`src/Support/Http/IpAddressResolver.php:22-41`) now reverses the comma-split header and walks right-to-left, skipping entries whose IP matches any `TRUSTED_PROXIES` CIDR, and returns the first untrusted entry. Landed in commit `dd85214` (Phase 2 security fixes) on `2026-05-10` alongside AUD-068/069/070.
- Verification: `php tests/IpAddressResolverTest.php` — five scenarios pass, including two AUD-072-specific cases: "rightmost-untrusted parsing rejects client-supplied spoofed XFF entries" (`1.2.3.4, 203.0.113.77, 10.1.2.3` → `203.0.113.77`) and "multiple trusted proxies are skipped right-to-left" (`203.0.113.77, 10.5.5.5, 10.1.2.3` → `203.0.113.77`).
- Residual risk: Deployments behind a reverse proxy must set `TRUSTED_PROXIES` correctly. If the proxy itself trusts client-supplied `X-Forwarded-For` headers verbatim instead of appending, the resolver still has nothing trustworthy to walk back through — proxy configuration discipline is a precondition.

### Phase 1 Re-verifications

The following prior findings had touched files; the v2 baseline flagged them for re-check.
Each was re-verified during Phase 1; results are recorded in-place on the original entry
above as a `Re-verified:` line. Summary:

- **AUD-001** — re-verified `2026-05-10`: `IpAddressResolver` is still the single source of truth for `Request`, `Middleware`, and `LoginRateLimiter`. The original fix holds. New related finding AUD-072 (leftmost-XFF parsing) is opened separately rather than reopening AUD-001.
- **AUD-002** — re-verified `2026-05-10`: `PasswordResetRepository` and `EmailVerificationRepository` both hash on write and accept either form on lookup. The compatibility shim is unchanged. Holds.
- **AUD-006** — re-verified `2026-05-10`: `SquareGateway::handleWebhook` (line 169) and `PayPalGateway::handleWebhook` (line 145) both `throw new InvalidArgumentException('Webhook signature is required')` when the header is missing, before any payload processing. Holds.
- **AUD-008** — re-verified `2026-05-10`: PaymentProcessingService still resolves `invoice_id` server-side and routes refunds through `recordRefund()`. Holds.
- **AUD-009 / AUD-010 / AUD-012 / AUD-061** — re-verified `2026-05-10` against current source: original fixes still in place. No regression detected.

## Audit v2 — Phase 3 (Performance Delta)

The following findings are produced by Phase 3 of the v2 audit. They cover performance
issues (N+1 queries, missing indexes, blocking I/O, unbounded result sets, cron hygiene)
discovered in code added or substantively changed since the v1 closeout (`2026-04-08`).
Hot-path or trivial issues are fixed inline; larger or design-level items are deferred to
`docs/audit-v2-recommendations.md`.

#### AUD-073

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `src/Services/Contracts/ContractSlaResolver.php:62`, `src/Services/Tickets/SlaClockService.php:57`
- Summary: `ContractSlaResolver::resolveFor()` ran a per-entitlement `TicketSlaPolicyRepository::findById()` from inside a nested foreach over (active covering contracts × their entitlements). The resolver is called from `SlaClockService::attachForTicket()`, which fires on every ticket creation — so a customer site with 3 active contracts each carrying 5 SLA-bearing entitlements drove 15 single-row policy lookups per ticket on top of the contract + entitlement queries.
- Evidence: pre-fix `resolveFor()` body — `foreach ($this->contracts->listActiveForSite(...) as $contract) { foreach ($this->entitlements->listForContract($contract->id, true) as $ent) { ... $policy = $this->slaPolicies->findById((int) $ent->sla_policy_id); ... } }`. The class docblock states "ranks competing contracts" and is invoked from `SlaClockService::resolvePolicy()` which is called from `attachForTicket()` (every ticket create) and `resolvePolicy()` (preview / clock recompute paths).
- Impact: Excess query volume on the ticket-create hot path scales with portfolio-coverage breadth, not with the number of policies that actually exist (the catalog is small — typically <50 rows). Fanning out to N+1 single-row reads is wasteful and inflates ticket-create latency, especially under multi-contract enterprise tenancy where a handful of large customers can dominate the ticket-create traffic.
- Recommended fix: Two-pass: collect every (contract, entitlement) pair that names an `sla_policy_id`, then bulk-load the referenced policies in one `SELECT … WHERE id IN (…)`. Resolution scoring then reads from the in-memory map rather than re-querying.
- Actual fix: Added `TicketSlaPolicyRepository::findByIds(array $ids): array<int, TicketSlaPolicy>` returning a map keyed by policy id. Refactored `ContractSlaResolver::resolveFor()` to first walk the contracts/entitlements collecting `(contract, entitlement)` pairs and the distinct set of `sla_policy_id` values, then `findByIds()` once, then rank in memory.
- Verification: `php tests/ContractSlaResolverTest.php` — all 13 scenarios pass (including `tightest response_minutes wins across competing contracts`, which is the multi-contract path that exercised the N+1).
- Residual risk: None for the fix. The wider expansion-plan question of whether `listActiveForSite` itself should JOIN entitlements + policies in a single query is left as a Phase-3 follow-up if a future profile shows the entitlements lookup dominating.

#### AUD-074

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Portal/PortalApprovalService.php:296`
- Summary: `pendingEstimatesFor()` issued a `CustomerRepository::find()` call for every estimate returned by each pending status query (up to 500 rows × 2 statuses) just to filter on `customers.company_id`. Authoring comment acknowledged the pattern as deliberately simple but flagged the helper as a future need; the helper already existed (`listIdsForCompany()`) and was not being used.
- Evidence: pre-fix loop body — `foreach ($this->estimates->list(['status' => $status], 500, 0) as $e) { $customer = $this->customers->find($e->customer_id); ... if ((int) ($customer->company_id ?? 0) !== $account->company_id) continue; }`. Repeated for each status in `ESTIMATE_PENDING_STATUSES`.
- Impact: Up to (statuses × 500) single-row customer reads on every portal pending-queue load. The portal dashboard auto-refreshes on a short interval, so a few connected portal accounts can drive thousands of redundant point reads per minute. Not a security issue — just wasted DB time.
- Recommended fix: Resolve the `company_id → customer_ids` set once via the existing `CustomerRepository::listIdsForCompany()` helper, then membership-test in memory.
- Actual fix: `pendingEstimatesFor()` now calls `listIdsForCompany($account->company_id)` once, flips the result into an isset() lookup map, and short-circuits the per-estimate filter in O(1) per estimate. Customer query count drops from O(N estimates) to 1.
- Verification: `php tests/PortalApprovalServiceTest.php` — the three `listPending` scenarios (`scopes estimates by company`, `marks renewal contracts`, `rejects revoked account`) all pass. Pre-existing test failures further down the suite (`approveEstimate` permission tests) are unrelated and reproduce on `main` without this change.
- Residual risk: The estimates table is still read via `EstimateRepository::list(['status' => …], 500, 0)` per status, so a tenant with >500 pending estimates of one status will silently truncate. That cap predates this finding and is tracked under the unbounded-result-sets review (AUD-077 below).

#### AUD-075

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `src/Services/Integrations/ThirdParty/AbstractIntegrationAdapter.php:86`, `src/Services/Sso/OidcHttpClient.php:36`, `src/Services/Sso/OidcHttpClient.php:72`
- Summary: Synchronous outbound HTTP from request handlers (third-party integrations, OIDC token exchange, OIDC userinfo) set `CURLOPT_TIMEOUT` but not `CURLOPT_CONNECTTIMEOUT`. libcurl defaults the connect timeout to 300 s; the overall TIMEOUT bounds the *total* call but a degraded downstream that drops SYNs (without RST) can still tie up an FPM worker for the full 15–30 s overall budget on every retry attempt instead of failing fast at the connect stage.
- Evidence: pre-fix `curl_setopt_array(...)` blocks in both files set only `CURLOPT_TIMEOUT` (15 for OIDC, 30 for the integration adapter). `PartsTechAdapter::request()` already sets `CURLOPT_CONNECTTIMEOUT => 10` (line 319), demonstrating the missing setting on the other two paths is an oversight, not a deliberate choice.
- Impact: During a third-party brownout (PartsTech rate-limited, telematics provider DNS misconfigured, IdP unreachable), every UI search / SSO callback that hits the offending adapter blocks the FPM worker for the full transfer-timeout window. Under FPM's bounded worker pool, a sustained brownout of an upstream can starve the pool and stall otherwise-unrelated traffic.
- Recommended fix: Add `CURLOPT_CONNECTTIMEOUT => 5` to both call sites so the worker gets the connection back inside ~5 s when the downstream is unreachable. Keep the existing total-transfer timeouts; the connect timeout caps just the TCP-handshake stage.
- Actual fix: Inserted `CURLOPT_CONNECTTIMEOUT => 5` into all three `curl_setopt_array(...)` blocks (`AbstractIntegrationAdapter::request`, `OidcHttpClient::postForm`, `OidcHttpClient::getJson`). No change to the request semantics — only adds a connect-stage cap.
- Verification: `composer run phpcs -- src/Services/Integrations/ThirdParty/AbstractIntegrationAdapter.php src/Services/Sso/OidcHttpClient.php` lint-clean. No behavioral test exists for the timeout values; the constant-only edit is verified by inspection plus the surrounding test suite (`OidcServiceTest`, `IntegrationAdapterRegistryTest`) continuing to pass.
- Residual risk: Adapters that were not in scope (`PayPalGateway`, `SquareGateway`, `StripeGateway`, `MaskedSmsGateway`, `PartnerDispatchSyncService`, `IntegrationWebhookService`) should be audited in a follow-up sweep. The payment gateway HTTP paths in particular are higher-impact than the read-side adapters fixed here.

#### AUD-076

- Status: `resolved`
- Category: `performance`
- Severity: `medium`
- Location: `bin/cron/data-cleanup.php:55-130`
- Summary: The nightly data-cleanup cron issued single unlimited `DELETE FROM <table> WHERE <retention predicate>` statements against `password_resets`, `email_verifications`, `notification_logs`, `audit_logs`, `payment_sessions`, and `reminder_logs`. On a deployment where any of these tables has accumulated a large backlog (audit_logs grows fast on busy tenants; notification/reminder logs grow with campaign throughput), a single unbounded DELETE takes a long-held exclusive table-region lock, generates one giant binlog event, and can stall replication / block other writers for the duration of the sweep.
- Evidence: pre-fix script lines 57, 67, 90, 102, 113, 124 — every cleanup step prepares a `DELETE … WHERE …` and executes it once. No `LIMIT`, no chunking, no transaction sizing.
- Impact: For small deployments the unbounded DELETE completes in seconds and is fine. For deployments that have run for years without retention (or that hit a sudden burst — for example, an audit storm during an incident), the next nightly sweep can lock the table for minutes, queue write traffic against it, and produce a single multi-megabyte binlog event that disrupts replication. The payment_sessions and audit_logs tables are the highest-risk because they grow continuously and aren't typically pruned by other code paths.
- Recommended fix: Wrap each DELETE in a `LIMIT N` loop that exits when `rowCount() < N`. Sleep briefly between batches so concurrent writers can interleave. Keep the cumulative `rowCount` for the existing log line.
- Actual fix: Added a `$batchedDelete(PDO, sql, params, batchSize=5000)` closure to `data-cleanup.php` that appends `LIMIT 5000` to the supplied SQL, loops until a partial batch returns, and totals the rowCount across iterations. Refactored all six DELETE call sites to route through it. Sleep is `usleep(50000)` (50 ms) between batches.
- Verification: `php -l bin/cron/data-cleanup.php` reports no syntax errors. The script's behavior is otherwise unchanged from a correctness standpoint — same predicates, same rowCount totals, same log lines — only the lock-hold profile changes.
- Residual risk: Other cron scripts (`auth-sweep.php`, `retention-runner.php`, `pos-stale-sweeper.php`) were not modified in this pass. They were spot-checked and either operate on small inherently-bounded sets or use higher-level service code that handles batching internally; a deeper audit of those scripts is captured as a Phase-3 follow-up if a future profile shows long-held locks from them.

#### AUD-077

- Status: `resolved`
- Category: `performance`
- Severity: `low`
- Location: `bin/cron/run.php:200-204`, `bin/cron/run.php:181-194`
- Summary: The unified cron runner serializes all due jobs in a single PHP process and gates execution on a global file-based lock that has no PID check and a 5-minute timeout. With 4+ jobs scheduled every minute (`waterfall-dispatch`, `geofence-processor`, `pos-stale-sweeper`, `ticket-sla-breach`) plus the `*/5` set, a slow or hung child can monopolise the lock and skip subsequent ticks for up to 5 minutes; meanwhile any *other* job spawned by the same tick blocks behind the slow one because `runJob()` calls `exec()` synchronously.
- Evidence: `run.php:200-204` — `foreach ($jobs as $key => $job) { if (isDue($job['schedule'])) { runJob($job, $quiet); } }` — single sequential loop, no `&`, no `proc_open` parallelism. `run.php:186-188` — lock check uses `time() - $lockTime < 300` and reads only the timestamp; if the holder process has died the lock survives until 5 min after creation.
- Impact: Per-minute jobs can develop coordinated lateness during a slow run; in the worst case the per-minute SLA breach detector and POS stale-heartbeat sweeper miss multiple ticks if any earlier job in the dispatch order hangs. Stale lock files from crashed cron runs delay the next legitimate run by up to 5 minutes.
- Recommended fix: (1) Switch the lock to PID-based using `flock()` on the lock file FD so the lock releases automatically when the holder process exits or is killed. (2) For per-minute jobs that are independent of each other, dispatch them via `proc_open` in parallel and wait at the end of the tick. (3) Add a per-job timeout (kill the child after N seconds and continue with the next job) so one runaway script doesn't block the rest of the sweep.
- Actual fix: All three sub-recommendations landed in `bin/cron/run.php` and a new `App\Support\Cron\CronDispatcher` class.
  1. **Lock**: the timestamp-with-5min-staleness path is gone. The runner now opens `storage/temp/cron.lock` with mode `c`, takes `flock(LOCK_EX | LOCK_NB)`, writes its PID for diagnostics, and releases the lock by closing the FD in the `finally` block. The OS releases the lock automatically if the process is killed or crashes, so a dead runner can never delay the next tick. `--force` is preserved by falling back to `flock(LOCK_EX)` after a non-blocking miss.
  2. **Parallelism**: the sequential `foreach { runJob() }` loop is replaced by `CronDispatcher::dispatch()`, which spawns due jobs via `proc_open(['php', $script], …)` with non-blocking stdout/stderr pipes, polls them every 100 ms, and caps concurrency at `CronDispatcher::DEFAULT_MAX_CONCURRENT` (4) so a midnight tick (where multiple daily jobs converge with the per-minute set) cannot oversubscribe the DB.
  3. **Per-job timeout**: each spawn gets a deadline derived from its cron expression — 50 s for `* * * * *` (must finish before the next tick), `N*60-60 s` for `*/N`, 1800 s for hourly+. Jobs may opt into an explicit `timeout` field in the job spec to override the default. At the deadline the dispatcher sends SIGTERM, waits up to 5 s for a clean exit, then escalates to SIGKILL. The result row records `timed_out=true` and the (possibly-`-1`) exit code so the tick summary surfaces the failure instead of swallowing it.
- Verification: `php tests/CronDispatcherTest.php` — 11/11 (`per-minute job defaults to 50s timeout`, `*/5 schedule defaults to 240s timeout (one-minute margin under cadence)`, `*/15 schedule defaults to 840s timeout`, `hourly + slower schedules fall back to 1800s`, `fast job reports exit 0 + non-trivial duration`, `failing job surfaces non-zero exit code`, `slow job is killed at timeout and marked timed_out=true`, `missing script is skipped without starting a process`, `parallel dispatch finishes near max(child) wall time, not sum(child)` (3× sleep(1) finished in ~1.0s wall, not ~3.0s), `maxConcurrent throttles parallelism — 4 sleep(1) jobs at concurrency=2 take ~2s`, `empty job set returns empty result without spawning anything`). `php -l bin/cron/run.php` and `php -l src/Support/Cron/CronDispatcher.php` both lint clean. `php bin/cron/run.php --list` and `--help` continue to work end-to-end without touching the lock. End-to-end smoke run with two synthetic jobs (one `sleep(1)`, one instant) confirmed parallel dispatch (1.1 s wall) under the live `flock()`-backed lock.
- Residual risk: (a) The dispatcher does not pin children to a CPU set or memory cgroup; a runaway memory-hungry child can still pressure the host until SIGKILL fires at the timeout deadline. Production should keep the existing systemd / Docker memory caps. (b) Per-job timeouts are heuristic defaults — the daily 1800 s ceiling fits the existing cron set with margin, but a future job that needs longer must opt into an explicit `timeout` value or the dispatcher will SIGKILL it. (c) `--job=NAME` (single-job invocation) intentionally retains the original `exec()` flow with no timeout: operators occasionally invoke it for ad-hoc backfills that legitimately exceed the per-tick budget. The all-due tick path is the only one with a finding here.

### Template

#### AUD-XXX

- Status:
- Category:
- Severity:
- Location:
- Summary:
- Evidence:
- Impact:
- Recommended fix:
- Actual fix:
- Verification:
- Residual risk:

## Initial Notes

No confirmed findings recorded yet. Phase 1 completed the baseline inventory and prioritization. Confirmed issues should be added here during Phases 2 through 6.
