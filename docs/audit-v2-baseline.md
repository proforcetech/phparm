# Audit v2 Baseline

Baseline date: `2026-05-10`
Window covered: `2026-04-08` (prior closeout) → `2026-05-10`

## Change volume

| Metric | Value |
|--------|------:|
| Commits in window | 56 |
| Files changed | 1,184 |
| Lines added | 188,944 |
| Lines deleted | 1,908 |
| New PHP service files | 311 |
| New PHP model files | 125 |
| New PHP route module files | 48 |
| New PHP cron jobs | 12 |
| New React view files | 105 |
| New React component files | 9 |
| New React API service files | 51 |
| New tests | 66 |
| New migrations | 86 (097–182) |

The system has roughly doubled in scope. Roughly 99% of lines added are net
new code, not modifications — meaning most of the v2 audit surface area is
brand-new and was not seen by the v1 audit at all.

## New domains added

### Backend service domains under `src/Services/` (37 net new)

Assets, Auth, CapitalPlan, ChainRollup, ChangeManagement, ConsolidatedBilling,
Contracts, Crm, CustomFields, Dashboard, DispatchBoard, Division,
EstimateRequest, Fleet, Inspection, Integrations, Integrations/ThirdParty,
POS, Pm, Portal, Procurement, PropertyManagement, Reporting, Retention,
Routing, Security, ServiceLine, ServiceRoutes, Skills, SoftwareInventory,
Sso, Subcontractor, Tickets, TradeKpis, VoiceNotes, Workorder/Kit.

### Frontend view domains under `src/react/views/` (37 net new)

assets, auth, branch-dashboards, capital-plan, chain-rollup,
change-management, contracts, crm, custom-fields, customer-portal,
dispatch-board, divisions, eta, fleet, integrations, pm, portal, pos,
procurement, public, retention, routing, security, service-routes, skills,
software-inventory, sso, sub-portal, subcontractors, tenant, tickets,
trade-kpis, vendor-portal, voice-notes (plus modifications to existing
estimates, customers, invoices, settings).

### New route module files under `routes/modules/`

48 new files. The route layer now uses a per-module file pattern instead of
a single monolithic `routes/api.php`. The README under `routes/modules/`
documents the convention.

### New cron jobs under `bin/cron/`

`auth-sweep.php`, `consolidated-monthly-billing.php`, `contracts-renewal.php`,
`integration-sync.php`, `lease-expiry-alerts.php`, `pm-generator.php`,
`pos-stale-sweeper.php`, `retention-runner.php`, `route-visit-generator.php`,
`scheduled-reports.php`, `ticket-escalation.php`, `ticket-sla-breach.php`.

### New `Support/` modules

- `Auth/PasswordExpirationPolicy.php`
- `Auth/Policy.php` and `Auth/PolicyRegistry.php` (new policy framework)
- `Auth/SensitiveSettings.php`
- `Auth/StepUpService.php` and `Auth/StepUpRequiredException.php` (TOTP step-up)
- `Crypto/FieldCipher.php` (field-level encryption — first appearance in repo)
- `Http/IpAddressResolver.php` (referenced by AUD-001 fix)
- `Http/RouteContext.php`
- `Pdf/CapitalRecommendationPdfGenerator.php`
- `Pdf/EstimatePdfGenerator.php`

## High-risk surfaces added in this window

These get priority in Phase 1:

1. **E-sign and forensics** — contracts, estimates, workorders. Token entropy,
   replay protection, geo data retention, signature integrity.
2. **Portal phases 2a–2f** — tenant host gate, RBAC tiers, SSO with global +
   per-company providers, API tokens, audit trail. Privilege boundaries
   between global and per-company configuration are the main risk.
3. **TOTP step-up** for sensitive settings writes. Bypass risk if step-up
   verification can be replayed or shared across endpoints.
4. **Field-level crypto** (`Support/Crypto/FieldCipher.php`) — first appearance
   in repo. Key management, IV reuse, ciphertext authentication.
5. **48 new route module files** — consistency of middleware application
   (auth, rate limiting, CSRF) across all the new modules.
6. **Public estimate-request photo upload** — already audited as AUD-005;
   re-verify the validator is still in the request path.
7. **Vendor portal and subcontractor portal** — new external-user surfaces.
8. **Property management tenant portal** — another external-user surface.
9. **Voice notes** — file upload + storage pattern needs the AUD-005
   treatment.
10. **Third-party integration framework** — outbound HTTP, credential
    storage, webhook ingest.

## Re-verify list — prior findings whose code has been touched

30 of the 31 distinct files referenced by AUD-001 through AUD-062 have been
modified since the closeout. The high-severity items below get re-verified
in Phase 1 first; medium and low get spot-checks only if Phase 1 turns up
related new findings.

### High-priority re-verification (security/high or error_handling/high)

| Finding | Category | Files touched since closeout |
|---------|----------|------------------------------|
| AUD-001 | security/high | `Request.php`, `Middleware.php`, `LoginRateLimiter.php` |
| AUD-002 | security/high | `PasswordResetRepository.php`, `EmailVerificationRepository.php` |
| AUD-006 | security/high | `routes/api.php`, `SquareGateway.php`, `PayPalGateway.php` |
| AUD-008 | error_handling/high | `PaymentProcessingService.php`, `SquareGateway.php`, `PayPalGateway.php` |
| AUD-009 | error_handling/high | `PaymentProcessingService.php`, `database/install/install.sql` |
| AUD-010 | error_handling/high | `PaymentProcessingService.php` |
| AUD-012 | error_handling/high | `InvoiceService.php`, `database/install/install.sql` |
| AUD-061 | security/high | `routes/cms.php`, `public/index.php` |

### Medium-priority re-verification (security/medium or error_handling/medium)

AUD-003, AUD-004, AUD-005, AUD-007, AUD-011, AUD-062.

### Low-priority re-verification

All performance findings AUD-013 through AUD-060 — touched files include
nearly the entire `routes/api.php`, `Workorder/`, `Dashboard/`, and
`Financial/` trees. These get spot-checks only.

## Files NOT touched since closeout

The single prior-finding location not modified since 2026-04-08 is the React
dashboard SQL fixture at `src/react/views/dashboard/install.sql` (referenced
by AUD-012). Its part of AUD-012 stays trusted; the PHP side gets re-verified.

## Test infrastructure status

- 66 new tests added since closeout. Many require `pdo_sqlite`.
- The `pdo_sqlite` blocker noted in v1 closeout is unchanged in this
  workspace; targeted regression scripts that need a database remain
  blocked locally and must run in CI/staging.
- `EstimateCreationIntegrationTest.php` is broken on `main` due to a
  SQLite/MySQL `NOW()` dialect mismatch in production code (separate from
  this audit; pre-existing).

## Configuration drift to watch

Per the v1 closeout's residual-risk list, these env settings are still
load-bearing for security guarantees and must be checked again in v2:

- `TRUSTED_PROXIES` — required for AUD-001 IP normalization to work
- `APP_URL` — required for AUD-004 redirect canonicalization
- Payment webhook credentials per gateway — required for AUD-008 fail-closed
- Any new keys introduced by the field-level crypto support module

## What this baseline enables

Phase 1 (security delta) starts from a clear scope:

- New surfaces from §"High-risk surfaces" above
- Re-verification list from §"Re-verify list" above

Phase 4 (stale code) starts knowing the route layer split into modules, so
orphan-route detection must scan all 48 module files, not just `api.php`.

Phase 5 (UI gap catalog) starts knowing 37 new view domains exist; many of
those domains shipped backend-first and may have only stub UIs. That's the
likely source of the largest gap cluster.
