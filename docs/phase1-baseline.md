# Phase 1 Baseline and Inventory

## Objective

Establish the application surface area, likely high-risk domains, external integrations, and current testing posture before starting the security, performance, and error-handling audit.

## Snapshot

- Repository root: `/var/www/phparm`
- Backend stack: custom PHP 8.1 application with Composer autoloading
- Frontend stack: React + Vite SPA
- Database: MySQL-style schema and migrations
- Backend PHP files under `src/`: 370
- Frontend files under `src/react/`: 193
- Database migrations: 122
- Top-level automated test scripts: 16
- Registered routes:
  - `routes/api.php`: 557
  - `routes/cms.php`: 39

## Entry Points and Operational Surfaces

### Web Entry Points

- `public/index.php`: main runtime entry point
- `router.php`: development router for PHP built-in server
- `routes/api.php`: primary application and API route registration
- `routes/cms.php`: CMS route registration, including catch-all rendering

### Setup and Upgrade

- `install.php`
- `install_db.php`
- `upgrade.php`
- `database/install/install.sql`
- `database/initial_schema.sql`

### Scheduled and Batch Jobs

- `bin/cron/run.php`
- `bin/cron/appointment-reminders.php`
- `bin/cron/cms-search-reindex.php`
- `bin/cron/data-cleanup.php`
- `bin/cron/geofence-processor.php`
- `bin/cron/inventory-low-stock.php`
- `bin/cron/job-density-snapshot.php`
- `bin/cron/reminder-campaigns.php`
- `bin/cron/storage-lien-notices.php`
- `bin/cron/waterfall-dispatch.php`
- `bin/migrate-financial-entries.php`
- `bin/parse_partner_email.php`
- `bin/seed.php`

## Request Lifecycle Inventory

### Bootstrap and Configuration

- `bootstrap.php` loads Composer, initializes logging, loads `.env`, and assembles app config.
- Logging is configured directly with `ini_set`, writing to `storage/logs/app.log`.
- The bootstrap creates the log directory with mode `0777`, which should be reviewed in the security phase.

### Routing Model

- `public/index.php` dispatches both API and CMS routes through the same router.
- `routes/api.php` is the dominant application surface, containing auth, public flows, protected business APIs, payments, integrations, public tokens, health endpoints, and document-style endpoints.
- `routes/cms.php` includes public page rendering, admin CMS routes, asset serving, and a catch-all path handler.

### Middleware and Security Hooks

Observed centralized controls include:

- JWT service initialization
- CSRF middleware with exempted paths
- rate limiting
- reCAPTCHA verification
- role and permission resolution
- audit logging hooks

This indicates the audit needs to verify not only the presence of controls, but also route coverage consistency.

## High-Risk Domains Identified

The following areas should be treated as Phase 2 priority review targets.

### Authentication and Session Management

- login and customer login
- JWT issuance and refresh
- logout and session management
- password reset and email verification
- invite acceptance
- 2FA/TOTP setup and verification
- impersonation flows

Key files:

- `routes/api.php`
- `config/auth.php`
- `src/Support/Auth/`
- `src/Services/Auth/`

### Public and Token-Based Access

- public invoice endpoints
- public estimate access, approvals, rejections, and signatures
- tracking links
- CMS preview tokens
- invoice public tokens
- estimate public links

Key files:

- `routes/api.php`
- `src/Models/EstimatePublicLink.php`
- `src/Models/Invoice.php`
- `src/Services/Estimate/EstimatePublicLinkService.php`
- `src/Services/Invoice/InvoicePublicController.php`
- `src/Services/Tracking/TrackingService.php`

### File and Media Handling

- CMS media upload and serving
- estimate request uploads
- filesystem configuration for public and private disks
- signed download URL generation

Key files:

- `src/CMS/Controllers/MediaController.php`
- `config/filesystems.php`
- `config/media.php`
- `src/Support/Filesystem/`
- `routes/api.php`

### Payments and Financial Flows

- Stripe, Square, and PayPal integrations
- public payment portal
- onsite payments
- refunds
- payment webhooks

Key files:

- `config/payments.php`
- `src/Services/Payment/`
- `src/Services/Payments/`
- `routes/api.php`

### Messaging and Notifications

- SMTP mail
- Twilio SMS
- reminder campaigns
- masked SMS messaging

Key files:

- `config/notifications.php`
- `src/Support/Notifications/`
- `src/Services/Messaging/`
- `src/Services/Reminder/`

### External Integrations

- VIN decoding via NHTSA and PartsTech
- partner dispatch integrations for Agero, AAA, and GEICO
- bank feed integrations
- outbound webhook dispatch

Key files:

- `config/partner_dispatch.php`
- `config/appointments.php`
- `config/customer_retention.php`
- `src/Services/Integrations/`
- `src/Services/Vehicle/`
- `src/Services/BankFeeds/`
- `src/Support/Webhooks/`

### CMS and Rendering Surface

- public page rendering
- admin CMS routes
- cache behavior
- media library
- page preview flows

Key files:

- `routes/cms.php`
- `config/cms.php`
- `src/CMS/`
- `src/Services/CMS/`

### Scheduled Jobs and Data Maintenance

- reminder dispatch
- geofence processing
- inventory low-stock jobs
- cleanup of expired tokens
- search indexing
- storage lien notices

The cron surface matters for both performance and resilience because failures may not be user-visible.

## External Secrets and Configuration Inventory

Environment and config indicate these sensitive inputs exist:

- `JWT_SECRET`
- payment gateway credentials and webhook secrets
- Twilio SID and token
- SMTP credentials
- partner dispatch auth tokens
- bank feed access token
- reCAPTCHA keys
- filesystem signing key via `APP_KEY`

Important configuration observations:

- `.env.example` documents multiple sensitive integrations and default development values.
- `config/auth.php` and `config/filesystems.php` include fallback default secrets that must be reviewed for production safety and enforcement.
- `config/cms.php` enables CMS CSRF and session regeneration settings, which should be validated against actual route enforcement.

## Current Test Coverage

Present top-level tests appear concentrated in:

- inventory repositories and policy
- service type repository and policy
- vehicle master and VIN-related flows
- estimate creation
- workorder service
- bundle service
- dashboard technician scope
- dispatch recommendation workload
- technician margin report
- PartsTech integration

## Initial Coverage Gaps

Based on the inventory, there is little or no obvious dedicated automated coverage for:

- authentication flows
- JWT refresh and session invalidation
- 2FA/TOTP flows
- impersonation
- password reset and email verification
- payment processing and webhooks
- public invoice and public estimate token flows
- CMS admin authorization and CMS asset/media security
- file uploads and signed downloads
- cron job failure handling and idempotency
- error response consistency across the API surface

These gaps mean Phase 2 and later phases will require more manual inspection and targeted regression tests.

## Initial Prioritization for Phase 2

Review in this order:

1. Auth, JWT, CSRF, 2FA, impersonation, and public token flows
2. Payment processing, payment webhooks, and public payment endpoints
3. File uploads, media serving, signed URLs, and path handling
4. CMS admin routes, preview routes, and public rendering behavior
5. Partner integrations, webhook dispatch, and outbound HTTP behavior
6. Cron jobs handling cleanup, notifications, dispatch, and indexing

## Notes for Audit Execution

- The route count is large enough that route-by-route review should be grouped by domain, not performed as a flat sweep.
- `routes/api.php` appears to be a concentration point for security, performance, and resilience risk.
- The CMS path is effectively a second application surface and should be audited independently from the main API.
- The test surface is too narrow to assume safe refactoring in the highest-risk areas without adding targeted regression coverage.
