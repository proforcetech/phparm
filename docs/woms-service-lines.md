# WOMS Service Lines — Per-Trade Detail

**Status:** Draft, 2026-04-25
**Companion to:** [`woms-expansion-plan.md`](./woms-expansion-plan.md)

---

## How to read this document

Each section below covers one of the nine service lines the platform must support. The shape is the same for every trade so they're easy to compare:

- **Positioning** — what we sell to whom and why a customer buys this from us
- **Key entities** — the data we store (extends the WOMS data model in the expansion plan)
- **Key workflows** — request → completion lifecycle
- **KPIs** — what success looks like operationally
- **Gotchas** — non-obvious requirements that will bite us if we forget them

Where a section says "uses existing X," it refers to capability already shipped per `expansion-plan.md` (Phases 0-10).

---

## 1. Auto Repair / Roadside / Towing  *(existing)*

### Positioning
The original product. Independent shops and mobile mechanics serving consumer and small-fleet vehicles. Sold per-shop, monthly subscription. **No changes planned in WOMS expansion** beyond service-line tagging.

### Key entities
- `customers`, `customer_vehicles`, `estimates`, `estimate_jobs`, `estimate_items`, `invoices`, `invoice_items`, `time_entries`, `dispatch_assignments`
- All existing.

### Key workflows
1. Customer requests work → estimate built → customer approves → workorder created → time logged → invoice → payment.
2. Roadside: incoming call → dispatch assignment → tech en-route → on-site service → close-out.

### KPIs
- Bay turnover rate, average ticket value, technician efficiency %, comeback rate, online review score. (All exist in current dashboards.)

### Gotchas
- This vertical is the only one with **walk-in consumers**; every other vertical is B2B. Customer-portal flows that assume a logged-in commercial contact will break here.
- Default service line for all pre-WOMS data must be `auto_repair`. Treat this as a hard backfill rule.

---

## 2. Building & Property Repair

### Positioning
General contractor / handyman service for commercial property. Mix of one-off WOs ("HVAC compressor failed") and PM contracts ("quarterly facility inspection"). Sold per-property or per-portfolio.

### Key entities
- Uses existing `companies`, `sites`, `site_assets`, `workorders`, `contracts`, PM engine.
- **New:** `service_routes` for recurring inspection rounds (M7).
- **New:** `acquisition_workflows` for purchase + install of fixtures/equipment (M4).
- Trade catalogs: HVAC, plumbing, electrical, general carpentry, roofing — all stored as `service_lines.parent_id` sub-trades or as labor-task taxonomies, depending on customer mix.

### Key workflows
1. **Reactive WO:** site contact submits request via portal → triaged by trade → dispatched to specialist → on-site → photos + sign-off → invoice (or contract entitlement consumed).
2. **PM round:** PM engine generates inspection WO → technician executes against template → findings either close clean or open child WOs.
3. **Capital project:** large repair → estimate → customer approval → multi-day WO with milestones → progress billing.

### KPIs
- First-time-fix rate, mean response time per severity, PM completion %, capital project on-budget %, callback rate per trade.

### Gotchas
- **Multi-trade single dispatch.** A single building event ("sprinkler leak ruined drywall in two units and shorted a panel") spawns plumbing + drywall + electrical sub-tickets. Need parent/child WO support that survives across service lines.
- **After-hours premium billing.** Building emergencies happen at 2 AM; contract entitlements need a "premium hours" multiplier built in.
- **Tenant coordination.** Even though property mgmt is its own service line, building repair frequently needs tenant access coordination — surface tenant contact on the WO when `unit_id` is set.

---

## 3. Property Management

### Positioning
Day-to-day operation of multi-tenant commercial or residential buildings on the owner's behalf. Sold per door / per unit / per square foot. **High frequency, low-value transactions.**

### Key entities  *(net-new)*
- **`tenants`** — tenant business or person occupying a unit.
- **`units`** — leasable space within a `site`. Has FK to `site_id`. Carries `unit_type` (apartment, suite, retail bay, etc.), square footage, beds/baths if residential.
- **`tenant_leases`** — start/end, monthly rent, deposit, escalator clauses, renewal terms, notice period.
- **`unit_id`** added to `workorders` (nullable; only set for property-mgmt customers).
- **`tenant_billable`** flag on workorder — drives invoicing routing (tenant vs landlord).
- Existing `contracts` carry the management-services agreement with the building owner.

### Key workflows
1. **Tenant request:** tenant submits maintenance request (no login wall — magic link from rent portal) → property manager triages → routed to in-house tech or third-party trade → completion + tenant notification.
2. **Lease lifecycle:** new tenant onboarding → lease execution (e-sign via existing contracts module) → recurring rent invoicing → renewal/non-renewal at term → move-out inspection → security deposit reconciliation.
3. **Turnover:** tenant moves out → unit-condition inspection → list of make-ready WOs auto-generated from inspection findings → all WOs complete → unit marked rentable → marketing handoff.
4. **Common-area maintenance (CAM):** PM rounds + reactive WOs against common-area assets, billed back to tenants per CAM allocation rules in the management contract.

### KPIs
- Average maintenance request close time, tenant satisfaction (CSAT post-request), occupancy %, turnover days (move-out → move-in ready), CAM cost per square foot, on-time rent collection %.

### Gotchas
- **Tenant identity is not customer identity.** Tenants are not customers in the billing sense — the building owner is. But tenants need portal access. Treat tenant as a third party with a token-based auth flow; do not pollute the `users` table with tenants.
- **Billing routing is per-WO, not per-customer.** Same tenant can have a tenant-billable WO (clogged sink they caused) and a landlord-billable WO (HVAC compressor) in the same week.
- **Lease abstraction overlap.** `asset_leases` (M3) and `tenant_leases` (M6) are deliberately separate concepts. Asset lease = customer leases physical equipment. Tenant lease = tenant rents space. Don't merge them.
- **Vacant unit work** still needs to be tracked even though there's no tenant to bill — landlord-billable, but `tenant_id` is null.
- **Privacy.** Tenants cannot see other tenants' WOs even within the same building. Enforce at the API layer in addition to UI.

---

## 4. Equipment Repair (general)

### Positioning
On-site or shop repair of any non-vehicle, non-IT equipment: industrial machinery, kitchen equipment, medical/dental equipment, laboratory instruments. Sold per-customer with mixed T&M + service-contract billing.

### Key entities
- Uses existing `site_assets` with `asset_type` taxonomy extended for equipment classes (motor, pump, compressor, mixer, autoclave, refrigeration unit, etc.).
- Existing `meter_readings`, PM engine, `inspection_templates`, `parts_catalog`.
- **New:** `software_assets` ↔ `installed_software` join used for embedded firmware tracking on smart equipment.
- **New (M4/M5):** acquisition + decommissioning workflows for capital equipment.

### Key workflows
1. **Reactive RO:** customer reports failure → diagnostic visit → quote → approval → repair (parts + labor) → invoice.
2. **PM:** runtime-meter or calendar-driven PM WO → predefined task list → readings logged → consumables decremented from contract entitlement.
3. **Warranty claim:** failed component under manufacturer warranty → claim WO with vendor-RMA tracking → cost recovered from vendor, not billed to customer.
4. **Installation / commissioning:** new equipment delivered → install WO → commissioning checklist (uses existing inspection templates) → asset activated → contract entitlements update.

### KPIs
- MTBF (mean time between failures) per asset, MTTR (mean time to repair), parts-on-truck first-fix rate, PM compliance %, warranty recovery $.

### Gotchas
- **Serial number is not optional.** Equipment without serials is unidentifiable for warranty/recall. Force serial collection on intake; warn loudly if missing.
- **Manufacturer-specific parts.** Cross-reference catalogs (OEM PN ↔ aftermarket PN ↔ supersession chain) is a real requirement. Phase 14+ work; flag it now.
- **Hazardous materials.** Refrigerants, compressed gases, biohazards — disposal requires audit trail. Decommissioning workflow (M5) must capture disposal evidence.
- **Specialized tools.** Track which tech has which calibrated tool; expired calibration on a torque wrench invalidates a repair.

---

## 5. Customer Fleet Management

### Positioning
End-to-end management of a customer's vehicle fleet: maintenance, leases, telematics, fuel, accident management, end-of-lease decisions. Sold per-vehicle/month + per-event.

### Key entities
- Uses existing `customer_vehicles` (extends across service lines as `site_assets` with `asset_type='vehicle'`).
- Uses existing telematics integration layer.
- **New (M3):** `asset_leases` — lease term, payment schedule, lessor (bank/finance company), residual value, mileage allowance, end-of-lease alert thresholds.
- **New:** `fuel_transactions` if we ship fuel-card integration (deferrable to Could-have).
- **New (S7):** end-of-lease decision workflow (renew / buy out / return / replace).

### Key workflows
1. **Acquisition (M4):** customer needs new vehicle → spec + approval → vendor PO → delivery → lease executed → vehicle active in fleet, PMs scheduled, telematics enrolled.
2. **PM:** existing fleet maintenance flow; nothing new.
3. **Lease milestone alerts:** at 90/60/30/0 days from lease end, customer gets dashboard alert + email. UI walks them through end-of-lease decision.
4. **End-of-lease (S7):**
   - **Return:** schedule excess-wear inspection → reconcile mileage → return WO → lease closed.
   - **Buy out:** purchase price calculation → owner change → asset reclassified from leased to owned.
   - **Replace:** triggers a new acquisition workflow (M4) for the replacement vehicle in parallel with the return workflow on the outgoing vehicle.
   - **Renew:** new lease record, same asset.
5. **Decommission (M5):** salvage / total loss / sold → asset retired with full audit.

### KPIs
- Cost per mile, PM compliance %, lease utilization (actual mileage vs allotted), excess-wear charges $, downtime days/year per vehicle, telematics safety score per driver.

### Gotchas
- **Mileage matters legally.** Lease overage charges are real money. Telematics-fed mileage must be reconcilable; manual override needs an audit trail.
- **Fuel card integration is a separate scope** — defer or partner. Don't promise it in Must Have.
- **Driver assignments** ≠ technician assignments. A customer driver is a third-party identity (similar to tenant) — do not put them in `users`.
- **Accident management** is workflow + insurance claim coordination + replacement vehicle logistics. It's a sub-feature of fleet that easily becomes its own product. Scope tightly: log incident, link to repair WO, store insurance claim # and contact. Do not build full claim-management UX.

---

## 6. IT Support

### Positioning
Managed IT services: helpdesk, endpoint management, software licensing, network/server upkeep, change management. Sold per-user/month or per-device/month with monthly retainer. **Highest volume, lowest unit cost** of any service line.

### Key entities
- Uses existing `tickets` extended with `severity` (P1-P4), `affected_users`, `change_request_id`.
- **New (M9):** `software_assets`, `license_seats`, `license_assignments`, `installed_software`.
- **New (S3):** `change_requests` (RFC), `cab_approvals`.
- Site assets extend to CIs (configuration items): workstations, servers, switches, firewalls, printers, mobile devices.
- `service_lines.it_support` carries trade-specific custom fields on assets (RAM, OS, CPU, IP, MAC, AD/Entra ID join status, agent installed?).

### Key workflows
1. **Helpdesk ticket (M8):** end-user submits via IT-specific portal wizard ("My computer / Email / Network / Software / Access / Other") → triaged by severity → assigned to L1 → escalated through L2/L3 as needed → resolved + KB article option.
2. **Onboard / offboard (huge volume):** new hire → equipment provision → AD/Entra account → license assignments → workstation imaged → desk delivery. Offboard is the reverse.
3. **Change management (S3):** RFC submitted → impact assessment → CAB approval (or auto-approved if standard change) → scheduled change window → execution WO → post-implementation review.
4. **License compliance:** quarterly audit run against `license_seats` vs `license_assignments`; surface over-allocations as tickets.
5. **Asset lifecycle:** acquisition (M4) for new equipment; decommissioning (M5) including data wipe step + certificate of destruction.

### KPIs
- First-call resolution %, mean time to resolve by severity, SLA compliance %, % of changes that succeed without rollback, license compliance %, ticket volume per user (lower = better — surfaces training opportunities), CSAT.

### Gotchas
- **Severity is not priority.** Severity = how broken (system down vs cosmetic). Priority = how urgent (CEO laptop vs intern laptop). Need both fields, both sortable.
- **Decommissioning has a data-wipe step that's regulatory.** Certificate of destruction must be storable as a workflow attachment with vendor sign-off. Do not skip this.
- **Don't compete with RMM tools.** Datto, NinjaOne, ConnectWise Automate already do agent-based endpoint management. Integrate (alerts → tickets), don't rebuild.
- **CMDB completeness is a customer's #1 complaint about every IT MSP tool.** Bulk import (S12) is critical; manual asset entry never keeps up with reality.
- **License keys are sensitive.** Encrypt at rest, mask in UI by default, audit access.
- **AD/Entra integration** for user lookup will be requested early. Plan for it in Phase 14, not later.

---

## 7. Security Systems / Surveillance Cameras / Access Controls

### Positioning
Install, monitor, and maintain physical security systems: IP cameras, NVRs, access-control panels, badge readers, intrusion alarms, intercoms. Sold install + monthly monitoring + per-incident.

### Key entities
- Uses existing `site_assets` (cameras, panels, readers, sensors).
- **New (S1):** `credential_registers` — every issued badge/PIN/biometric. Each row: holder identity, issued by, issued at, expires at, current access level, status (active/lost/revoked).
- **New (S1):** `programming_logs` — every config change to a panel or reader. Diff-style (before/after).
- **New:** access policies / schedules — who can enter where and when (foreign key to credential register).
- **New:** install checklists per device class (extends inspection templates with `service_line='security'`, `template_type='install_completion'`).

### Key workflows
1. **Install project:** quote → site survey → equipment list → acquisition workflow (M4) → install WO with multi-day milestones → commissioning checklist per device → activation → monitoring contract starts.
2. **Credential issuance:** request (HR or customer admin) → approval → physical badge encoded or PIN issued → credential register updated → handover signed.
3. **Credential revocation:** termination event → bulk revoke → credential register status updated → audit log entry.
4. **Programming change:** scheduled work order → tech makes change → before/after captured in programming log → customer notified.
5. **Audit cycle (annual or per contract):** PM-style WO that walks every device + every credential → compliance report generated → findings → remediation WOs.
6. **Incident response:** alarm fires → ticket auto-created via integration → tech dispatched → resolution + report.

### KPIs
- Install on-time %, install rework rate, audit pass rate, mean time to revoke (terminated employee → access revoked), false-alarm rate.

### Gotchas
- **Credentials are forever security-relevant.** Soft delete only; never hard-delete a credential register row. Holder identity may also need GDPR-compliant pseudonymization on request.
- **Camera footage retention policies** are configured per-site and per-jurisdiction. We don't store the footage itself; we store the *policy* and surface its expiration.
- **Programming logs are diffable like git.** Plan storage accordingly — JSON before/after, not free text.
- **Dual-trade jobs are common.** "Install camera + run network drop for it" needs the IT trade to do the cabling and the security trade to do the camera. M10 multi-trade dispatch is required.
- **Some customers (banks, government, regulated industries)** require background-checked technicians only. Tag techs in the skill matrix (S11) with security clearances; dispatcher must filter on it.

---

## 8. Point of Sale

### Positioning
Maintain customer POS systems (terminals, kitchen displays, kiosks, payment peripherals). Mostly retail and restaurants. Sold per-terminal/month + per-incident. **Heartbeat-monitoring driven** rather than ticket-driven.

### Key entities
- Uses existing `site_assets` for terminals.
- **New (S2):** `pos_heartbeats` — time-series, partitioned by month. Stores device id, timestamp, status, last-transaction time, software version, network status.
- **New:** stale-heartbeat rules — defined per customer or per terminal; trigger ticket auto-create when violated.
- **New (S1, shared):** `programming_logs` for menu/price/tax changes pushed to terminals.
- Existing `tickets` with `severity` + IT-style helpdesk overlay.

### Key workflows
1. **Heartbeat ingestion (S2):** webhook from POS vendor → `pos_heartbeats` insert → rules evaluated → ticket auto-created if stale → dispatch.
2. **Terminal failure:** ticket (manual or auto) → diagnose remote → if unrecoverable, swap-out WO → faulty terminal returns to depot → repair or RMA.
3. **Configuration push:** menu/price/tax change request → reviewed → pushed via vendor API or on-site WO → programming log entry → customer sign-off.
4. **New-store rollout:** acquisition workflow (M4) for terminal hardware → install WO with network + power + commissioning checklist → first-day-of-business support window.

### Gotchas
- **Heartbeats are noisy.** A bad WiFi access point can mark every terminal stale. Rule engine needs to distinguish single-terminal failure from site-wide network failure (correlate stale heartbeats across the same site).
- **Transaction integrity.** If a terminal fails mid-transaction, money is involved. Failure tickets that touch active transactions are P1 24/7 — explicit rule.
- **PCI compliance.** We're not in the cardholder data environment, but we are adjacent. SOC 2 Type II is likely a customer requirement (open question 7 in the expansion plan).
- **Heartbeat data volume** is the largest single source of row growth in the system. Partition `pos_heartbeats` by month from day one; archive after 90 days unless contract requires longer retention.
- **Vendor lock-in** is a real risk. Each POS vendor (Square, Toast, Clover, NCR, Aloha) has its own API. Build a heartbeat-ingestion adapter pattern, not a Square-specific endpoint.

### KPIs
- Terminal uptime %, mean time from stale-heartbeat to ticket, mean time to first response on a P1, transaction-impacting incident count, swap-out turnaround time.

---

## 9. Commercial Cleaning

### Positioning
Recurring cleaning services for commercial properties: offices, retail, medical facilities, light industrial. Sold as monthly contract with defined route + frequency. **High visit volume, very low ticket value, mobile-first execution.**

### Key entities  *(net-new — M7)*
- **`service_routes`** — named recurring route ("Tuesday/Thursday Office Loop"), assigned crew, service type.
- **`route_stops`** — ordered stops within a route, FK to `site_id` and optionally `unit_id` (M6 dependency for multi-tenant buildings).
- **`route_visits`** — scheduled instance of a stop (one row per stop per scheduled date). Carries arrival timestamp, completion timestamp, photos, exception notes.
- **`route_visit_tasks`** — checklist items per visit (vacuum, restrooms, trash, restock).
- Existing `contracts` carry the cleaning service agreement; consumption ledger tracks completed visits against contract entitlement.

### Key workflows
1. **Route execution:** PM-style scheduler generates `route_visits` for the upcoming day → cleaner pulls route on PWA → arrives, scans QR at site → completes checklist → uploads photos → marks visit complete.
2. **Quality control:** supervisor reviews uploaded photos (optionally with ML photo verification S8) → flags rework or approves.
3. **Special request:** customer requests one-off cleaning ("conference room post-event") → standalone WO outside the route → billed per-incident.
4. **Route replanning:** crew member out sick → dispatcher re-routes stops to other crews → affected stops auto-update.
5. **Monthly billing:** consolidated invoice (M11) summarizes visits completed + special requests + missed visits with credit.

### KPIs
- Visit completion %, on-time arrival %, missed-visit rate, photo verification pass rate, customer complaint rate, route efficiency (visits per crew-hour).

### Gotchas
- **The mobile UX must beat paper or it won't be used.** Cleaners are not tech-forward users. Single-tap-per-task, big buttons, offline-first (PWA already supports this), QR scan should auto-load the visit. If the workflow takes longer than the cleaning, it's a failure. Phase 15 needs dedicated UX iteration with real cleaners.
- **Photos eat storage.** Plan for image compression on upload, lifecycle policies on stored photos (downsample after 30 days, archive after 90).
- **Time-on-site geo-validation (C7)** is sensitive. Cleaners interpret it as surveillance. Make it a contract-level opt-in with explicit customer + crew transparency.
- **Route changes are frequent.** Building access changes, holidays close offices, contracts get added/removed. The route definition can't be hard-baked into route_visit rows weeks in advance — generate visits on a short horizon (e.g., 7 days out).
- **Per-visit value is so low** that admin overhead per visit must be near zero. Resist any feature that adds even 30 seconds of office processing per visit.

---

## Cross-cutting concerns

### Trade-aware custom fields
Already supported via existing custom-fields engine. Each service line registers a default set of field templates per entity type (asset, WO, ticket). Templates are seeded at install; customer can override.

### Trade-aware permissions
New permission namespace: `service_line.<slug>.{view,manage,dispatch,configure}`. Default roles seeded per trade (`it_tech`, `cleaning_lead`, etc.). Existing permissions (`workorders.view`, etc.) gate access to the entity itself; service-line permissions further filter which entities a user can see based on their assigned trades.

### Trade-aware reporting
Each KPI dashboard (S10) is scoped to a single service line by default. Multi-line view available to admins / cross-trade managers. The shared dashboard framework is reused; only the metric definitions differ per trade.

### Inventory & parts overlap
Several trades share parts categories (e.g., automotive and equipment repair both use bearings, fasteners, lubricants). The parts catalog is **single, with multi-trade tagging** rather than siloed per trade — avoids duplicate SKU records when the same part serves multiple verticals.

### Subcontractor pattern
Every trade except the existing auto-repair vertical will at some point dispatch to subcontractors (HVAC specialist, electrician, alarm-monitoring central station, software vendor support). Existing subcontractor management is reused; trade tagging on subcontractors is added in Phase 11 alongside the rest of the service-line tagging work.

---

## Mapping to the MoSCoW backlog

The capabilities below cite which MoSCoW items from `woms-expansion-plan.md` enable each service line.

| Service line | Required Must items | Enabling Should items |
|---|---|---|
| Auto Repair | M1 (tagging only) | — |
| Building & Property Repair | M1, M2, M4, M7, M10, M12 | S5, S6, S10, S11 |
| Property Management | M1, M2, M6, M11 | S4, S9 |
| Equipment Repair | M1, M2, M3, M4, M5, M12 | S5, S6, S7, S10, S12 |
| Customer Fleet Management | M1, M2, M3, M4, M5 | S7, S10, S12 |
| IT Support | M1, M2, M5, M8, M9 | S3, S10, S11, S12 |
| Security / Cameras / Access | M1, M2, M4, M5, M10, M12 | S1, S6, S10, S11 |
| Point of Sale | M1, M2, M4, M5, M8 | S2, S6, S10 |
| Commercial Cleaning | M1, M2, M7, M11 | S4, S8, S10, S11 |

If a Must item from a customer's contracted service line is not yet shipped, do not onboard that customer — the workflows will not work end-to-end.
