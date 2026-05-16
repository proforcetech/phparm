# PHPArm — Feature Catalog

A complete inventory of what the PHPArm platform does, organized by domain. The system started as an auto-repair shop management application (estimates, work orders, invoicing, parts) and has grown through a multi-phase WOMS expansion into a general-purpose service-order / field-service-management platform that supports nine verticals on a single codebase: auto repair, building maintenance, property management, equipment service, fleet, IT support, physical security, POS device monitoring, and recurring/janitorial services.

This document is organized so a new operator, integrator, or contributor can read top-to-bottom and understand the system. Each section covers what the feature does, who it is for, the load-bearing data model, and the entry points (API routes, React surfaces, cron jobs) that wire it together.

**Quick scale numbers (as of 2026-05-14):**

- 215 SQL migrations across 18 numbered phases of the original plan plus the WOMS expansion (Phases 11–18)
- 74 service domains in `src/Services/`
- 48 route modules in `routes/modules/`
- 63 React view domains in `src/react/views/`
- 202 PHP model classes in `src/Models/`
- 21 background jobs orchestrated by a single unified cron runner
- 19 toggleable application modules (most can be disabled per-deployment)
- 13 system roles plus a portal-user role for customer-facing self-service

---

## Table of Contents

1. [Architecture & Foundation](#1-architecture--foundation)
2. [Identity, Access, and Security](#2-identity-access-and-security)
3. [Customer Relationship Management (CRM)](#3-customer-relationship-management-crm)
4. [Vehicles & Asset Registry](#4-vehicles--asset-registry)
5. [Estimates](#5-estimates)
6. [Contracts & E-Sign](#6-contracts--e-sign)
7. [Work Orders & Job Execution](#7-work-orders--job-execution)
8. [Inspections](#8-inspections)
9. [Appointments & Scheduling](#9-appointments--scheduling)
10. [Invoicing, Payments, and POS](#10-invoicing-payments-and-pos)
11. [Inventory & Procurement](#11-inventory--procurement)
12. [Dispatch, Routing, and Geofencing](#12-dispatch-routing-and-geofencing)
13. [Recurring Service Routes](#13-recurring-service-routes)
14. [Towing, Impound, and Roadside Assistance](#14-towing-impound-and-roadside-assistance)
15. [Fleet Operations](#15-fleet-operations)
16. [Preventive Maintenance (PM)](#16-preventive-maintenance-pm)
17. [Tickets & IT Helpdesk](#17-tickets--it-helpdesk)
18. [Software Inventory & Change Management (CAB)](#18-software-inventory--change-management-cab)
19. [Asset Lifecycle (Lease / Acquisition / Decommission)](#19-asset-lifecycle-lease--acquisition--decommission)
20. [Capital Planning](#20-capital-planning)
21. [Property Management Vertical](#21-property-management-vertical)
22. [Physical Security Credentials Vertical](#22-physical-security-credentials-vertical)
23. [POS Device Monitoring Vertical](#23-pos-device-monitoring-vertical)
24. [Multi-Trade Operations](#24-multi-trade-operations)
25. [Customer Portal & Public Self-Service Surfaces](#25-customer-portal--public-self-service-surfaces)
26. [Subcontractor & Vendor Self-Service Portals](#26-subcontractor--vendor-self-service-portals)
27. [Communications & Reminders](#27-communications--reminders)
28. [Voice Notes](#28-voice-notes)
29. [Time Tracking & Payroll](#29-time-tracking--payroll)
30. [Warranty Claims](#30-warranty-claims)
31. [Reporting & Analytics](#31-reporting--analytics)
32. [Content Management System (CMS)](#32-content-management-system-cms)
33. [Document Vault](#33-document-vault)
34. [Service Lines & Multi-Tenancy](#34-service-lines--multi-tenancy)
35. [Divisions, Branches, and Chain Rollup](#35-divisions-branches-and-chain-rollup)
36. [Consolidated Billing](#36-consolidated-billing)
37. [Custom Fields](#37-custom-fields)
38. [Third-Party Integrations](#38-third-party-integrations)
39. [Audit Logging & Data Retention](#39-audit-logging--data-retention)
40. [Background Jobs (Cron Runner)](#40-background-jobs-cron-runner)
41. [Settings & Module Administration](#41-settings--module-administration)
42. [Developer & Operations Tooling](#42-developer--operations-tooling)

---

## 1. Architecture & Foundation

### Stack
- **Backend:** Custom PHP 8.1+ application (no Laravel / Symfony framework). Direct PDO queries against MySQL/MariaDB; PDO_SQLITE for in-memory test isolation.
- **Frontend:** React 19 + Vite. JSX views in `src/react/views/`, reusable UI in `src/react/components/`, React-Context-based stores in `src/react/stores/`.
- **Mobile:** A separate Expo (React Native) project in `apps/mobile/` for field technicians.
- **Server entry:** `public/index.php` via the PHP dev-server router (`router.php`) or a real web server pointing at `public/`.

### HTTP framework
- **Custom router:** `App\Support\Http\Router` — middleware-aware, group-aware, parameterised paths, JSON helpers (`Response::json`, `Response::created`, `Response::noContent`, `Response::badRequest`, `Response::serverError`).
- **Middleware:** auth (`Middleware::auth()`), CSRF, rate limiting, IP resolver with right-walk X-Forwarded-For trust chain (AUD-072).
- **Route grouping:** every route module exports a callable `function (Router $router, RouteContext $ctx)`. The bootstrap loop (`routes/api.php`) discovers and includes every file under `routes/modules/`, so adding a feature is "drop a new file in `routes/modules/` and add a `<Domain>Service`."

### Service-oriented backend
- Thin controllers (`*Controller.php`) wrap each domain. Business logic lives in `*Service.php`. Persistence lives in `*Repository.php` (no ORM — direct PDO `prepare`/`execute`).
- Models (`src/Models/*.php`) are anaemic data containers, hydrated from `PDO::FETCH_ASSOC` rows via a `BaseModel` constructor, with constants for state-machine transitions and lookup enums.

### Frontend conventions
- React Router 6 with a route table in `src/react/router/index.jsx` that splits guests / public / `/cp` (admin) / `/portal` (customer) / `/p` (new portal shell) / `/ess` (employee self-service) / `/tenant` (property-management tenant portal) / `/sub-portal` (subcontractor) / `/vendor-portal` (vendor).
- Per-area shell layouts (`AdminLayout`, `CustomerLayout`, `PortalShell`, `EssLayout`, `TenantLayout`).
- Service modules in `src/services/` wrap `axios` and surface a per-domain client (`customers.service.js`, `voice-notes.service.js`, `capital-plan.service.js`, etc.).

### Response envelope
Every JSON endpoint returns `{ data, message?, success? }`. Lists return `{ data: [...] }`. Domain controllers wrap their model arrays through `array_map(fn($m) => $m->toArray(), $rows)` to produce a flat JSON body.

### Database
- 215 idempotent SQL migrations in `database/migrations/`. Each starts with `IF NOT EXISTS` / information_schema guards so partial re-runs are safe.
- Migration runner: `php migrate.php` walks the directory in numeric order, tracking applied migrations in `schema_migrations`.
- Two production databases supported: MySQL/MariaDB (with emulated prepares so multi-use named params Just Work) and SQLite (test harness, with `registerMysqlCompatFunctions` shimming NOW/GREATEST/LEAST).

---

## 2. Identity, Access, and Security

### Authentication
- **JWT bearer tokens** (HS256). `JwtService` mints / verifies. Issued at login, refreshed via `/api/auth/refresh`. TTLs configurable via `JWT_EXPIRATION` and `JWT_REFRESH_EXPIRATION` env vars.
- **Login surfaces:** staff login (`/login`), customer portal login (`/customer-login`, `/portal-login`), per-tenant SSO (`/sso/start/{provider}`), customer-facing magic-link / password-reset flows.
- **Password policy:** `App\Support\Auth\PasswordPolicy` enforces minimum length, character classes, history reuse window via `PasswordHistoryRepository`. Expirations enforced by `PasswordExpirationPolicy`.
- **Account lockout:** failed-login throttling, lockout window, security-event audit logging.

### Two-factor authentication (TOTP)
- `TotpService` for TOTP secret generation and verification.
- **Step-up MFA** (`StepUpService`, `StepUpRequiredException`): sensitive operations require a fresh second-factor verification within a session-bound window. Session-fingerprint binding (AUD-069) means a step-up satisfied on device A does not authorise a sensitive write on device B.
- **TOTP replay defense** (AUD-068): DB-level `UNIQUE` on the consumed counter slot makes replay attempts surface as a `SQLSTATE 23000` rather than a silent success.
- Admin role requires 2FA at all times (`requires_2fa: true` in `config/auth.php`); accounting and dispatcher require it; everyone else is opt-in.
- Trusted-device tokens (`TrustedDeviceService`) skip the step-up prompt on remembered hardware for a configurable window; tokens stored hashed, with sweep cron job `auth-sweep` purging expired entries nightly.

### Single Sign-On (SSO)
- OpenID Connect (OIDC) and SAML 2.0 paths via `SsoService`, `SsoController`, `OidcHttpClient`.
- Per-tenant provider catalog (`sso_providers`); user-account linking (`sso_user_links`); login attempts logged (`sso_login_attempts`) for audit + rate-limited error reporting.
- Step-up MFA can be required even for SSO logins.

### Roles and permissions
13 system roles plus a portal user, defined in `config/auth.php`:

| Role | Use | Notes |
|------|-----|-------|
| `admin` | Full control | Wildcard permission `*`. 2FA required. |
| `dispatcher` | Coordinates dispatch + roadside | 2FA required. |
| `manager` | Shop management | Cleaner permission set than admin. |
| `technician` | Field staff | Tablet-first; can record but not delete voice notes. |
| `parts` | Parts counter | Inventory + procurement view, limited mutations. |
| `roadside` | Field roadside / tow | Shift, dispatch acceptance, geofence events. |
| `cms` | CMS author | Read/draft. |
| `cms_editor` | CMS edit | Draft, edit, schedule. |
| `cms_publisher` | CMS publish | Publish, unpublish, snapshot. |
| `customer` | Customer-portal account | Read own data, portal self-service. |
| `accounting` | Bookkeeping / reporting | Reads voice notes, geofences, route plans for reconciliation. |
| `warehouse` | Warehouse / receiving | Inventory transfers, receipts. |
| `portal_user` | Generic external portal account | Magic-link / token portal scope. |

`AccessGate` is the central authorisation point — every service-layer mutation calls `gate->assert($actor, $permission)`. `PolicyRegistry` maps permission strings to per-resource policy callbacks (e.g., `workorders.assigned-tech-only-while-in-progress`). Permissions are dot-namespaced (`crm.companies.view`, `voice_notes.view_global`, `capital_plans.manage`, `property.units.manage`).

### Session and CSRF
- `SecureSessionService` issues secure HTTP-only cookies for the React shell; JWT remains the API authority.
- `CsrfTokenService` for state-changing operations from non-API browser flows (CMS forms, settings).
- `IpAddressResolver` walks the `X-Forwarded-For` chain right-to-left, dropping trusted proxy hops, so a client-supplied leading entry cannot spoof the resolved IP (AUD-072).

### Encryption at rest
- `App\Support\Crypto\FieldCipher` provides authenticated encryption for sensitive short fields (alarm codes, gate codes, third-party API credentials).
- Two on-disk envelope formats: legacy v0 (`crypto_secretbox`, decrypt-only after R-06) and v1 (`crypto_aead_xchacha20poly1305_ietf` with the **domain string bound as AAD** so a v1 ciphertext sealed under one domain cannot be opened under another).
- Per-domain keys (`SITE_CODES_ENCRYPTION_KEY`, `INTEGRATION_CREDENTIALS_ENCRYPTION_KEY`) — leaking one only compromises its own domain. Code-resident `KEY_REGISTRY` maps a `key_id_u32` byte in the envelope to its env-var name, supporting future key rotation without column-shape changes.
- One-shot rewrap script (`bin/crypto/rewrap_secrets.php`) walks every column carrying FieldCipher ciphertext and upgrades v0 → v1 envelopes on demand. Idempotent — safe to re-run after a partial failure.

### Audit log
- `App\Support\Audit\AuditLogger` writes to `audit_logs` for every state-changing service-layer call. Fields: `actor_user_id`, `entity_type`, `entity_id`, `event` (e.g., `workorder.completed`, `contract.signature_captured`), `context` (JSON), `ip_address`, `user_agent`, `created_at`.
- Polymorphic — any new entity simply emits its own `entity_type`. Used as the source of truth for the asset-lifecycle timeline (no parallel `*_events` tables).
- Driven by retention policies (see §39).

---

## 3. Customer Relationship Management (CRM)

### Companies, sites, and contacts
- **Companies** (`companies`) — top-level B2B entity, optionally rolled up via parent/child for chain customers.
- **Sites** (`sites`) — physical locations under a company. Carry alarm/gate codes (encrypted), blackout windows, operational notes.
- **Site contacts** (`site_contacts`) — operational contacts per site (facility manager, security guard, etc.).
- **Billing contacts** (`billing_contacts`) — AP-side contacts at the company level.
- **Site blackout windows** — periods when a site cannot accept routine maintenance (ramp-up days, audits, executive visits).
- **Customer/company linkage shim** — legacy `customers` rows can be promoted to a full CRM `company + primary site` via `POST /api/customers/{id}/promote-to-company`.

### Customer (legacy / individual)
- `customers` table predates the CRM expansion and remains the canonical record for individual auto-repair customers (one customer, one or more vehicles).
- `CustomerVehicleService` encapsulates the customer→vehicle relationship.

### Site codes
- Alarm codes and gate codes stored encrypted via `FieldCipher` under the `site_codes` domain.
- Reveal endpoint (`GET /api/sites/{id}/codes`) gated on `crm.sites.codes.view` permission with audit logging on every reveal — silent decrypt failures bubble up as `decrypt_failed` (AUD-070), not as `not_set`.

### Endpoints
- `routes/modules/crm.php` exposes companies / sites / contacts / blackout windows / site-codes reveal / customer-to-company promotion. Reads gated on `.view`, writes on `.manage`.

---

## 4. Vehicles & Asset Registry

Two parallel asset surfaces:

### Customer vehicles (auto-repair)
- `customer_vehicles` keyed off a `customer_id`. VIN, make/model/year, mileage history, service intervals.
- VIN decoder integration (NHTSA vPIC API, configurable via `VIN_DECODER_API_URL` / `VIN_DECODER_TIMEOUT`).
- `VehicleMaster` is the read-side view that joins customer + vehicle for shop-wide listing.

### Site asset CMDB (verticals beyond auto)
- `site_assets` is the polymorphic CMDB used by every non-vehicle vertical: HVAC units, doors (security), POS terminals, software-asset hardware, building equipment.
- Asset types (`asset_types`) catalog the kinds of things a site can hold. Each asset has lifecycle status (`active`, `retired`, `replaced`).
- Asset documents (`asset_documents`) attach manuals, warranty papers, service records.
- Asset links (`asset_links`) form parent/child relationships (sub-assemblies, multi-component installs).

### QR codes and bulk import
- `AssetQrService` generates per-asset QR tokens for inspection / route-visit launch flows.
- `AssetImportService` (Phase 18 / S12) ingests CSV files (5k+ rows tested) in a two-step `upload → dry-run → apply` workflow with a durable per-row error trail (`asset_import_rows`).

---

## 5. Estimates

### Lifecycle
- Estimates live in `estimates`, with `estimate_items` (parts/labor lines), `estimate_jobs` (grouped work bundles), and `estimate_signatures` (e-sign capture).
- Statuses: `draft` → `sent` → `viewed` → `approved` / `rejected` / `expired`.
- `EstimateEditorService` orchestrates line-item edits with running total recalc.

### Sharing
- `EstimateShareService` issues short-lived share URLs that point at the public estimate view (`/estimate/view?token=...`).
- Public share endpoints: read estimate, accept/reject jobs (with optional rejection reason), capture e-signature.

### Public links + e-sign hardening
- `EstimatePublicLinkService` issues per-customer link tokens, capturing the document hash + JSON snapshot at issue time (R-04 / AUD-066). On capture, mismatches between issue-time and capture-time hashes trigger refusal unless the signer explicitly opts in (audited override).
- Per-IP and per-link rate limiting via `PublicLinkRateLimiter` (R-02a / AUD-064).
- Single-use claim on first capture (`public_link_single_use` migration 185) — second capture against the same link returns 409.

### Estimate request intake (public)
- `/request-estimate` is a reCAPTCHA-gated public form for new prospects to drop a request. `EstimateRequestService` queues these for staff triage.

---

## 6. Contracts & E-Sign

A first-class contract lifecycle distinct from estimates, used for service contracts (recurring HVAC PMs, cleaning routes, IT MSAs):

### Data model
- `contracts` — contract record with effective dates, billing cadence, scope.
- `contract_amendments` — versioned addenda.
- `contract_sites` — multi-site scope (a single contract can cover N sites under one company).
- `contract_entitlements` + `contract_consumption_ledger` — track included service hours / visits and consumption draw-down per period.
- `contract_signers` (R-02c) — first-class roster of signer email addresses per public link, replacing the original "anyone with the link can sign" model.
- `contract_signatures` — captured e-sign events with bound signer email (R-02b), document hash at issue (R-04).
- `contract_public_links` — per-recipient link tokens with snapshot of document at issue, signer-email binding, single-use claim.

### Services
- `ContractSigningService` orchestrates e-sign capture. Refuses if document changed between issue and capture unless the override flag is present (audited as `contract.document_changed_accepted`).
- `ContractRenewalService` runs nightly via the `contracts-renewal` cron, auto-renewing contracts within the notice window and expiring non-renewing ones.
- `ContractBillingService` issues invoices from contract billing schedules.
- `ContractUtilizationService` reports drawdown of entitlements (e.g. "you've used 8 of 12 included PM visits this quarter").
- `ContractSlaResolver` derives SLA targets from active contracts when a ticket comes in for a covered customer.

### Public sign UI
- React view `views/public/PublicContractSign.jsx`. Requires the long token; short-code access is read-only as of R-03. Surfaces the bound recipient email so the signer can confirm they're signing as the correct party.

---

## 7. Work Orders & Job Execution

### Core
- `workorders` table is the central operational record: customer, vehicle/asset, assigned tech, status, scheduled time, line items.
- Workorder statuses: `draft` → `scheduled` → `in_progress` → `awaiting_parts` / `qc` → `completed` / `cancelled`. `workorder_status_history` retains the full transition log with timestamps + actor.
- `WorkorderTimelineService` synthesises a per-WO timeline merging audit log entries, status moves, voice notes, signatures, photos.
- `WorkorderJobEvidenceService` enforces evidence-attached completion (photos / voice notes / signatures) for jobs that require it.

### Sub-objects
- `workorder_items` — parts/labor lines.
- `workorder_jobs` — grouped chunks of work.
- `workorder_signatures` — customer / tech sign-off.
- `job_signatures` — per-job customer sign-off (e.g. signed-off oil change before the brake job continues).
- `job_damage_reports` — pre-existing damage walk-around at receiving.
- `workorder_additional_techs` — secondary technicians attached to a single WO for collaborative jobs.

### Change orders
- `WorkorderChangeOrder` + `WorkorderChangeOrderItem` track scope changes mid-job. Customer signs a change order before the new line items become billable.

### Reassignment
- `WorkorderReassignmentRequest` + `WorkorderReassignmentHistory` capture tech-to-tech handoffs with reason and dispatcher approval.

### Tech requests
- `WorkorderTechRequest` is the tech-side ask for help — "need a part," "need a second tech," "customer needs to be called." Routes to dispatcher inbox.

### Kit installs (Phase 10.8)
- `WorkorderKitInstall` + `WorkorderKitInstallItem` apply a Bundle (predefined parts/labor template) to a WO in one gesture. Prep-parts queue surfaces planned installs to dispatch.

### Voice notes
- Per-WO voice-note timeline (see §28).

### Endpoints
- `routes/modules/workorder_change_orders.php`, `workorder_kit_installs.php`, `workorder_reassignments.php`, `workorder_tech_requests.php` plus the core surfaces under `routes/api.php`.

---

## 8. Inspections

A heavily-used vertical for fleet / fire-safety / DOT compliance:

### Templates and items
- `inspection_templates` + `inspection_sections` + `inspection_items` define the form (e.g. "DOT 90-day brake inspection: front pads, rear pads, parking brake, ...").
- `InspectionTemplateService` versions templates so an inspection captured in 2024 can be reproduced exactly in 2026.

### Reports
- `inspection_reports` is a captured execution: inspector, asset/vehicle, started/completed timestamps, overall pass/fail.
- `inspection_report_items` carry per-item results: pass/fail, severity, notes, defect details.
- `inspection_report_media` attaches photo evidence per item.

### Risk scoring
- `InspectionRiskScoringService` computes a risk score per report from item-level severities. Powers "which assets need attention first" prioritisation.
- `inspection_risk_scores` persisted for trend analysis.

### Auto-generation policies
- `InspectionAutoGenerationPolicy` schedules recurring inspections (monthly fire-extinguisher checks, annual DOT inspections). Cron job materialises the next due inspection and links it to the right asset.

### Compliance tags
- `inspection_compliance_tags` mark a report as satisfying a regulatory requirement (DOT, FDA, OSHA). Compliance-tag searches surface "show me everything DOT-relevant for this fleet unit."

### Escalations
- `InspectionEscalation` + `InspectionEscalationRule` open a ticket when a critical defect is found ("brake pad below minimum thickness" auto-creates a P1 work-order ticket).

### Estimate bridge
- `InspectionEstimateBridgeService` converts inspection defects into estimate line items in one click — the customer sees a quote for the brake job that the inspection just flagged.

### QR launch
- `InspectionQrLaunch` lets a tech scan an asset QR with their phone to immediately open the right inspection template, even without searching the asset.

### Portal access
- `InspectionPortalService` lets the customer (via portal) see completed inspections for their assets.

---

## 9. Appointments & Scheduling

- `appointments` ties a customer + vehicle/asset + tech + scheduled time slot.
- Conflict detection at booking (no double-booking the bay or the tech).
- Reminder cron (`appointment-reminders.php`) fires hourly, sending email + SMS reminders 48h, 24h, and 2h ahead per customer preference.
- Tech assignment respects tech skill matrix (see §24).
- Customer can request appointment changes via the portal (gated on capacity rules).

---

## 10. Invoicing, Payments, and POS

### Invoices
- `invoices` table with line items, tax, discount, customer reference, optional WO link.
- `InvoiceService` orchestrates issue → send → record-payment → close. Status moves stamp `audit_logs`.
- Recurring invoice generation via contract billing (`ContractBillingService`).
- Public invoice view + payment surface at `/pay/{token}` with token-based access (`InvoicePublicPaymentTokenService` rotates tokens on payment-attempt rate-limit).

### Payment processing
- Multi-gateway support via `PaymentGatewayFactory`:
  - **Stripe** (`StripeGateway`) — cards, ACH, recurring.
  - **Square** (`SquareGateway`) — cards, terminal hardware integration.
  - **PayPal** (`PayPalGateway`) — express checkout.
- `PaymentProcessingService` handles authorisation, capture, refund, partial-refund, void.
- Webhook ingestion: signed webhook receiver per gateway with idempotent event processing (`payment_webhook_events` table dedupes by hash).

### Credit accounts (B2B)
- `credit_accounts` track open balance per customer. `CreditTransaction` ledger captures every charge / payment / adjustment. Aging buckets (current / 30 / 60 / 90+) surface in the AR dashboard.
- `CreditPaymentReminder` schedules dunning emails.

### POS / Quick Sale
- `views/pos/QuickSale.jsx` is the over-the-counter POS screen for parts sales / oil changes / tire rotations without a full WO.
- `cash_drawer_sessions` track shift open/close with cash-count reconciliation.
- POS terminal devices (separate from the QuickSale UI) tracked under §23.

### Bank feeds + reconciliation
- `BankFeedService` ingests bank-statement CSVs / OFX / API feeds (`DemoBankFeedProvider` ships; production providers pluggable).
- `ReconciliationService` matches deposits against invoices/payments. `reconciliation_sessions`, `reconciliation_bank_transactions`, `reconciliation_matches` retain the audit trail.

### Financial entries
- `financial_entries` is a generic GL-style journal (manual debits/credits) for non-invoice cashflows (rent, payroll, supplies).
- `account_categories` provides the chart of accounts.

---

## 11. Inventory & Procurement

### Items and lookups
- `inventory_items` is the SKU catalog. Quantity-on-hand, reorder threshold, vendor reference, vehicle compatibility (via `inventory_vehicle_compatibilities`).
- `inventory_lookups` is the legacy small-set catalog (categories, locations, vendors-as-strings) that the parts-supplier dropdown still reads. CSV importer lives at Settings → Modules → Import Inventory Lookups.
- `InventoryCsvService` bulk-imports SKU master data.
- Low-stock detection (`InventoryLowStockService`) runs daily and emails the parts manager via the `inventory-low-stock` cron job.

### Stock orders + transfers
- `inventory_stock_orders` is an internal request from a tech to the parts counter. Lifecycle: `requested` → `picked` → `delivered` / `cancelled`.
- `inventory_transfers` move stock between physical locations.

### Pull requests
- `InventoryPullRequest` is the short-lived "I need this part right now for this WO" request from a tech to the parts counter. Counter clerk fulfills, marks picked, drops it on the bay.

### Core returns
- `CoreReturnService` tracks core-return cycles for cores-back parts (alternators, starters): customer pays a core charge, returns the old part within the window, gets the credit back.

### Parts cart
- `PartsCartService` is the tech-side shopping cart used during job spec — add lines, see availability, push to a stock-order if anything's out of stock.

### Procurement (Phase 18 / S5)
- First-class `vendors` table replaces the prior `inventory_lookups`-only model.
- `purchase_orders` with five-state machine (`draft → sent → partial → received → closed`, with terminal `cancelled`).
- `PurchaseOrderLine` per-line items; `PurchaseOrderReceipt` + `PurchaseOrderReceiptLine` per-shipment receipts.
- `PurchaseOrderDocument` attaches PO PDFs, packing slips, vendor invoices.
- Vendor self-service portal (Phase 18 / C1) — see §26.

---

## 12. Dispatch, Routing, and Geofencing

### Waterfall dispatch
- `WaterfallDispatchService` offers a job to qualified techs in priority order, escalating after a configurable timeout. Timeout-driven by the `waterfall-dispatch` per-minute cron.
- `DriverJobOfferService` + `DriverOfferTrackingService` track per-offer state (offered, accepted, declined, expired).

### Driver shifts
- `DriverShiftService` tracks driver availability (on-shift, on-break, off-shift). Dispatch only offers to on-shift drivers.
- Push notification support via `DriverPushTokenService` (FCM-style).

### Recommendations
- `DispatchRecommendationService` ranks driver candidates against an open job by location, skill match, current load.

### Truck checklist
- `TruckChecklistService` enforces a pre-shift truck inspection. Failed checklist items can block shift start.

### Geofencing
- `geo_fences` define site / customer / zone boundaries.
- `GeoFenceEvaluator` evaluates driver coordinates (pushed from the field PWA) against active fences in real time.
- `GeoFenceEventService` records fence-crossings (`geo_fence_events`) — used for arrival/departure stamping and idle detection.
- `geofence-processor` per-minute cron evaluates pending coordinates and emits events.
- `DriverLocationPartitionResolver` shards driver location reads by date-partitioned tables to keep the geofence join fast at high cardinality.

### Route plans
- `route_plans` + `route_plan_stops` materialise an optimised drive sequence for a tech's day.
- `RouteOptimizerInterface` is pluggable: ships with `NearestNeighborRouteOptimizer`; production deployments can wire a real OR-Tools-backed optimiser.
- `route_visits` are the per-stop execution records; techs check in (`en_route → arrived → completed/skipped`) with the field PWA.
- `TrafficAwareEtaService` (Mapbox / Google Maps integration) refreshes ETAs against live traffic.

### Dispatch board
- `DispatchBoardService` powers the multi-trade dispatch board (see §24) — single board filterable by service line, surfaces qualified candidates from the skill matrix.

---

## 13. Recurring Service Routes

(Phase 15 — janitorial / recurring inspection / route-based PM.)

- `service_routes` define a recurring visit schedule for a set of sites (e.g. "Janitor Crew A: 12 visits/month at sites 14, 27, 31").
- `RouteVisitGenerator` materialises future visits forward through a `generation_horizon_days` window. Runs every 5 minutes via the `route-visit-generator` cron, also marking overdue planned visits as missed.
- `route_visits` per-execution row, with arrival/departure stamps.
- `route_visit_photos` — visit-photo verification heuristics (`PhotoVerifier`) ensure the photos cover the right rooms / fixtures (no photographing the parking lot 12 times).
- QR-scan launch from the field PWA opens the right visit and gates upload.

---

## 14. Towing, Impound, and Roadside Assistance

### Towing
- `TowingPriceMatrix` + `TowingServiceClass` + `TowingServiceType` price out a tow by class (light / medium / heavy duty), service type (winch-out, accident, jump, lockout), and distance.
- `TowingPricingService` computes a quote in the dispatcher's UI before the offer goes out.

### Roadside
- `RoadsideService` integrates the dispatch + ETA + payment flow specifically for roadside calls. Customer can pay via the public payment portal (`/pay/{token}`) before the truck rolls.

### Impound + storage liens
- Impound-case lifecycle tracked through workorders + `release_checklists`.
- `AuctionManagement` views handle auction-eligible vehicles.
- `InventorySpotChecks` surface high-value impounded inventory.
- Storage-lien notice automation: `storage-lien-notices` daily cron flags impound cases that have reached the lien-notice threshold under state-specific rules.

---

## 15. Fleet Operations

For shops servicing customer fleets (towing companies, delivery fleets, municipal vehicles):

- `fleet_units` represent each truck/trailer in a customer fleet.
- `fleet_unit_assignments` track which driver/employee currently has each unit.
- `fleet_unit_readings` capture odometer + engine hours per service event.
- `fleet_unit_downtime` records out-of-service periods (`FleetUnitDowntimeService`).
- `fleet_external_repairs` track repairs done at outside shops with imported documentation.
- `FleetCostReportService` rolls up cost per unit per period — fuel / parts / labor / external — for fleet-management reporting.

---

## 16. Preventive Maintenance (PM)

- `pm_plans` define a maintenance plan template (e.g. "30,000-mile service: oil + air filter + cabin filter + tire rotate").
- `pm_schedules` apply a plan to a specific customer/asset with a cadence (every N miles or every N days).
- `pm_fleet_bindings` apply the same plan to an entire fleet.
- `PmGeneratorService` materialises the next due ticket from each schedule; runs daily at 02:00 via the `pm-generator` cron, advancing the cadence on completion.
- `PmComplianceService` reports on-time PM compliance per customer/fleet for SLA reporting.
- `PmFleetInheritanceService` applies fleet-level PM templates to newly-added units.
- `PmLinkageService` links generated tickets back to source PM schedules so completion advances the schedule.

---

## 17. Tickets & IT Helpdesk

The ticketing primitive predates the IT-helpdesk overlay (Phase 14 / M8) and is shared between facility-management tickets, IT helpdesk tickets, and inspection-defect-driven tickets:

### Core ticket model
- `tickets` — title, description, category, priority, queue, assignee, status, severity (ITIL P1–P4 for IT tickets), affected_users_count, business_impact (required for P1/P2), it_request_kind (`outage`/`incident`/`request`/`question`).
- Lifecycle: `open` → `in_progress` → `pending_*` → `resolved` → `closed`. Reopens permitted within a window.

### Catalog
- `TicketCategory`, `TicketCloseReason`, `TicketResolutionCode`, `TicketFailureCode`, `TicketQueue` — canonical taxonomies.

### SLA
- `TicketSlaPolicy` defines per-priority response + resolution targets, customer-aware (contract-defined SLAs override defaults via `ContractSlaResolver`).
- `TicketSlaClock` tracks per-ticket clock running, paused (waiting on customer), breached. Breached_at stamped by the per-minute `ticket-sla-breach` cron.

### Escalations
- `TicketEscalationRule` — rules like "P1 unacknowledged for 15 min → page on-call manager." Match by category, severity, queue, customer.
- `TicketEscalationService` evaluates rules every 5 min via the `ticket-escalation` cron, recording fires in `ticket_escalation_events`.

### Routing
- `TicketRoutingRule` + `TicketRoutingService` direct new tickets to the right queue based on customer, category, severity.

### Triage
- `TicketTriageService` + `HeuristicTicketTriageScorer` produce a triage suggestion (priority, category) on intake based on title/description heuristics. Ships with a heuristic scorer; an LLM scorer can be swapped in via `TicketTriageScorerInterface`.

### IT helpdesk overlay (Phase 14 / M8)
- ITIL severity (P1–P4) distinct from priority — severity reflects business impact, priority reflects work order in the queue.
- Auto-derivation: P1 if `affected_users_count > 50` or `it_request_kind = outage`; P2 if affected_users 11–50 or `incident`.
- `ItHelpdeskService` enforces the IT-specific validation (P1/P2 require `business_impact`).

### Workorder linkage
- `TicketWorkorderLink` connects a ticket to one or more workorders (e.g. a P1 outage ticket spawns dispatch + parts-fetch + remediation WOs).

---

## 18. Software Inventory & Change Management (CAB)

(Phase 14 — M9 + S3.)

### Software inventory / CMDB
- `software_assets` — the catalog of software products tracked (Microsoft 365, Adobe CC, AutoCAD, custom in-house apps).
- `license_seats` — per-product seat pools with total counts and term dates.
- `license_assignments` — which user holds which seat. Enforces seat-pool capacity at assignment time.
- `installed_software` — per-device installation records (joined to `site_assets` via the polymorphic asset key).
- `SoftwareInventoryService` reports on entitlement ("we have 50 Office seats, 47 are assigned, 3 free"), compliance ("3 unauthorized installs of AutoCAD 2024 detected"), and renewal exposure ("Adobe CC renewal in 22 days, $4,800").

### Change management (CAB)
- `change_requests` — RFCs with title, description, change category, risk assessment, planned window, originating ticket reference.
- `cab_approvals` — per-CAB-member vote ledger with comments.
- `ChangeRequestService` enforces CAB quorum + approval threshold rules before a change can move from `submitted` → `approved`.
- Lifecycle: `draft` → `submitted` → `cab_review` → `approved` / `rejected` → `scheduled` → `in_progress` → `completed` / `failed` / `rolled_back`.

---

## 19. Asset Lifecycle (Lease / Acquisition / Decommission)

(Phase 13 — M3, M4, M5.)

### Asset leases (M3)
- `asset_leases` — lessor terms (start/end, monthly payment in cents, mileage cap for fleet leases, residual + buyout, end-of-lease decision: `renew` / `buyout` / `return` / `replace`).
- Four `alert_*_sent_at` columns make `LeaseExpiryAlertService` idempotent — daily 08:00 cron (`lease-expiry-alerts`) fires the single applicable 90/60/30/0-day notice without doubling up on re-runs.

### Acquisitions (M4)
- `asset_acquisitions` — front-half lifecycle: `draft → quoted → approved → po_issued → received → install_scheduled → installed → activated`, plus `cancelled`.
- Each transition is its own POST endpoint (`/api/asset-acquisitions/{id}/{quote|approve|po|receive|install|activate}`) for clear UI affordances and narrow audit events.
- The terminal `activate` step is admin-only (`asset_acquisitions.activate`) since it links a new asset into the CMDB.
- `AssetAcquisitionService` orchestrates state transitions; transition history written to `audit_logs` (no parallel `_events` table).

### Decommissions (M5)
- `asset_decommissions` — back-half lifecycle: `initiated → wipe → recovery → entitlement → audited → retired`, plus `cancelled`. Optional wipe-or-skip branch at initiation (`requires_wipe` flag).
- Terminal `retire` step is admin-only and atomically flips `site_assets.status='retired'` plus `decommissioned_at` in the same transaction.

---

## 20. Capital Planning

(Phase 9.x — multi-year budget planner for asset replacement.)

### Aging report
- `AgingAssetReportService` rolls up asset age + condition + estimated replacement cost at three scopes (company, division, portfolio).

### Scoring models
- `capital_scoring_models` — tunable models that compute "replace-by year" from asset age, condition, MTBF history, scope-level inflation rate.
- Per-division scoping with fallback to the default model.

### Plans + scenarios
- `capital_plans` — a snapshot scope + horizon (e.g. "HVAC division, FY2026, 5-year plan"). One plan per save; the planner clones to iterate.
- `capital_plan_scenarios` — alternative versions of the same plan (`Baseline`, `Defer 12mo`, `Accelerate urgent`). Each plan auto-mints a Baseline; users add what-ifs. `global_options` JSON blob carries cross-cutting transforms.
- `capital_plan_scenario_overrides` — per-asset deviations within a scenario (`pin_to_year`, `defer_months`, `replacement_estimate_cents_override`, `excluded`). Composed on top of `global_options` at compute time.

### Compute + compare
- `CapitalPlanService::computeScenario` returns a year-by-year capex projection: per-bucket totals as `raw_cents` (today's dollars) and `projected_cents` (inflated to spend year using the scoring model's annual inflation rate, optionally overridden per scenario).
- `compareScenarios` produces a side-by-side delta payload for the React comparison view.

### Recommendation PDF
- `CapitalRecommendationPdfGenerator` (dompdf-backed) emits a customer-facing capital recommendation PDF — what to replace when, at what cost, with photos.

### Editor UI
- `views/capital-plan/CapitalPlanDetail.jsx` exposes the per-scenario overrides editor (UIG-11): "Manage overrides" modal per non-baseline scenario with full add / edit / delete CRUD against the existing backend API.

---

## 21. Property Management Vertical

(Phase 12 — multi-unit residential / commercial property management.)

### Domain tables
- `units` — leasable spaces inside a `sites` row (apartment 4B, suite 200, locker 17).
- `tenants` — lessees, individual or business.
- `tenant_leases` — the join carrying lease terms and the all-important `billing_responsibility` field (`landlord` / `tenant` / `split`) plus per-category `maintenance_terms` JSON.
- `tenant_maintenance_requests` — tenant-side intake doc that becomes a workorder (deliberately separate from the auth-less / reCAPTCHA-gated `estimate_requests` since the property-mgmt request is authed via the tenant portal).

### Routing decision
- `TenantBillingResolver` consumes the active lease and the per-category `maintenance_terms` to decide whose invoice a WO becomes. Default fall-through is `landlord` — matches standard commercial-lease practice.
- The decision is **snapshotted** on the WO and the resulting invoice (`workorders.tenant_billable_party`, `invoices.tenant_billable_party`) at conversion time. Changing the lease later does not silently re-route already-issued invoices.

### Tenant portal
- Dedicated `/tenant` shell with its own sidebar (`tenantMenuItems`).
- Three React views: `views/tenant/MyUnits.jsx`, `MyRequests.jsx`, `NewRequest.jsx`.
- Tenant logs into the unit-scoped portal (gated by `Tenant.portal_user_id`), submits maintenance requests against their unit, sees status history.

### Staff config UI
- `views/settings/PropertyManagement.jsx` mounted at `/cp/settings/property-management` for unit / tenant / lease CRUD by office staff.

### Permissions
- `property.units.{view,manage}`, `property.tenants.{view,manage}`, `property.leases.{view,manage}` enforced inside controllers.

---

## 22. Physical Security Credentials Vertical

(Phase 16 — S1.)

### Domain
- `credential_registers` — issued credentials (card / fob / PIN / mobile / biometric / license-plate).
- `credential_doors` — M:N grant of credential to door, with optional per-grant `access_schedule_id` (e.g. "this contractor's badge works only Mon–Fri 7–18").
- `access_schedules` — recurring access policy (days-of-week + start/end + timezone).
- `programming_logs` — polymorphic config-change audit shared with POS (every credential issue / revoke / schedule-change writes a row).

### Convention
- Doors are not a new entity — they're `site_assets` with `asset_type.code = 'access_door'`, keeping door management inside the existing CMDB.

---

## 23. POS Device Monitoring Vertical

(Phase 16 — S2.)

### Heartbeat ingestion
- `pos_terminals` is the device registry (joined to `site_assets`).
- `pos_heartbeats` is the time-series ledger of received heartbeats.
- Devices push heartbeats over a signed webhook (`PosHeartbeatIngestionService`).

### Stale-heartbeat sweeper
- `pos-stale-sweeper` per-minute cron (`PosTerminalService::sweepStale`) opens an alert ticket for any terminal whose `last_seen_at` exceeded its `stale_after_seconds` threshold. Idempotent — re-firing the same alert is suppressed.

---

## 24. Multi-Trade Operations

(Phase 17 — M10, S10, S11.)

### Skill matrix (S11)
- `skills` — catalog of competencies tied to service lines (HVAC EPA 608, OSHA 10, fiber-optic splicing, pesticide applicator).
- `user_skills` — per-tech proficiency level + cert expiry date. Expiring certs surface in the operations dashboard.
- `SkillMatrixService` is the central read for "who is qualified for service line X right now."

### Multi-trade dispatch board (M10)
- `DispatchBoardService` powers a single board that filters unassigned WOs by service line and surfaces qualified candidates from the skill matrix.
- Removes the prior "one dispatcher per trade" coordination problem.

### Trade KPI dashboards (S10)
- `TradeKpiService` computes per-service-line MTTR (mean time to repair), MTBF, on-time PM rate, first-time-fix rate.
- Dashboards in `views/trade-kpis/` per service line.

---

## 25. Customer Portal & Public Self-Service Surfaces

### Two parallel portal trees
- **Legacy `/portal/*`** — original portal app with `CustomerLayout`, served behind the `customer` role.
- **New `/p/*`** (Phase 2a) — `PortalShell` with theme provider (host-resolved branding) and portal-specific auth provider. Namespaced JWT (separate from staff `auth_token`). `requirePortalAuth` reads the portal-namespaced token only.

### Portal capabilities
- **Account dashboard** — open balance, recent invoices, upcoming appointments.
- **Approvals** (`PortalApprovalService`) — approve/reject pending estimates inline.
- **Billing** (`PortalBillingService`) — view invoices, download PDFs, pay (Stripe/Square/PayPal).
- **Contracts** (`PortalContractService`, `PortalContractSigningService`) — view active contracts, sign new ones.
- **Workorders** (`PortalWorkorderService`) — read-only timeline of in-flight + completed work.
- **Assets** (`PortalAssetService` + `PortalAssetViewService`) — read-only inventory of customer's assets we service.
- **Messaging** (`PortalMessagingService`) — threaded customer↔shop conversation.
- **Notifications** (`PortalNotificationPreferenceService`) — opt in/out of email/SMS reminder categories.
- **CSAT survey** (`PortalCsatService`) — post-job satisfaction form gated by token.
- **ETA promises** (`PortalEtaPromiseService`) — customer-facing ETA tracker for active dispatched jobs.
- **Uploads** (`PortalUploadService`, `PortalUploadStorage`, `PortalUploadValidator`) — customer attaches photos / documents to a request. R-01-hardened: server-managed path, MIME sniffing, ULID filenames, auth-gated stream endpoint.
- **API tokens** (`PortalApiTokenService`) — customer can mint long-lived API tokens for their own integrations (e.g. fleet manager pulling their fleet's open-WO list into BI).
- **Lifecycle** (`PortalLifecycleService`) — invite, activate, deactivate, password reset for portal accounts.
- **SSO** (`PortalSsoService`) — customer-side SSO for enterprise customers with IdPs.
- **Theming** (`PortalThemeService`, `portal_themes`) — per-tenant logo, colour palette, custom CSS.
- **Audit** (`PortalAuditService`) — every portal-side action audited.
- **Permission gating** (`PortalPermissionService`) — strict per-customer site-scoping (R-05); a portal account never sees another customer's work even if the URL is changed by hand.

### Public surfaces (no portal account)
- `/track/{token}` — customer-facing job tracker (status, ETA, tech name).
- `/pay/{token}` — public invoice payment portal.
- `/contract/view?token=...` and `/c/{shortCode}` — public contract sign (token required for state-changing operations as of R-03; short codes read-only).
- `/estimate/view?token=...` — public estimate view + accept/reject/sign flow (R-03 gated on token, R-04 enforces document-hash snapshot at issue).
- `/request-estimate` — reCAPTCHA-gated public new-customer intake.

---

## 26. Subcontractor & Vendor Self-Service Portals

(Phase 18 — C1, C2.)

### Subcontractor portal (C2)
- Token-authenticated public portal (no JWT staff account) where subs accept / decline / start / complete their assignments and upload POD (proof-of-delivery) / photo / signature bundles.
- `subcontractor_portal_tokens` is the bearer-token registry.
- `subcontractor_assignment_pods` carries the captured artifacts.

### Vendor portal (C1)
- Token-authenticated public portal where procurement vendors:
  - Acknowledge POs.
  - Mark line shipments with tracking number + carrier.
  - Upload tracking labels / packing slips / invoices.
- `vendor_portal_tokens` registry.

### Design rationale
- Both portals are deliberately segregated from the JWT stack so a leaked token never opens cross-tenant access. Per-token scope is pinned to a single sub / vendor entity at issue.

---

## 27. Communications & Reminders

### Notification dispatcher
- `App\Support\Notifications\NotificationDispatcher` is the single fan-out point. Templates rendered via `TemplateEngine` with placeholder substitution.
- Channels:
  - **Mail:** `SmtpMailDriver` (production), `LogMailDriver` (dev — writes to log instead of sending).
  - **SMS:** `TwilioSmsDriver` (production), `LogSmsDriver` (dev).
- Per-event template config in `config/notifications.php`. URL placeholders are opaque tokens (`{{estimate_url}}`, `{{tracking_url}}`, `{{sign_url}}`, `{{reset_url}}`, `{{verification_url}}`, `{{login_url}}`, `{{invite_url}}`, `{{invoice_url}}`).
- `notification_logs` audits every send (driver, recipient, template, success/failure, error_message).

### Push notifications
- `PushNotificationService` for mobile-app push (FCM-style) — driver job offers, customer ETA promises, urgent ticket assignments.

### Reminder campaigns
- `reminder_campaigns` define recurring outreach (post-service follow-ups, anniversary specials, expiring-warranty notices, oil-change due reminders).
- `ReminderCampaignService` matches enrollment criteria (last service date, vehicle mileage, customer segment) every 15 minutes via the `reminder-campaigns` cron and sends due messages.
- `reminder_logs` retains per-recipient per-campaign send history (deduped so a customer doesn't get two "your warranty expires next month" emails).
- `reminder_preferences` lets a customer opt in/out per category.

### Webhooks
- `App\Support\Webhooks` provides the signing/verification primitives shared by the payment-gateway webhook receivers and the integration webhook intake.
- `webhook_events` dedupes inbound by signed-hash within provider (`IntegrationWebhookEventRepository`).

---

## 28. Voice Notes

(Phase 10.7 — audio-first field notes for techs.)

### Capture
- Tech records a voice note from the field PWA against a workorder, ticket, or vehicle (or unattached as a memo).
- `VoiceNoteUploadService` handles the multipart file part (`audio`), with MIME sniffing, ULID-based path generation, and storage-root containment (defends against absolute-path planting and symlink escapes — AUD-063 hardening).
- Storage-managed fields (`audio_path`, `audio_mime`, `audio_size_bytes`, `audio_sha256_hash`, `audio_format`) are server-only — any client payload that includes one of those is rejected as an attempted bypass.

### Transcription
- Pluggable `TranscriberInterface`. Ships with `HeuristicTranscriber` (sidecar `.txt` file convention for dev). Production deployments swap in a Whisper / Deepgram / AssemblyAI implementation by replacing the constructor binding.
- Lifecycle: `pending → transcribing → completed` (or `failed` with retry path).
- Re-transcription path overwrites in place without status flip.

### Tags + pinning
- `voice_note_tags` — free-form taggable taxonomy with case + whitespace normalisation.
- Pinned notes float to the top of the WO timeline (operational notes — "two adults present, hostile customer" — stay visible).

### Three views
- **My** — actor's own notes (`/api/voice-notes/my`).
- **All** — cross-shop firehose (`/api/voice-notes/all`, gated on `voice_notes.view_global`, dispatcher / manager / admin only — UIG-10).
- **Pending review** — cron-worker scan path for backlog drains.

### Audio stream
- `GET /api/voice-notes/{id}/audio` is auth-gated (R-01). Returns the bytes with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff` so an intermediary CDN cannot cache PII-adjacent recordings.

---

## 29. Time Tracking & Payroll

### Time entries
- `time_entries` — clock in / out / break with pause-resume semantics. Per-tech, per-day.
- `time_adjustments` capture managerial corrections (forgot-to-clock-out, mis-applied lunch break) with reason + audit trail.

### Technician portal
- `TechnicianPortal.jsx` surface bound to `GET /api/time-tracking/technician/portal`: totals header, active timer card, assigned jobs, recent history (10).

### Payroll
- `payroll_runs` — pay-period containers.
- `payroll_run_entries` — per-employee per-period summary (regular hours, OT, double-time, jobs completed, commission, deductions).
- `payroll_exports` — CSV / OFX / API exports to QuickBooks Payroll, Gusto, ADP.

### Leave
- `leave_requests` — PTO / sick / unpaid leave intake with manager approval workflow.

---

## 30. Warranty Claims

- `warranty_claims` — customer-side intake form linked to a closed WO.
- Lifecycle: `submitted → under_review → approved / denied → resolved`.
- `warranty_claim_messages` — threaded conversation.
- Customer-portal view (`views/customer-portal/WarrantyClaims.jsx`, `WarrantyClaimDetail.jsx`).

---

## 31. Reporting & Analytics

### Catalog
- `ReportCatalogService` enumerates available report types (AR aging, tech productivity, parts profit, fleet cost, PM compliance, retention).

### Saved reports
- `SavedReport` — user's saved filter set + schedule for a particular report. Personal or shared.
- `SavedReportService` orchestrates save / share / favorite.

### Scheduled reports
- `ScheduledReport` — runs a saved report on a cron expression and emails CSV / JSON output to recipient list.
- `scheduled-reports` cron runs every 15 min and dispatches due schedules.
- `report_executions` retains the per-run history.

### Export
- `ReportExportService` produces CSV (default), JSON, and PDF (via dompdf) outputs. Streaming for large result sets so a 100k-row export doesn't OOM.

### Domain reports
- **Customer retention** — `CustomerRetentionReportService` computes lapsed-customer cohorts.
- **Fleet cost** — per-unit per-period roll-up.
- **PM compliance** — on-time PM percentage per fleet / customer.
- **Trade KPIs** — per-service-line MTTR / MTBF / first-time-fix.
- **WIP aging** — work-in-progress dashboard widget.
- **Branch dashboards** — per-branch operational KPIs (`branchesService`).

### Dashboards
- Admin dashboard (`views/dashboard/AdminDashboard.jsx`): branch-filtered overview with quick-action tiles (new invoice / appointment / customer / vehicle), low-stock alerts, recent invoices, recent appointments.

---

## 32. Content Management System (CMS)

A first-class marketing-website CMS lives alongside the operations app, sharing auth and theming:

### Pages and components
- `cms_pages` — URL-routable pages with slug, title, SEO meta, status (`draft / scheduled / published / archived`).
- `cms_components` — reusable building blocks (hero, feature grid, testimonial wall, pricing table, contact form).
- `cms_revisions` — versioned snapshots of pages + components for undo + scheduled publish.

### Rendering
- `CMSRenderingService` renders page → HTML by composing components from the layout JSON.
- `CMSDynamicComponentService` evaluates dynamic-data components (live testimonials, real-time pricing, latest blog posts).
- `CMSCacheService` caches rendered output with surgical invalidation on revision publish.

### Asset bundling
- `CMSAssetBundler` produces per-page CSS+JS bundles with cache-busting hashes.

### Indexing
- `CMSIndexService` maintains a search index over published pages + component contents. Rebuild via `cms-search-reindex` weekly cron.

### Component usage
- `CMSComponentUsageService` tracks where each component is referenced — prevents accidental deletion of an in-use component.

### Editor surfaces
- `views/cms/` React tree for page list, editor, component editor, scheduled publishes, asset library.

### Auth bridge
- `CMSAuthBridge` shares the staff JWT between the operations app and the CMS so a logged-in admin doesn't need to re-auth.

### Roles
- `cms` (read/draft), `cms_editor` (edit/schedule), `cms_publisher` (publish/unpublish/snapshot).

---

## 33. Document Vault

- `views/documents/DocumentVault.jsx` is the per-customer document library: warranty papers, service contracts, receipts, photos, scans.
- Documents tagged with retention policy — eligible documents auto-expire under the data-retention runner.
- Per-document permission checks ensure customer-portal users only see their own.

---

## 34. Service Lines & Multi-Tenancy

(Phase 11 — the abstraction that turned the auto-shop app into a multi-vertical platform.)

### Service lines
- `service_lines` — top-level vertical containers (`auto`, `building`, `property`, `equipment`, `fleet`, `it`, `security`, `pos`, `cleaning`, ...).
- Most domain entities (workorders, tickets, contracts, sites) carry a `service_line_id` so a single tenant can run multiple verticals on one PHPArm instance with cleanly partitioned UIs and reports.
- `ServiceLineService` provides resolution helpers; `SubjectResolver` decides which service line a record belongs to when not explicitly set.

### Settings UI
- `views/settings/SettingsServiceLines.jsx` for tenant admins to enable/disable service lines and configure per-line defaults.

---

## 35. Divisions, Branches, and Chain Rollup

### Divisions
- `divisions` — operational sub-units inside a tenant (geographical or functional). A tech, customer, or asset is optionally division-scoped.
- `DivisionService` enforces division-scoped reads when configured.
- Division CRUD in `views/divisions/Divisions.jsx`. Hard-delete shipped (UIG-9) — all FKs to `divisions.id` are `ON DELETE SET NULL` so deletion is safe.

### Branches
- `branches` — physical shop locations. Multi-branch tenants get per-branch dashboards (`branch_dashboards` config).
- `BranchScope` filters dashboard reads by selected branch.

### Chain rollup
- For chain customers (one parent company with N child sub-companies), `ChainRollupService` produces parent-level aggregates: chain-wide AR, chain-wide WIP, chain-wide spend by service line.

---

## 36. Consolidated Billing

- `consolidated_statements` bundle one calendar month's invoices for an opted-in chain customer into a single statement.
- `ConsolidatedBillingService` composes the statement (per-site detail + chain-wide total + payment instructions).
- `consolidated-monthly-billing` cron runs on the 1st of every month at 02:00.
- Customer portal renders the statement with per-line drill-down to source invoices.

---

## 37. Custom Fields

- Tenants define their own per-entity custom fields (text, number, dropdown, date, multiselect) without code changes.
- `custom_fields` is the schema registry; `custom_field_values` stores per-record values keyed by `(entity_type, entity_id, custom_field_id)`.
- `CustomFieldService` validates writes against the field's type + constraints (required, regex, enum).
- React form helpers render the right widget per field type and surface validation errors inline.

---

## 38. Third-Party Integrations

### Adapter framework
- `IntegrationAdapterInterface` + `AbstractIntegrationAdapter` define the contract: `providerKey()`, `displayName()`, `category()`, `credentialFields()`, `settingFields()`, `defaultCadenceMinutes()`, `testConnection()`, `sync()`.
- `IntegrationAdapterRegistry` registers all adapters at boot.
- `third_party_integrations` table holds per-tenant configured integrations with **encrypted credentials** (FieldCipher, `integration_credentials` domain).

### Shipped adapters
- **QuickBooks Online** (`QuickBooksOnlineAdapter`) — invoice + customer sync.
- **Xero** (`XeroAdapter`) — accounting sync.
- **Google Maps** (`GoogleMapsAdapter`) — geocoding + traffic-aware ETAs.
- **Mapbox** (`MapboxAdapter`) — geocoding + routing alternative.
- **Generic telematics** (`GenericTelematicsAdapter`) — abstract interface for fleet GPS / engine-data feeds.
- **Telecom monitoring** (`TelecomMonitoringAdapter`) — phone/network device monitoring.
- **Access control** (`AccessControlAdapter`) — physical access control system bridge.
- **PartsTech** (`PartsTechAdapter`, separate service) — parts catalog lookup + ordering.

### Sync engine
- `IntegrationService::processDueSyncs` walks every connected integration whose `next_sync_at` has passed, runs the adapter's `sync()`, writes a sync log, advances `next_sync_at` by the integration's cadence, and stamps `last_sync_at` + status.
- `integration-sync` cron runs every 5 minutes.
- `integration_sync_logs` retains per-sync history (start, finish, records-in, error if any).

### Webhook intake
- `IntegrationWebhookService` receives provider webhooks, dedupes on payload hash within provider, and queues for processing.
- `processPending` worker marks rows processed.

### Connection testing
- Operator can hit "test connection" from Settings → Integrations to verify credentials + connectivity before enabling auto-sync. Result flips integration status to `connected` or `error` with the reason captured.

### HTTP timeouts
- `CURLOPT_CONNECTTIMEOUT => 5` enforced on all outbound HTTP from integration adapters and the OIDC client (AUD-075). Caps the TCP-handshake stage so a brownout downstream can't tie up an FPM worker for the full transfer-timeout budget.

---

## 39. Audit Logging & Data Retention

### Audit log
- `audit_logs` is the polymorphic, append-only record of every state-changing service call. Indexed on `(entity_type, entity_id)` and `(actor_user_id, created_at)`.
- `views/audit/AuditLogs.jsx` is the staff-side audit-log explorer with filter UI.
- Used directly as the timeline source for asset acquisitions, decommissions, contract document-changed-accepted events, and any other state-machine transitions.

### Retention policies
- `data_retention_policies` define per-`entity_type` retention rules: keep N years, then delete or archive.
- Each policy carries a query template + the action (delete / archive to S3 / keep but anonymise).
- `RetentionRunner` executes due policies. Runs daily at 03:00 via the `retention-runner` cron.
- `data_retention_runs` retains per-run history (entity_type, rows_processed, started_at, completed_at, error).

### Data cleanup
- `data-cleanup` daily cron purges short-TTL transactional data (`password_resets`, `email_verifications`, `notification_logs`, `audit_logs`, `payment_sessions`, `reminder_logs`) in batched 5000-row chunks (AUD-076 hardening) so a single unbounded DELETE doesn't lock the table for minutes on busy tenants.

### Retention UI
- `views/retention/` for admin to view, edit, and dry-run policies.

---

## 40. Background Jobs (Cron Runner)

A single unified runner (`bin/cron/run.php`) dispatches 21 scheduled jobs. Recommended crontab:

```cron
* * * * * php /path/to/bin/cron/run.php --quiet >> /var/log/phparm-cron.log 2>&1
```

### Lock model (AUD-077)
- File-descriptor `flock(LOCK_EX | LOCK_NB)` on `storage/temp/cron.lock`. The OS releases the lock automatically on process death — a crashed runner can never delay the next tick (replaces the prior 5-minute stale-timestamp window).
- `--force` falls back to `flock(LOCK_EX)` after a non-blocking miss for ad-hoc operator overrides.

### Parallel dispatch
- Due jobs dispatch through `App\Support\Cron\CronDispatcher` via `proc_open()` with non-blocking pipes, capped at 4 concurrent children by default.
- Per-job timeout derived from cron expression: 50 s for `* * * * *`, `step*60-60 s` for `*/N`, 1800 s for hourly+. Jobs may opt into an explicit `timeout` field.
- SIGTERM at deadline, SIGKILL after 5 s grace. Result rows record `timed_out=true` and `exit_code` so the tick summary surfaces failures.

### The 21 jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `reminders` | `*/15 * * * *` | Reminder-campaign send batch |
| `appointments` | `0 * * * *` | Appointment reminder fan-out |
| `inventory` | `0 8 * * *` | Daily low-stock alert email |
| `lien-notices` | `0 7 * * *` | Storage-lien notice flagging |
| `cleanup` | `0 2 * * *` | Short-TTL data purge (batched) |
| `retention-runner` | `0 3 * * *` | Apply retention policies |
| `waterfall-dispatch` | `* * * * *` | Advance waterfall offer state |
| `geofence-processor` | `* * * * *` | Evaluate driver coords against fences |
| `job-density` | `0 * * * *` | Hourly density-heatmap snapshot |
| `cms-search-reindex` | `0 3 * * 0` | Weekly CMS search reindex |
| `ticket-sla-breach` | `* * * * *` | Stamp SLA-breach on running clocks |
| `ticket-escalation` | `*/5 * * * *` | Apply ticket-escalation rules |
| `contracts-renewal` | `0 1 * * *` | Auto-renew + expire contracts |
| `pm-generator` | `0 2 * * *` | Materialise next-due PM tickets |
| `auth-sweep` | `30 3 * * *` | Expire stale SSO + trust tokens |
| `scheduled-reports` | `*/15 * * * *` | Run due saved-report schedules |
| `integration-sync` | `*/5 * * * *` | Pull from connected integrations |
| `lease-expiry-alerts` | `0 8 * * *` | 90/60/30/0-day lease notices |
| `route-visit-generator` | `*/5 * * * *` | Materialise route visits + overdue sweep |
| `pos-stale-sweeper` | `* * * * *` | Open ticket on stale POS heartbeats |
| `consolidated-monthly-billing` | `0 2 1 * *` | Monthly chain-customer statements |

Operator can list (`--list`), run a single job (`--job=NAME`), force past the lock (`--force`), or run silent (`--quiet`).

---

## 41. Settings & Module Administration

### Modules
19 toggleable application modules defined in `config/modules.php`. Tenant admins can enable/disable most modules from `views/settings/ModuleSettings.jsx`:

| Module | Default | Notes |
|--------|---------|-------|
| Core Operations | enabled | Cannot be disabled — customers/vehicles/dashboard |
| Estimates | enabled | |
| Work Orders | enabled | |
| Invoicing & Payments | enabled | |
| Appointments | enabled | |
| Inventory Management | enabled | |
| Towing & Roadside | optional | |
| Impound & Storage | optional | |
| Inspections | optional | |
| Warranty Claims | optional | |
| Time Tracking | optional | |
| Messaging | optional | |
| Reminder Campaigns | optional | |
| Content Management | optional | Marketing site CMS |
| Customer Portal | optional | |
| Reports & Analytics | optional | |
| Service Bundles | optional | |
| Document Vault | optional | |
| Financial Management | optional | GL, reconciliation, bank feeds |

Disabled modules disappear from sidebar nav and gate their endpoints behind a 404. Module dependencies enforced (e.g. you can't enable Customer Portal without Core).

### Settings tree
- `views/settings/SettingsLayout.jsx` mounts a sidebar of settings sub-pages: shop profile, terms & conditions, templates, rejection reasons, pricing, security, notifications, payments, integrations, services, service lines, modules, dispatch, VIN decoder, property management.
- All settings persisted via `App\Support\SettingsRepository` (key/value JSON store with type coercion).

### Tenant settings
- Multi-tenant deployments use `App\Models\Tenant` to scope settings per tenant.

---

## 42. Developer & Operations Tooling

### Tests
- `tests/` directory holds 60+ test files organised by service domain. Each is a self-contained PHP script that runs a fixture suite and reports pass/fail counts.
- Test bootstrap (`tests/test_bootstrap.php`) registers a PSR-4 autoloader and SQLite MySQL-compat shims (`NOW`, `GREATEST`, `LEAST`, named-param emulation).
- Fixture pattern: each test file ships in-memory schema snippets, a permissive AccessGate fake, and per-domain repository fakes. New tests follow the existing pattern (see `tests/CronDispatcherTest.php`, `tests/FieldCipherTest.php`, `tests/VoiceNoteServiceTest.php` as examples).

### Code style
- `composer run phpcs` checks PSR-12 conformance.
- `composer run phpcbf` auto-fixes.

### Frontend
- `npm run dev` — Vite dev server on `localhost:3000` proxying `/api` to the PHP server.
- `npm run build` — production build to `public/assets/`.
- `npm run test:react` — Vitest.

### Docker
- `docker-compose up -d` brings up app on `:8080`, MySQL on `:33060`, Mailhog on `:8025`.

### Migrations
- `php migrate.php` — runs the migration runner against the configured DB. Idempotent; safe to re-run.
- `bin/crypto/rewrap_secrets.php --apply` — one-shot migration to upgrade legacy v0 FieldCipher ciphertext to v1 envelopes.

### Audit + observability
- `App\Support\Observability\` — request-scoped structured-log helpers, request-id propagation.
- `audit-summary.md`, `audit-v2-summary.md`, `audit-findings.md`, `audit-v2-recommendations.md`, `audit-v2-ui-gaps.md` retain the security/perf audit history through closeout.

### Documentation
- `docs/expansion-plan.md` — the original 1.x expansion roadmap (pre-WOMS).
- `docs/woms-expansion-plan.md` + `docs/woms/phase-{11..18}-*.md` — per-phase expansion docs.
- `docs/CMS_INTEGRATION.md`, `docs/CMS_TEMPLATE_RENDERING.md` — CMS-specific docs.

---

## Appendix A — Where to look first

| If you're trying to … | Start here |
|-----------------------|------------|
| Understand the codebase top-down | `CLAUDE.md`, then this file |
| Add a new domain / feature | Pick a small existing route module like `routes/modules/voice_notes.php` as a template |
| Add a new integration | `src/Services/Integrations/ThirdParty/AbstractIntegrationAdapter.php` + register in `bin/cron/integration-sync.php` |
| Add a new cron job | Drop a script in `bin/cron/`, add an entry to the `$jobs` array in `bin/cron/run.php` |
| Add a new permission | `config/auth.php` per role, then `gate->assert($actor, 'new.perm')` in service |
| Add a new portal capability | New `App\Services\Portal\Portal*Service` + route under `routes/modules/portal.php` |
| Add a new vertical | Follow the WOMS phase doc pattern in `docs/woms/` and the Phase 11 service-line precedent |
| Trace a customer-facing request | Start at `src/react/views/`, follow service to `src/services/`, then to `routes/modules/` and `src/Services/` |
| Investigate a security finding | `docs/audit-findings.md` (numeric AUD-NNN) or `docs/audit-v2-recommendations.md` (R-NN follow-ups) |

## Appendix B — Vertical capability matrix

| Vertical | Core data | Specific to this vertical |
|----------|-----------|---------------------------|
| Auto repair | customers, customer_vehicles, workorders, estimates, invoices | VIN decoder, parts cart, core returns, warranty claims |
| Building maintenance | site_assets, workorders, contracts | PM plans, inspections, route plans |
| Property management | units, tenants, tenant_leases, tenant_maintenance_requests | Tenant portal, billing-responsibility resolver |
| Equipment service | site_assets, asset_acquisitions, asset_decommissions, asset_leases | Acquisition/decommission state machines, lease expiry alerts |
| Fleet | fleet_units, fleet_unit_assignments, fleet_unit_readings | Fleet cost reports, external repairs, downtime tracking |
| IT support | tickets (with severity overlay), software_assets, license_seats, change_requests | ITIL severity, CAB voting, software CMDB, licensing |
| Physical security | credential_registers, credential_doors, access_schedules | Programming logs, scheduled access policies |
| POS device monitoring | pos_terminals, pos_heartbeats | Stale-heartbeat sweeper, signed webhook ingestion |
| Recurring services / janitorial | service_routes, route_visits, route_visit_photos | Photo verification, route generator, QR scan launch |
