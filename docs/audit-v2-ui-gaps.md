# Audit v2 — Phase 5: UI Gap Catalog

**Date:** 2026-05-11 (catalog) / 2026-05-12 (UIG-1, UIG-2, UIG-9 closeouts) / 2026-05-13 (UIG-3..UIG-8, UIG-10, UIG-11 closeouts) — **all 11 UI gaps now resolved**
**Plan:** [audit-v2-plan.md](audit-v2-plan.md)
**Findings register:** [audit-findings.md](audit-findings.md) (UI gaps are **not**
appended to the main register — they are a backlog, not security/correctness
defects, per the v2 plan's "Findings register conventions" section.)

## Closeout status

| ID    | Status                | Shipped     | Notes |
| ----- | --------------------- | ----------- | ----- |
| UIG-1 | ✅ Resolved           | 2026-05-12  | `TechnicianPortal.jsx` replaced with real read-only view bound to `/api/time-tracking/technician/portal`. Totals header, active timer card, assigned jobs, recent history (10). |
| UIG-2 | ✅ Resolved           | 2026-05-12  | "View requests" CTA removed from `AdminDashboard.jsx`. The placeholder route remains in routing for now (no inbound link). |
| UIG-9 | ✅ Resolved           | 2026-05-12  | `DELETE /api/divisions/{id}` shipped with `DivisionControllerDestroyTest`; frontend delete button enabled with confirm dialog. Hard-delete is safe — all FKs to `divisions.id` are `ON DELETE SET NULL`. |
| UIG-3 | ✅ Resolved           | 2026-05-13  | `/cp/financial/vendors/create` route and `VendorForm.jsx` deleted (pure `PlaceholderPage` stub, never wired to a backend). |
| UIG-4 | ✅ Resolved           | 2026-05-13  | `/cp/financial/vendors/:id/edit` route deleted (shared the same dead `VendorForm.jsx` component as UIG-3). |
| UIG-8 | ✅ Resolved           | 2026-05-13  | `/cp/financial/vendors` route now redirects to `/cp/procurement/vendors` (the canonical vendor master from Phase 18 / S5). `VendorList.jsx` and the orphaned `financial-vendor.service.js` (called nonexistent `/api/financial/vendors` endpoints) deleted. The CSV importer that was the only working feature on the old page moved into `Settings → Modules → Import Inventory Lookups` alongside the existing Categories / Locations importers, since it seeds the `inventory_lookups` parts-supplier dropdown — a distinct dataset from the procurement vendor master. |
| UIG-5 | ✅ Resolved (deleted) | 2026-05-13  | Investigation found zero inbound references — no notification template, no QR / SMS URL builder, no PHP service generates `/customers/:id` links. Customer deep-links go through the authed `/cp/customers/:id` (`CustomerDetail.jsx`) surface. Public route + `CustomerPublicDetail.jsx` deleted. |
| UIG-6 | ✅ Resolved (deleted) | 2026-05-13  | Same investigation as UIG-5: no inbound references to `/vehicles/:id`. Public route + `VehiclePublicDetail.jsx` deleted. |
| UIG-7 | ✅ Resolved (deleted) | 2026-05-13  | Same investigation: no inbound references to `/vehicles/:id/edit`. Public route + `VehiclePublicEdit.jsx` deleted. The catalog disposition explicitly noted that any future public vehicle-edit surface needs a token-gated trust model designed before building — preserving the placeholder accomplishes nothing toward that goal. |
| UIG-10 | ✅ Resolved          | 2026-05-13  | New `GET /api/voice-notes/all` endpoint with newest-first cross-shop feed (limit/offset, capped at 500/page). Gated on the new `voice_notes.view_global` permission held by dispatcher / manager / admin (technicians + accounting stay scoped to their existing `/voice-notes/my` + per-WO timeline surfaces). Frontend "All" tab now calls `voiceNotesService.all()` instead of falling back to `/voice-notes/my`; a 403 surfaces an inline "switch to Mine" prompt instead of silently returning the actor's own notes. 6 new tests in `VoiceNoteServiceTest.php` (67/67 pass). |
| UIG-11 | ✅ Resolved          | 2026-05-13  | Investigation showed the catalog's "service exposes only `createScenario`" was about the **frontend** service: the backend already had full CRUD (`GET/POST /api/capital-plan/scenarios/{id}/overrides`, `DELETE /api/capital-plan/overrides/{id}`) covered by 12 existing service tests. Added `listOverrides`, `setOverride`, `removeOverride` to `src/services/capital-plan.service.js`. `CapitalPlanDetail.jsx` placeholder Card replaced with a per-scenario "Manage overrides" button (hidden for baseline scenarios since `setOverride` rejects baselines server-side) that opens an editor modal showing the current override list with add / edit / delete UI. Backend POST is upsert keyed on `(scenario_id, site_asset_id)` so editing a row re-sends the full draft for the same asset id — no new endpoints needed. |

## Scope

Phase 5 inventories user-visible gaps in the React frontend:

- Routes that resolve to a `PlaceholderPage` component
- Buttons / actions disabled with a "not yet supported" tooltip
- Tabs / pages that silently fall back to a different endpoint because the
  intended one does not exist on the backend
- Pages with "coming soon" copy where partial functionality is shipped

Severity vocabulary, per the v2 plan:

- **Blocking** — feature is registered in routing/menus but cannot be used at all
- **Degraded** — feature works but obvious capability is missing
- **Cosmetic** — placeholder copy, missing icons, alignment issues

This catalog is documentation only. No code changes are produced by this phase.

## Method

1. Grep `src/react` for `PlaceholderPage` imports → enumerate all routes that
   currently resolve to a placeholder.
2. Grep for `TODO` / `FIXME` / `not yet implemented` / `coming soon` markers
   in JSX.
3. Grep for `disabled` controls with a `title="..."` attribute citing missing
   backend support.
4. For each gap, cross-reference the route in `src/react/router/index.jsx`
   against the navigation surfaces (`src/react/components/layout/Sidebar.jsx`,
   inline `<Link>` usages) to determine whether the placeholder is actually
   reachable from the live UI or only by direct URL.

## Blocking gaps

These are reachable from the live UI (sidebar nav or inline links from real
pages) and resolve to a `PlaceholderPage`. A user clicking through ends up on
a "this React screen is a placeholder during the migration" card.

| ID    | Route                                  | View file                                  | Reached from                                  | Notes                                                                                                                                       |
| ----- | -------------------------------------- | ------------------------------------------ | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| UIG-1 | `/cp/my-time`                          | `src/react/views/time/TechnicianPortal.jsx` | Technician sidebar (`Sidebar.jsx:224`)        | Top-level item in the technician role's sidebar — every technician sees a dead link as the second item under their dashboard.               |
| UIG-2 | `/cp/inventory/pull-requests`          | `src/react/views/inventory/PullRequestList.jsx` | "View requests" button on `AdminDashboard.jsx:565` | Admin dashboard has a prominent CTA that lands on a placeholder.                                                                            |
| UIG-3 | `/cp/financial/vendors/create`         | `src/react/views/financial/VendorForm.jsx` | `/cp/financial/vendors` "coming soon" card    | Old Vue-mirror create flow; the live vendor master is `/cp/procurement/vendors` (Phase 18 / S5). Either redirect or wire — see UIG-4.       |
| UIG-4 | `/cp/financial/vendors/:id/edit`       | `src/react/views/financial/VendorForm.jsx` | (no inbound link)                             | Same component as UIG-3. Probably should be removed entirely now that `/cp/procurement/vendors` is the canonical surface.                   |

## Blocking gaps — public routes (reachable only by direct URL)

These three placeholder routes are declared `auth: 'public'` but are not
linked from anywhere in the React tree. They may be reachable via emails / SMS
/ printed QR codes that hand out the URL. **Investigation needed** to confirm
whether any backend notification template generates links to these paths
before deciding to wire or delete.

| ID    | Route               | View file                                          | Notes                                                                                                                                                                      |
| ----- | ------------------- | -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| UIG-5 | `/customers/:id`    | `src/react/views/customers/CustomerPublicDetail.jsx` | Distinct from authed `/cp/customers/:id` (`CustomerDetail.jsx`, fully implemented). Public placeholder.                                                                  |
| UIG-6 | `/vehicles/:id`     | `src/react/views/vehicles/VehiclePublicDetail.jsx` | No authed counterpart found. Likely a customer-facing read-only summary intended for QR-code / email links.                                                                |
| UIG-7 | `/vehicles/:id/edit` | `src/react/views/vehicles/VehiclePublicEdit.jsx`  | Public *edit* would need a token-gated trust model; design this before building. May make more sense to delete the route.                                                  |

## Degraded gaps

Feature works but an obvious capability is missing.

| ID     | Route                              | View file                                              | Missing capability                                                                                                                                                      |
| ------ | ---------------------------------- | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| UIG-8  | `/cp/financial/vendors`            | `src/react/views/financial/VendorList.jsx:43-47`       | No list / detail / edit / delete — only CSV import. Card explicitly says "Vendor management is coming soon in the React app." Live alternative: `/cp/procurement/vendors`. |
| UIG-9  | `/cp/divisions`                    | `src/react/views/divisions/Divisions.jsx:170-180`      | Delete button is rendered but `disabled` with `title="Delete not yet supported by API"`. Backend confirmed: `routes/modules/divisions.php` has GET/POST/PUT but no `DELETE /api/divisions/{id}`. **Backend gap surfacing as a UI gap.** |
| UIG-10 | `/cp/voice-notes` ("All" tab)      | `src/react/views/voice-notes/VoiceNotes.jsx:131-134`   | The "All" tab silently falls back to `/voice-notes/my` because the backend exposes no global feed. Documented inline as TODO. The frontend already passes a `scope: 'all'` hint so the backend can opt in without a frontend change. |
| UIG-11 | `/cp/capital-plan/plans/:id`       | `src/react/views/capital-plan/CapitalPlanDetail.jsx:341-349` | "Per-scenario line items are not yet available here." Service exposes only `createScenario` — no per-line-item edit endpoint. Read-only display rendered.            |

## Cosmetic gaps

None catalogued in this pass. The router contains commented section headers
(`// ── WOMS expansion stubs (Phase A foundation pass) ──`) that may give the
impression the routes underneath are stubs, but every one of them
(CRM, Contracts, Tickets, PM, Assets, Procurement, Software, Change
management, Security credentials, POS, Skills, Dispatch board, Consolidated
billing, Chain rollup, Trade KPIs, Fleet, Routing, Capital plan, Divisions,
Subcontractors, Voice notes, Custom fields, Integrations, SSO, Security
events, Retention, ETA promises) resolves to an implemented view. The
"stub" comment is historical residue from the foundation pass that
introduced the route group; the views were filled in subsequently. Worth
removing the comment in a future docs pass to avoid confusing future
readers.

## Cross-checks performed

- **Form handlers** — grep for `onSubmit={() => {}}`, `alert("Not implemented")`,
  `console.log("TODO")` patterns: no matches.
- **No-op click handlers** — grep for `onClick={() => {}}` / `onClick={noop}`:
  no matches.
- **Disabled controls** with explanatory tooltip: only one match (UIG-9).
- **TODO/FIXME comments in JSX**: only the three already cited (UIG-9 Divisions,
  UIG-10 voice-notes, UIG-11 capital-plan).
- **`PlaceholderPage` consumers**: 6 (UIG-1 through UIG-7, with UIG-3 and UIG-4
  sharing one component).

## Not surveyed in this phase

- **Vue-side parallel app** — the `src/cms-php` checkout cited in the v2 plan as
  out-of-scope; not present in this workspace.
- **Mobile app** (`mobile/`) — separate Expo project; out of scope for the v2
  audit per the prior baseline.
- **Email / notification templates** that may link to the public placeholder
  routes (UIG-5 through UIG-7). A separate sweep over
  `src/Support/Notifications/templates` would be required to confirm.

## Recommended dispositions

These are recommendations, not commitments. The v2 plan is explicit that
Phase 5 produces a backlog only — the actual decisions to wire vs. delete vs.
redirect should be made by the relevant feature owners.

| ID     | Suggested next step                                                                                                              |
| ------ | -------------------------------------------------------------------------------------------------------------------------------- |
| UIG-1  | Either wire `TechnicianPortal` to `/api/time-tracking/my` (which exists) or remove from sidebar until the page is ready.        |
| UIG-2  | Hide the "View requests" CTA on `AdminDashboard.jsx:565` until the page is implemented, or remove the route.                    |
| UIG-3  | Delete the `/cp/financial/vendors/create` route — `/cp/procurement/vendors` is the canonical create surface.                    |
| UIG-4  | Same — delete the `/cp/financial/vendors/:id/edit` route.                                                                       |
| UIG-5  | Investigate whether any notification template generates `/customers/:id` links before deciding (wire vs. delete).               |
| UIG-6  | Same — investigate notification surface for `/vehicles/:id`.                                                                    |
| UIG-7  | Same, plus design the token-gated trust model before building any public edit page.                                              |
| UIG-8  | Either redirect `/cp/financial/vendors` to `/cp/procurement/vendors` or finish the React port. Two parallel vendor surfaces is confusing. |
| UIG-9  | Implement `DELETE /api/divisions/{id}` (with FK-restricted soft-delete if divisions are referenced from other tables).          |
| UIG-10 | Implement a `GET /api/voice-notes/all` endpoint that respects the `scope=all` query hint the frontend already passes.           |
| UIG-11 | Implement `POST/PUT/DELETE /api/capital-plan/plans/:planId/scenarios/:scenarioId/line-items` and a per-scenario edit page.       |

## Future work

- Notification-template sweep for UIG-5/6/7 reachability.
- Mobile app UI gap pass (separate effort, separate audit).
- Remove the misleading "WOMS expansion stubs" comment in
  `src/react/router/index.jsx:453` once an owner confirms it's safe.
