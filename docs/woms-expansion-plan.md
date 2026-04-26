# WOMS Expansion Plan

**Status:** Draft, 2026-04-25
**Supplements:** [`expansion-plan.md`](./expansion-plan.md) (Phases 0-10 complete)
**Companion:** [`woms-service-lines.md`](./woms-service-lines.md) — per-trade detail

---

## Why this plan exists (and what it isn't)

The existing `expansion-plan.md` has already shipped the generic field-service platform: companies/sites/contacts hierarchy, site assets with parent/child + QR + lifecycle, tickets with SLA + escalation, contracts with e-sign + entitlements + consumption ledger, a PM engine that handles frequency / meter / condition triggers, fleet maintenance for both owned and customer fleets, FIT inspections compliance, capital planner with multi-year budgeting, BI dashboards, PWA parity, SSO + MFA, configurable data retention, and a third-party integrations stack.

That platform is **trade-agnostic**. The next pivot is to **explicitly serve eight verticals** from one codebase — Auto Repair / Roadside / Towing (existing), Building & Property Repair, Property Management, Equipment Repair, Customer Fleet Management (with leases), IT Support, Security / Cameras / Access Controls, Point of Sale, and Commercial Cleaning — and to close the **asset lifecycle loop** so the same system handles **acquisition, deployment, maintenance, and decommissioning** of every asset under contract.

This document scopes only the **delta** between what exists and what the new vision needs. It does not re-plan completed phases.

---

## Vision

Become the **single point of contact** a commercial or industrial customer hires to manage every piece of physical and digital infrastructure across their operation:

- One number to call, one portal to log into, one bill at month-end — regardless of which trade does the work.
- Every asset tracked from purchase order through retirement, with the contracts, PMs, and warranty data attached at every step.
- Service contracts and SLAs drive automated dispatch, billing, and renewal.
- Per-trade specialists (HVAC tech, IT tech, security installer, cleaner, etc.) work the same backbone with workflows tailored to their craft.
- Customers running multi-site operations (retail chains, commercial real estate portfolios, fleet operators) get a roll-up view across every site and trade.

---

## Service line summary

Detail in [`woms-service-lines.md`](./woms-service-lines.md). High-level shape:

| Service line | WO model | Asset-heavy | Recurring | Status |
|---|---|---|---|---|
| Auto Repair / Roadside / Towing | Existing repair-order flow | Vehicles | One-off | Production |
| Building & Property Repair | Trade WO against site asset | Buildings, fixtures | One-off + PM | Bones exist |
| Property Management | Tenant-driven WO + lease admin | Units, common areas | Yes (rent + turnover) | Missing tenancy primitives |
| Equipment Repair (general) | RO against equipment asset | Equipment | One-off + PM | Bones exist |
| Customer Fleet Management | PM + leases + telematics | Vehicles | Yes (PM + lease) | PM done; leases missing |
| IT Support | Helpdesk ticket → resolution | CIs, software | Yes (patching, monitoring) | Missing CMDB + change mgmt |
| Security / Cameras / Access Controls | Install + programming + audit | Devices, credentials | Audit cycles | Missing programming/credential records |
| Point of Sale | Device health + transaction integrity | Terminals | Continuous monitoring | Missing heartbeat + monitoring |
| Commercial Cleaning | Route-based recurring service | Sites, areas | Yes (daily/weekly) | Missing route/visit primitives |

---

## Capability gap analysis

| Capability | Existing platform | Gap for WOMS vision |
|---|---|---|
| Companies / Sites / Contacts | ✅ Complete | None |
| Site assets (parent/child, QR, lifecycle) | ✅ Complete | Need trade-specific asset type templates |
| Work orders | ✅ Auto-repair semantics | Need trade-aware WO types (project, install, recurring visit, helpdesk-derived, change request) |
| Tickets w/ SLA + escalation | ✅ Complete | Need helpdesk-grade severity (P1-P4), change-management workflow, IT-specific portal wizard |
| Contracts w/ entitlements + consumption | ✅ Complete | Need recurring-service contracts (cleaning); usage-billed contracts (managed services) |
| PM engine | ✅ Frequency + meter + condition | Need route-based recurring (cleaning); IT-style scheduled patching |
| Fleet maintenance | ✅ Maintenance + telematics | Need lease management (terms, payments, mileage caps, end-of-lease) |
| Customer portal | ✅ Complete | Need multi-site chain rollup UX; trade-specific request wizards |
| Inspections | ✅ Templates + compliance | Need install-completion checklists per trade |
| Capital planner | ✅ Lifecycle scoring + multi-year plans | Need procurement integration (auto-PO from approved capital plans) |
| Procurement (PO lifecycle) | ⚠️ Partial (vendor catalog, stock orders) | Need full customer-billable PO flow |
| **Asset acquisition workflow** | ❌ Missing | NEW — quote → approval → PO → receipt → install → activate |
| **Asset decommissioning workflow** | ❌ Missing | NEW — retire → wipe (IT) → recovery (return / e-waste / resale) → contract update → audit |
| **Asset lease records** | ❌ Missing | NEW — lease docs, payments, mileage caps, end-of-lease decisions |
| **Tenants / units / tenant leases** | ❌ Missing | NEW — property-management primitives separate from asset leases |
| **IT CMDB + software inventory** | ⚠️ Assets exist | NEW — software assets, license seats, assignments |
| **IT change management** | ❌ Missing | NEW — RFC + CAB approval + scheduled change windows |
| **Security: credentials + programming** | ❌ Missing | NEW — credential register, programming log, scheduled access policies |
| **POS heartbeat / monitoring** | ❌ Missing | NEW — webhook ingestion, stale-heartbeat ticket auto-create |
| **Cleaning route management** | ❌ Missing | NEW — routes, scheduled visits, scan-on-arrival, photo verification |
| **Multi-trade dispatch** | ⚠️ Single-skill dispatch | Skill-matching across trades, trade-filtered boards |
| **Consolidated customer invoicing** | ⚠️ Per-WO invoicing exists | Monthly bill spanning all WOs/contracts/recurring services per customer |

---

## MoSCoW feature backlog

Items below are the **net-new** capabilities required for the vision. Anything already complete in `expansion-plan.md` is intentionally not repeated.

### Must Have

These define the minimum viable WOMS expansion. Anything missing from this list means we cannot credibly market the platform to a non-auto-repair customer.

| ID | Capability | Rationale |
|---|---|---|
| **M1** | Service-line tagging on every WO/ticket/contract/asset (FK to `service_lines` lookup) | Without it, dashboards, dispatch, and reports can't separate HVAC work from IT work from cleaning visits. Foundational. |
| **M2** | Trade-aware WO types: `corrective`, `preventive`, `inspection`, `install`, `project`, `recurring_visit`, `change_request` | Each trade carries different required fields via the existing custom-fields engine. |
| **M3** | Asset lease records — start/end, payment schedule, mileage cap, residual value, lessor, end-of-lease alerts | Required for customer fleet management beyond just maintenance, and for IT/security/POS equipment that customers lease rather than own. |
| **M4** | Acquisition workflow — quote → customer approval → vendor PO → receipt → install WO → asset activation → contract entitlement update | Closes the front half of the lifecycle loop. Currently fragmented across estimates/inventory/WO. |
| **M5** | Decommissioning workflow — retirement initiated → IT wipe (if applicable) → recovery (return-to-vendor / e-waste / resale) → entitlement removal → audit entry → asset status=retired | Closes the back half of the lifecycle loop. Required for IT/security/POS verticals where end-of-life is a regulated event. |
| **M6** | Property Management primitives — `tenants`, `units`, `tenant_leases`, `unit_id` on WO; tenant-billable vs landlord-billable distinction; common-area vs unit work | Property-mgmt customers cannot be served without these; without them they look just like generic site customers. |
| **M7** | Recurring service routes — `service_routes`, `route_stops`, `route_visits`; auto-generated lightweight WO per scheduled visit; mobile QR scan-on-arrival; required photo verification | Cleaning is unviable without this. Same primitives also serve recurring security audits and PM rounds. |
| **M8** | IT helpdesk overlay on tickets — severity P1-P4, `affected_users`, IT-specific portal request wizard | Without IT semantics, tickets feel like a repair-shop tool to IT customers. |
| **M9** | Software / license inventory — `software_assets`, `license_seats`, `license_assignments`; `installed_software` join to assets | Required for credible IT vertical; license compliance is a top-three IT customer concern. |
| **M10** | Multi-trade dispatch board — trade filter, skill matching against technician profiles, trade-tailored card layout | Single dispatcher cannot context-switch across six trades on a generic board. |
| **M11** | Consolidated customer invoicing — monthly bill across all WOs/contracts/recurring services for a customer, grouped by service line; co-exists with per-WO invoicing for one-off jobs | Single point of contact promise breaks if customer gets six bills a month. |
| **M12** | Trade taxonomies & catalog — labor-rate codes per trade, trade-specific labor task libraries (extend existing `labor_tasks`), trade-specific parts catalogs | Without it, every estimator builds quotes from scratch. |

### Should Have

Deliver within 6 months after the Must set lands. These differentiate the platform from generic CMMS competitors.

| ID | Capability | Rationale |
|---|---|---|
| **S1** | Security credential register + programming logs + scheduled access policies | Audit trail satisfies many security customers' compliance requirements; competitor parity. |
| **S2** | POS heartbeat ingestion — webhook receiving device heartbeats; auto-creates ticket on stale heartbeat; integrates with existing third-party integrations layer | Differentiates from passive ticket-only POS support. |
| **S3** | IT change management — RFC → CAB approval → scheduled change window → post-implementation review | Higher-end IT customers require ITIL-shaped workflows. |
| **S4** | Multi-site chain customer dashboard — operations rollup with site comparison, SLA-by-site, spend-by-site | Sales differentiator for retail-chain and commercial-real-estate prospects. |
| **S5** | Procurement on customer's behalf — full PO lifecycle including markup, customer-billable vs internal POs, vendor consignment | Required to monetize the "we handle everything" promise. |
| **S6** | Trade-specific install checklists — extend inspection templates with `service_line` scope and `install_completion` type | Forces per-trade quality control on install jobs. |
| **S7** | End-of-lease decision workflow — at lease expiry: renew / buy out / return / replace; UI prompts customer; system generates corresponding workflow | Closes the loop on M3. |
| **S8** | Recurring service photo verification (optional ML assist) — basic checks on cleaning verification photos | Workflow works without it; ML adds polish. Defer if vendor cost is high. |
| **S9** | Customer self-service contract portal — view entitlements, consumption-to-date, renewal terms; request renewal/changes | Reduces support load; customer expectation. |
| **S10** | Trade-specific KPI dashboards — MTBF/MTTR (equipment), first-call-resolution % (IT), route-completion-rate (cleaning), install-on-time % (security/POS) | Operational visibility per trade; required to manage at scale. |
| **S11** | Skill matrix per technician — skills tagged with proficiency, used by M10 dispatch matcher | Without it, M10 falls back to manual dispatch. |
| **S12** | Bulk asset import (CSV / vendor catalog feed) — onboarding hundreds of customer assets | Onboarding velocity; without it, every new IT/security customer takes weeks to load. |

### Could Have

Nice to have once the platform is established and bandwidth permits.

| ID | Capability | Rationale |
|---|---|---|
| **C1** | Vendor portal — vendors view POs, mark fulfillment, upload tracking | Reduces phone calls. |
| **C2** | Subcontractor self-service portal — accept/reject WOs, upload PODs | Extends existing subcontractor management. |
| **C3** | IoT sensor ingestion for predictive maintenance — temperature, vibration, runtime sensors feed PM engine condition rules | Already partially possible via integrations layer. |
| **C4** | AR-assisted remote support — video + annotation for L1 IT/security troubleshooting before dispatch | Cost-saver per truck-roll. |
| **C5** | Customer satisfaction surveys — post-WO NPS/CSAT, results aggregate per technician + service line | Soft KPI driver. |
| **C6** | Cross-customer parts marketplace — shared parts pool, surplus equipment resale | Ambitious; requires network effects. |
| **C7** | Time-on-site geo-validation — verify technician was actually at the site for billable hours | Trust signal for skeptical customers. |

### Won't Have (this expansion)

Out of scope. Each represents a different software category.

| ID | Capability | Why not |
|---|---|---|
| **W1** | ERP / general ledger | Integrate with QuickBooks / NetSuite / Sage. |
| **W2** | HR / time-and-attendance system | Keep existing time tracking; don't expand into HR. |
| **W3** | Full project management (Gantt, critical path) | Link to MS Project; we own the WO layer, not construction PM. |
| **W4** | CRM sales pipeline (opportunity management) | HubSpot / Salesforce territory. We own delivery, not sales. |
| **W5** | Building information modeling (BIM) | Specialized vendors own this. |
| **W6** | Full IT remote-monitoring & management (RMM) — agent-based endpoint mgmt | Integrate with existing RMMs (Datto, NinjaOne, ConnectWise Automate); don't compete. |

---

## Implementation roadmap

Each phase below is sequenced for dependencies and for customer-impact ordering. Estimates assume 2 backend engineers + 1 frontend + part-time ops/PM. **Critical path: Phases 11 → 12 → 13.** Phases 14-16 can run in parallel pairs once the foundation lands.

### Phase 11 — Service Line Configurability (Must M1, M2, M12)

**Status:** ✅ Implemented 2026-04-25 — see [`woms/phase-11-service-lines.md`](./woms/phase-11-service-lines.md)

**Effort:** 4-6 weeks. **Risk:** Low. **Beta target:** internal only.

Foundational. Ships `service_lines` lookup table, `service_line_id` FK on workorders/tickets/contracts/site_assets, WO type enum extension, trade-aware labor task libraries. Touches schema lightly (mostly columns + lookup tables). Sidebar gains "service line" filter; existing auto-repair UI is tagged `service_line='auto_repair'` and behaves identically.

**Exit criteria:** Every existing entity carries a service-line tag; WO type dropdown shows the new types; admin UI lets ops add labor tasks scoped to a trade.

### Phase 12 — Property Management vertical (Must M6)
**Effort:** 6-8 weeks. **Risk:** Medium (new domain). **Beta target:** 1-2 property-mgmt customers.

Adds `tenants`, `units`, `tenant_leases` tables. Adds `unit_id` to workorders (nullable, only used by property-mgmt customers). New customer-portal flow for tenants distinct from generic site contacts. WO routing logic distinguishes tenant-billable vs landlord-billable.

**Exit criteria:** A property-mgmt customer can submit a unit-specific request, the system routes it correctly, and the bill goes to the right party (tenant or landlord) per the lease terms.

### Phase 13 — Lease & Lifecycle (Must M3, M4, M5)
**Effort:** 8-10 weeks. **Risk:** High (largest single phase, spans procurement + inventory + contracts + audit + portal). **Beta target:** 1 IT customer + 1 fleet customer.

The big one. Adds asset-level lease records, end-to-end acquisition workflow (quote → approval → PO → receipt → install → activate), and decommissioning workflow (retire → wipe → recover → entitlement update → audit). Each workflow is a state-machine entity with its own table, audit trail, and customer-portal surface.

**Exit criteria:** A customer can request a new asset, the system carries it from quote to active managed asset without leaving the platform; an asset can be retired with full audit trail; lease alerts fire 90/60/30/0 days from expiry.

### Phase 14 — IT Support vertical (Must M8, M9, Should S3)
**Effort:** 8-10 weeks. **Risk:** Medium. **Beta target:** 1-2 IT-managed-services customers.

Helpdesk overlay on tickets (severity, affected users, IT-specific portal wizard), software/license inventory, change management (RFC + CAB + scheduled windows). Most data primitives are extensions of existing tables; the work is mostly UX + workflow.

**Exit criteria:** An IT customer can submit a P1 ticket via the IT-specific portal flow, escalation hits the right pager, and a planned change goes through CAB approval before execution.

### Phase 15 — Recurring Service Routes (Must M7, Should S8)
**Effort:** 4-6 weeks. **Risk:** Medium (mobile UX heavy). **Beta target:** 1 cleaning customer for 3 months.

Cleaning is the driver but routes apply to recurring security audits and PM rounds too. Mobile-first; leans heavily on the existing PWA. Requires polished mobile flow — a cleaner won't use it if it's slower than scribbling on paper.

**Exit criteria:** A recurring cleaning route runs for 3 months in production with >95% on-time visit completion logged via QR scan and photo upload.

### Phase 16 — Security / POS verticals (Should S1, S2)
**Effort:** 6-8 weeks. **Risk:** Low-medium. **Beta target:** 1 security customer + 1 POS customer.

Credential registers, programming logs, POS heartbeat ingestion. Fairly self-contained; slots into existing third-party integrations layer.

**Exit criteria:** Security customer's access-control programming history is queryable per credential per door; POS customer's terminal heartbeat creates a ticket within 5 minutes of going stale.

### Phase 17 — Multi-Trade Operations & Polish (Must M10, M11; Should S4, S10, S11)
**Effort:** 6-8 weeks. **Risk:** Low. **Beta target:** GA prep.

Dispatch board enhancements with skill-matching, consolidated monthly invoicing, multi-site chain rollup dashboards, trade-specific KPI dashboards, technician skill matrix. The phase that makes the platform feel coherent rather than six trade-specific apps stitched together.

**Exit criteria:** A dispatcher can route a multi-trade ticket in under 60 seconds; a multi-site customer sees one rollup dashboard; monthly invoicing produces one bill per customer per month.

### Phase 18 — Vendor & Subcontractor Self-Service (Could C1, C2; Should S5, S12)
**Effort:** 4-6 weeks. **Risk:** Low. **Beta target:** post-GA.

Lower priority; deliver after core value prop is in market and proven.

**Exit criteria:** Vendors can self-service POs from a portal; subcontractors can accept/reject WOs without phone calls.

### Total runway

**Critical path (11 → 13):** ~18-24 weeks (~5-6 months).
**Full backlog (11 → 18):** ~46-58 weeks (~11-14 months) at the staffing assumption above.
**Parallelization opportunity:** Phases 14 and 15 can start in parallel after 13 lands; 16 can start in parallel with 17.

---

## Architecture changes

### Data model additions

| Table | Purpose |
|---|---|
| `service_lines` | Lookup of supported service lines (slug, name, icon, default_role) |
| `tenants` | Tenant entity for property mgmt |
| `units` | Unit/space within a site (nested under sites; FK site_id) |
| `tenant_leases` | Tenant rental lease (start/end, rent, deposit, terms) |
| `asset_leases` | Asset-level lease (start/end, payments, mileage cap, residual, lessor) |
| `acquisition_workflows` | State-machine row for an in-flight acquisition |
| `decommission_workflows` | State-machine row for an in-flight decommissioning |
| `software_assets` | Software titles + versions (CMDB software side) |
| `license_seats` | License pool per software_asset (qty owned, qty assigned) |
| `license_assignments` | Seat → user/asset assignment |
| `installed_software` | Join: site_asset ↔ software_asset (what's running where) |
| `change_requests` (RFC) | IT change request entity |
| `cab_approvals` | Change advisory board approvals |
| `service_routes` | Cleaning/recurring route definition |
| `route_stops` | Stops within a route (FK site_id) |
| `route_visits` | Scheduled instance of a route stop |
| `credential_registers` | Security: who has access to what |
| `programming_logs` | Security/POS: configuration changes log |
| `pos_heartbeats` | Time-series heartbeat data (consider partitioning) |
| `tech_skills` | Technician skill matrix |
| `consolidated_invoices` | Monthly rollup invoice header (line items reference per-WO invoices) |

### Column additions on existing tables

- `workorders.service_line_id`, `workorders.unit_id` (nullable), `workorders.acquisition_workflow_id` (nullable), `workorders.decommission_workflow_id` (nullable)
- `tickets.service_line_id`, `tickets.severity` (P1-P4), `tickets.change_request_id` (nullable)
- `contracts.service_line_id`, `contracts.billing_model` enum (per_incident / monthly_retainer / consumption / route_based)
- `site_assets.service_line_id`, `site_assets.lease_id` (nullable), `site_assets.acquisition_workflow_id` (nullable)

All additive; no destructive migrations against existing entities.

### API

- New endpoints under `/api/v2/<service-line>/...` to keep namespacing clear and let v1 stay stable for existing integrations.
- WebSocket layer for POS heartbeat / IoT ingestion (extends existing third-party integrations infrastructure).
- All new endpoints follow the existing `{success, data, message}` envelope and the `Middleware::auth()` + policy-object permission pattern.

### Information architecture

- Sidebar gains a top-level **Service Line** switcher when the user has access to multiple lines. Each line surfaces its own WO list, dispatch board, KPI dashboard, and trade-specific tools (routes for cleaning, CMDB for IT, etc.).
- Existing auto-repair UI stays under the "Repair" service line — no breaking changes for current users.
- Customer portal gains trade-specific request wizards; selection driven by the contracts the customer holds.

### RBAC

- Add per-trade roles: `it_tech`, `cleaning_lead`, `property_manager`, `security_installer`, `pos_tech`. Each is a specialization of the existing role pattern.
- Permissions matrix grows by ~30 entries; the policy-object refactor from Phase 0 handles this without code rewrites.
- New permissions namespace: `service_line.<slug>.{view,manage,dispatch}`.

---

## Migration & rollout strategy

1. **Deploy schema additively.** Every new table/column is additive; no destructive migrations to existing entities. Default `service_line='auto_repair'` for all existing data.
2. **Feature-flag each phase per customer.** Use the existing module-access mechanism to gate visibility. Customers see only the service lines they've contracted for.
3. **Beta with one customer per service line** before opening generally. Each beta customer drives the spec for the workflows in their vertical.
4. **Stage the navigation change** behind a "WOMS preview" flag until 3+ service lines have shipped. Existing repair customers see no UI change.
5. **Documentation rolls with code.** Each phase ships with `docs/woms/<phase-slug>.md` capturing the data model, API surface, and operator runbook. Pattern matches the existing per-task documentation in `expansion-plan.md`.
6. **Beta exit criteria are gating.** Each phase has explicit exit criteria above. A phase is not "done" until a real customer uses it in production for the criterion period (typically 30-90 days depending on workflow cadence).
7. **Existing repair / roadside / towing flows stay frozen.** No refactor of those code paths during this expansion. Service-line tagging is the only addition that touches them, and it defaults transparently.

---

## Risks & mitigations

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| **Vertical-specific scope ambiguity** — IT helpdesk, security, cleaning each have decades of vendor-specific norms; risk of building the wrong abstraction | High | High | Ship Phase 11 first with one beta customer per service line driving the spec for their vertical. Don't build trade-specific UX without a paying beta customer to validate it. |
| **Permissions explosion** — service-line dimension multiplies the existing per-division permissions matrix | Medium | Medium | Enforce policy objects strictly; lint check against new code referencing string-based permission names; cap at ~30 new permissions across all phases. |
| **UI cognitive overload** — single dispatcher seeing six service lines on one screen | Medium | High | Trade filter defaults to user's primary line; multi-trade view is opt-in; per-trade dashboards are the default landing experience. |
| **Acquisition + decommissioning workflows are procurement-heavy** — building a full PO lifecycle is a quarter of an ERP | High | High | Scope to what's needed to close the asset lifecycle loop only; integrate with QuickBooks / NetSuite for the GL side; defer customer-cost markup logic until S5. |
| **Cleaning-route mobile UX must beat paper** — high-frequency low-value visits won't get logged if the mobile flow has friction | Medium | High | Dedicate frontend resourcing in Phase 15; pilot one cleaning customer for 3 months; instrument tap-count and time-to-complete per visit. |
| **Multi-tenant chain customer data isolation** — national chains expect their data segregated per region/division within their company | Medium | Medium | Reuse existing `division_id` plumbing; add per-customer data partitioning policy that's audited at API layer. |
| **Lease end-of-life decisions are time-critical** — missing an end-of-lease can cost the customer real money | Low | High | 90/60/30/0-day alerts hard-wired into PM engine; surface in capital planner; require explicit human acknowledgement. |
| **Schema scale** — we'll add ~30-40 tables on top of the existing ~150+ | Medium | Medium | Index strategy reviewed per phase; partitioning planned for time-series tables (POS heartbeats, route_visits) from the start. |

---

## Success metrics

| Metric | Target | When |
|---|---|---|
| **Coverage** — service lines with at least one paying production customer | 6+ of 8 | 12 months from Phase 11 start |
| **Adoption** — % of pilot-trade work logged in system (vs paper / texts) | >80% | Per phase, by 90-day beta exit |
| **Stickiness** — renewal rate for customers using ≥2 service lines vs single-line | +50% | 18 months from Phase 11 start |
| **Operational** — multi-trade ticket route time | <60 seconds | Phase 17 GA |
| **Lifecycle** — avg time from acquisition kickoff → asset-active | <14 days | Phase 13 GA |
| **Lifecycle** — avg time from decommission kickoff → asset retired (incl. recovery) | <30 days | Phase 13 GA |
| **Quality** — install-job rework rate | <5% | Phase 16 GA |
| **Customer satisfaction** — post-WO CSAT | >4.5 / 5 | Phase 18 GA |

---

## Open questions to resolve before Phase 11 starts

1. **Service-line taxonomy** — exact slugs and names for the eight lines. Affects every URL, permission, and report.
2. **Existing customers' service-line backfill** — do all current customers default to `auto_repair`, or do we survey them to tag actual scope?
3. **Pricing model per vertical** — flat rate, T&M, retainer, route-based — drives contract billing-model enum values.
4. **Branding** — does the consumer-facing repair business and the WOMS business share branding, or are they distinct customer experiences? Affects portal design and email templates.
5. **Mobile app vs PWA** — current mobile is React Native (in `apps/mobile/`); is the WOMS field experience also React Native or PWA-only? Phase 15 (cleaning routes) is the forcing function.
6. **Integration priorities** — which accounting / ERP / RMM / telematics vendors get first-class integrations? Affects integrations backlog beyond what's already in place.
7. **Compliance scope** — does the security/IT vertical require SOC 2 Type II certification, HIPAA BAA, or any other compliance posture beyond what already exists?

These should be answered in a kickoff session before Phase 11 ticket-writing begins.
