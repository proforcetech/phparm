# Audit Plan — 2026-05-14

## Purpose

Audit the current PHPArm/WOMS codebase for feature gaps, UI gaps, performance issues, and security issues, using the documentation as the source of intended placement and behavior.

This pass starts after the v1 and v2 audit closeouts. It should not re-open closed findings unless current code has regressed. New findings continue from the existing register as `AUD-078+`; UI-only backlog items use `UIG-12+`.

## Documentation Map

The docs establish these canonical locations:

| Concern | Location |
| --- | --- |
| PHP config | `config/` |
| Database schema and migrations | `database/`, especially `database/migrations/` |
| HTTP entry points | `public/index.php`, `router.php` |
| API route bootstrap | `routes/api.php` |
| Per-domain API routes | `routes/modules/*.php` |
| CMS routes | `routes/cms.php` |
| Backend services/controllers/repositories | `src/Services/<Domain>/` |
| Shared auth, HTTP, crypto, cron, security helpers | `src/Support/` |
| Domain models | `src/Models/` |
| React app routes/views | `src/react/router/`, `src/react/views/` |
| React API clients | `src/services/` and `src/services/portal/` |
| React support code | `src/react/components/`, `src/react/hooks/`, `src/react/stores/` |
| Scheduled jobs | `bin/cron/` |
| Mobile app | `apps/mobile/` |

Key planning docs reviewed:

- `README.md`
- `APP.md`
- `feature-path.md`
- `docs/audit-plan.md`
- `docs/audit-v2-plan.md`
- `docs/audit-summary.md`
- `docs/audit-v2-summary.md`
- `docs/audit-v2-ui-gaps.md`
- `docs/audit-v2-stale-code.md`
- `docs/expansion-plan.md`
- `docs/woms-expansion-plan.md`
- `docs/woms-service-lines.md`
- `docs/woms/phase-11-service-lines.md` through `docs/woms/phase-18-vendor-sub-self-service.md`
- `routes/modules/README.md`

## Current Baseline

- Current branch: `main`
- Current HEAD: `1b67271 fix(ui): restore missing Link import in AdminDashboard (regression from 303cbbf)`
- Prior audit state: v1 and v2 are documented as closed in `docs/audit-summary.md`.
- Working tree note: untracked WOMS docs plus `expansion.md` and `phparm.sql` are present. Treat them as user-owned unless explicitly asked to stage or change them.

## Audit Phases

### Phase 0 — Baseline and Drift

Tasks:

- Confirm documented file placement against current code.
- Compare `routes/modules/*.php` with the loader list in `routes/api.php`.
- Identify documentation that still describes old or incomplete placement.
- Inventory open TODOs, placeholders, disabled actions, and direct routes that are still user-visible by URL.

Deliverables:

- Updated execution notes in this document.
- New findings where there is enough evidence.

### Phase 1 — Feature Gap Audit

Tasks:

- Build a feature matrix from `feature-path.md`, `APP.md`, `docs/expansion-plan.md`, and WOMS phase docs.
- For each feature area, verify the expected migration, backend service/controller, route module, React API client, React view, sidebar/router entry, cron job, and tests.
- Separate true gaps from deliberately deferred scope.

Priority domains:

- Inventory pull requests, mobile technician/driver MVPs, customer portal `/p/*`, asset lifecycle, property management, IT support, service routes, security/POS, consolidated billing, vendor/subcontractor portals.

### Phase 2 — UI Gap Audit

Tasks:

- Grep for `PlaceholderPage`, `PortalSoon`, `coming soon`, TODO/FIXME markers, disabled buttons, no-op handlers, and direct routes with incomplete screens.
- Cross-reference each route with navigation in `src/react/components/layout/Sidebar.jsx`, portal layout navigation, dashboard CTAs, and inline `Link` usage.
- For each gap, classify as:
  - `blocking`: linked from live navigation and unusable.
  - `degraded`: usable but missing core behavior.
  - `direct-url`: registered route has no live inbound link but is still routable.

### Phase 3 — Performance Audit

Tasks:

- Review route/bootstrap cost in `public/index.php`, `routes/api.php`, `routes/cms.php`, and route modules.
- Review common hot paths for N+1 queries, unbounded list endpoints, missing pagination caps, expensive synchronous work, repeated settings reads, and report/dashboard aggregation cost.
- Review cron locking, idempotency, timeout behavior, and duplicate work risk.
- Review frontend over-fetching, repeated polling, missing debounce, and build payload risk.

Priority domains:

- Dashboards, workorders, tickets/SLA, portals, reports, inventory, service routes, POS heartbeats, consolidated billing, cron runner.

### Phase 4 — Security Audit

Tasks:

- Review auth and authorization consistency across route modules and public endpoints.
- Re-check public token flows, short-code flows, upload paths, public portals, vendor/subcontractor portals, tenant portal, POS heartbeat HMAC, payment webhooks, and CMS rendering.
- Review branch/company/site/portal scoping in repositories and services.
- Search for risky primitives: path handling, outbound HTTP, shell execution, unsafe HTML rendering, secret logging, token persistence, missing rate limiting, missing CSRF boundaries.

Priority domains:

- `src/Support/Auth`, `src/Support/Http`, `src/Support/Crypto`, `src/Support/Security`, `src/Services/Portal`, `src/Services/Procurement`, `src/Services/Subcontractor`, `src/Services/POS`, `src/Services/ServiceRoutes`, `src/Services/CMS`, upload services, public routes.

### Phase 5 — Verification

Tasks:

- Run low-risk repository-wide checks first: route/module comparison, placeholder inventory, lint targeted changed/high-risk PHP files, `npm run build`.
- Run targeted tests for any area with a confirmed finding or fix.
- Record commands and failures. Do not treat environment-dependent failures as findings without code evidence.

## Execution Notes

### 2026-05-14 — Phase 0 Started

Completed:

- Reviewed core docs and prior audit closeouts.
- Confirmed route-module loader consistency: 47 module files exist and all 47 are loaded by `routes/api.php`.
- Confirmed prior audit state says all v2 security/performance findings are resolved or partially resolved, and all 11 v2 UI gaps are documented as resolved.

Initial confirmed observations:

- `routes/modules/README.md` is stale: it says only `customer_retention.php` and `modules_and_user_groups.php` are currently migrated, but the repository now has 47 loaded route modules. This is documentation drift, not a code defect.
- `src/react/views/inventory/PullRequestList.jsx` still renders `PlaceholderPage` at `/cp/inventory/pull-requests`. The backend endpoints and `src/services/pull-request.service.js` already exist. Prior v2 work removed the dashboard CTA, but the registered route remains directly accessible and incomplete. Track as `UIG-12` unless a later reachability check finds live navigation into it.
- `src/react/views/portal/PortalSoon.jsx` remains in the tree, but the current `/p/*` router does not reference it. Treat related portal shell comments as stale-comment drift unless a later route-level check finds a live placeholder.
- Public estimate-request photo uploads have regressed from the prior `AUD-005` fix path: `routes/api.php` still handles `$_FILES['photos']` directly, preserves caller-controlled extensions, moves the file before MIME inspection, and does not call `PublicEstimatePhotoUploadValidator`. Recorded as `AUD-078`.
- Document Vault create/update uploads store files under `public/uploads/document-vault` using caller-controlled extensions, no explicit size cap, and MIME detection only after `move_uploaded_file()`. The route is authenticated and permission-gated, but the stored files are directly web-addressable. Recorded as `AUD-079`.
- `npm run build` succeeds, but Vite emits a chunk-size warning for a single `main` bundle at about 3.5 MB minified / 825 kB gzip. `src/react/router/index.jsx` eagerly imports the route tree, and no `React.lazy`, `Suspense`, or dynamic import usage was found in the router. Recorded as `AUD-080`.

Verification run:

- `php -l routes/api.php` — passed.
- `php tests/PublicEstimatePhotoUploadValidatorTest.php` — passed.
- Route-module loader comparison — passed after parsing the live `$routeModule` array: 47 module files, 47 loaded, no missing or extra entries.
- `npm run build` — passed with Vite chunk-size warning described in `AUD-080`.

### 2026-05-14 — Remediation Started

Completed:

- Corrected `routes/modules/README.md` so the migrated-module list matches the 47 loaded route modules.
- Resolved `UIG-12`: `/cp/inventory/pull-requests` now renders a real React list with filters, summary cards, pagination, workorder links, and status actions using `src/services/pull-request.service.js`.
- Resolved `AUD-078`: public estimate-request photo uploads now use `PublicEstimatePhotoUploadValidator` before persistence and derive stored extensions from validated MIME.
- Resolved `AUD-079`: Document Vault uploads now use a hardened upload service, store new files outside `public/`, and serve downloads through authenticated API.
- Started `AUD-080`: CMS and settings screens are route-lazy-loaded. The main bundle shrank from about 3.5 MB minified / 825 kB gzip to about 2.83 MB minified / 673 kB gzip, but the Vite large-chunk warning remains.

Verification run:

- `php -l routes/api.php` — passed.
- `php -l src/Services/EstimateRequest/PublicEstimatePhotoUploadValidator.php` — passed.
- `php -l src/Services/Documents/DocumentVaultUploadService.php` — passed.
- `php tests/PublicEstimatePhotoUploadValidatorTest.php` — passed.
- `php tests/PublicEstimatePhotoUploadRouteWiringTest.php` — passed.
- `php tests/DocumentVaultUploadServiceTest.php` — passed.
- `npm run build` — passed; large-chunk warning remains and is tracked under `AUD-080`.

Next execution targets:

- Continue feature/UI matrixing beyond the first placeholder scan, especially mobile technician/driver MVPs, asset helper TODOs, and WOMS phase docs.
- Continue security scans for the other upload implementations found by `move_uploaded_file()`/`is_uploaded_file()` search.
- Run build/lint/test verification for the audited baseline and record environment failures separately from code findings.
