# Phase 17 — Multi-Trade Operations

> Plan reference: `docs/woms-expansion-plan.md` Phase 17, items **M10**
> (multi-trade dispatch board), **S10** (trade-specific KPI dashboards),
> **S11** (technician skill matrix + cert tracking).
>
> Phase status: **shipped**. Migrations 167–168 deployed; skill matrix,
> dispatch board, and trade KPI endpoints are live.

Phase 17 is the operations layer that ties Phases 11–16 together. By the
end of Phase 16 PHPArm could run nine different verticals (auto, building
repair, property, equipment, fleet, IT, security, POS, cleaning) — but
the dispatcher running all nine had no way to answer "who on shift today
can take a P1 IT outage AND has the cert to handle the bloodborne
cleanup that just came in?"

Three deliverables, deliberately bundled because they share the same
data spine:

1. **Skill matrix (S11)** — a catalog of competencies tied to service
   lines plus a per-tech proficiency + cert-expiry ledger.
2. **Multi-trade dispatch board (M10)** — a single board that can
   filter unassigned WOs by service line and surface qualified
   candidates from the skill matrix.
3. **Trade KPI dashboards (S10)** — per-service-line MTTR / MTBF /
   completion / route metrics so a manager running multiple verticals
   can compare apples to apples without hand-rolling SQL.

The skill matrix is the load-bearing primitive: dispatch board reads
it for assignment validation, KPI dashboards group by the same
service_lines that scope it, and the schema (`skills.service_line_id`)
explicitly mirrors the Phase 12+ service-line model.

---

## 1. What shipped

### S11 — Skill matrix (migration 167)

| Table          | Role                                                              |
|----------------|-------------------------------------------------------------------|
| `skills`       | Catalog of competencies (slug + name + service_line + category).  |
| `user_skills`  | M:N grant: per-user proficiency + cert dates + expiration.        |

Plus:

- **Seed catalog** — ~30 starter skills covering all nine service
  lines, plus four cross-trade skills (`xt_first_aid`, `xt_osha_10`,
  `xt_customer_service`, `xt_drivers_license`) with `service_line_id =
  NULL` so they apply everywhere.
- **Three-tier proficiency** — `learner` → `competent` → `expert`,
  enforced in PHP (`UserSkill::ALLOWED_PROFICIENCY_LEVELS`).
- **Cert expiry tracking** — `certified_at` + `expires_at` per grant;
  `idx_user_skills_expires` makes "expiring in next 30 days" cheap.

### M10 — Multi-trade dispatch board (migration 168 + DispatchBoardService)

| Component                           | Role                                                                        |
|-------------------------------------|-----------------------------------------------------------------------------|
| `workorders.required_skill_id`      | Optional FK to `skills`; NULL = no specific skill needed.                   |
| `workorders.min_proficiency_level`  | Optional minimum tier; NULL = any holder qualifies.                         |
| `DispatchBoardService::board()`     | Returns `{workorders, candidates, technicians, skills}` — the full payload. |
| `DispatchBoardService::assignWorkorder()` | Atomic assign with eligibility validation + audit emit.               |

The board is the **only** place that crosses all the data: WOs from
every service line, all eligible techs (filtered by service-line
membership AND skill matrix), and the open-workload counts so the
dispatcher can break ties on capacity.

### S10 — Trade KPI dashboards (TradeKpiService)

A read-only analytics surface that returns a four-section bundle per
service line per period:

| Section       | Metrics                                                                          |
|---------------|----------------------------------------------------------------------------------|
| `reliability` | MTTR (hours), MTBF (days), sample size — site-asset-scoped.                      |
| `tickets`     | Total / open / closed / SLA-met counts.                                          |
| `workorders`  | Total / completed / completed-on-time / install-on-time % / revenue.             |
| `routes`      | Planned / completed / missed / skipped / completion %.                           |

All metrics are computed live from existing tables (workorders, tickets,
route_visits) — no new aggregation tables, no scheduled rollup. The
period is bounded so the queries stay cheap.

---

## 2. Data model

### 2.1 skills catalog

```sql
CREATE TABLE skills (
    id BIGINT UNSIGNED PK,
    slug VARCHAR(60) NOT NULL UNIQUE,           -- 'auto_brakes', 'it_networking', 'xt_first_aid'
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    service_line_id BIGINT UNSIGNED NULL,        -- → service_lines (SET NULL); NULL = cross-trade
    category VARCHAR(60) NULL,                   -- 'mechanical', 'compliance', 'soft', etc.
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
);
```

The `slug` is the API-facing stable identifier — code keys off slug, not
id. `service_line_id` is the scope: a skill belongs to one service line
(IT skills aren't interchangeable with HVAC skills), or NULL for
cross-trade competencies (First Aid, OSHA-10, customer service,
driver's license — things every field tech eventually needs).

`is_active = 0` archives a skill without breaking historical
`user_skills` rows that point to it; the dispatch board hides inactive
skills from the picker.

### 2.2 user_skills (the m:n join)

```sql
CREATE TABLE user_skills (
    id BIGINT UNSIGNED PK,
    user_id INT UNSIGNED NOT NULL,                  -- → users (CASCADE)
    skill_id BIGINT UNSIGNED NOT NULL,              -- → skills (CASCADE)
    proficiency_level VARCHAR(20) DEFAULT 'competent',  -- learner|competent|expert
    certified_at DATE NULL,
    expires_at DATE NULL,
    notes TEXT NULL,
    UNIQUE (user_id, skill_id)
);
```

Three rules:

1. **One row per (user, skill).** UNIQUE prevents accidental dup
   grants. Re-granting via `PUT /api/users/{id}/skills/{skillId}` is
   an upsert (returns the existing id with the new fields applied).
2. **Proficiency is a VARCHAR, not an ENUM.** Lets us extend the
   vocabulary without a migration; the in-PHP enforcement is the gate.
3. **`expires_at` is the cert deadline.** A NULL expires_at means "no
   expiration" (the holder keeps the skill until manually revoked).
   The expiring-soon query indexes `expires_at`.

### 2.3 workorders.required_skill_id + min_proficiency_level (migration 168)

```sql
ALTER TABLE workorders
    ADD COLUMN required_skill_id BIGINT UNSIGNED NULL AFTER service_line_id,
    ADD COLUMN min_proficiency_level VARCHAR(20) NULL AFTER required_skill_id,
    ADD INDEX idx_workorders_required_skill (required_skill_id),
    ADD CONSTRAINT fk_workorders_required_skill
        FOREIGN KEY (required_skill_id) REFERENCES skills(id)
        ON DELETE SET NULL ON UPDATE CASCADE;
```

Both columns are **optional**. Three modes a WO can be in:

| `required_skill_id` | `min_proficiency_level` | Meaning                                          |
|---------------------|-------------------------|--------------------------------------------------|
| NULL                | NULL                    | Anyone in the WO's service line is a candidate.  |
| 4321                | NULL                    | Must hold skill #4321 at any proficiency.        |
| 4321                | `'expert'`              | Must hold skill #4321 at expert level.           |
| NULL                | (anything)              | Validation no-op — proficiency is meaningless without a skill. |

Skill is SET NULL on delete: archiving a skill leaves WOs that
referenced it free for any tech in the service line, rather than
blocking the WO.

---

## 3. The two load-bearing decisions

### 3.1 Service line is the spine

Every Phase 17 entity scopes through `service_lines.id`:

```
service_lines (existing, from Phase 12+)
       ▲
       │
       ├─── skills.service_line_id              (S11: catalog scope)
       ├─── workorders.service_line_id          (existing routing dimension)
       ├─── users → user_service_lines (m:n)    (existing membership)
       └─── trade_kpis groups everything by it  (S10)
```

This is the load-bearing decision because it lets all three
deliverables compose cleanly. The dispatch board's "candidates"
query is:

```
candidates(wo) =
  techs in wo.service_line
    AND (wo.required_skill_id IS NULL OR techs hold the skill at >= min_prof)
    AND (cert hasn't expired)
```

The KPI dashboard's per-vertical view is:

```
SELECT ... FROM workorders WHERE service_line_id = ? AND completed_at BETWEEN ?
SELECT ... FROM tickets    WHERE service_line_id = ? ...
SELECT ... FROM route_visits JOIN service_routes ON ... WHERE service_line_id = ? ...
```

Service line is the natural grouping axis — and the existing one. The
alternative was inventing a "trade" concept; that would have meant
maintaining two parallel taxonomies (trade vs service line) and
deciding which won when they disagreed.

### 3.2 Eligibility is computed live, not precomputed

We considered a `workorder_candidate_techs` materialized table, refreshed
by a cron, so the dispatch board could just SELECT. Rejected:

- **Skill grants change daily.** A tech earns a cert; HR onboards a
  new hire; a license expires overnight. A precomputed table would
  be either stale or refreshed so often it has no benefit.
- **The query is cheap.** Service-line membership filters the tech
  roster down to dozens; the skill check is one indexed lookup
  (`user_skills` UNIQUE on `(user_id, skill_id)`).
- **Live compute is auditable.** When the assignment endpoint says
  "tech 47 can't take this WO," it can explain why (wrong service
  line, missing skill, expired cert) using the live data the
  operator just changed. A staleness window in a cached table would
  produce confusing rejections.

The operative pattern: `DispatchBoardService` computes candidates per
WO at board-render time AND re-runs `assertTechnicianEligible()` at
assign time. The render is advisory; the assign is authoritative.

---

## 4. Dispatch board payload

`GET /api/dispatch-board?service_line_id=…&date_from=…&date_to=…`
returns:

```jsonc
{
  "workorders": [
    {
      "id": 9876,
      "title": "Replace fuser unit",
      "service_line_id": 6,
      "required_skill_id": 24,
      "min_proficiency_level": "competent",
      "status": "scheduled",
      "priority": "p2_high",
      "site": { "id": 12, "name": "..." },
      "assigned_technician_id": null,
      "scheduled_for": "2026-05-04T13:00:00",
      ...
    },
    ...
  ],
  "candidates": {
    "9876": [
      { "id": 17, "name": "...", "open_workload": 3, "proficiency": "expert" },
      { "id": 22, "name": "...", "open_workload": 1, "proficiency": "competent" }
    ],
    ...
  },
  "technicians": [
    { "id": 17, "name": "...", "primary_service_line_id": 6, "service_line_ids": [6,9], "open_workload": 3 },
    ...
  ],
  "skills": [
    { "id": 24, "slug": "it_endpoints", "name": "Endpoint Management", "service_line_id": 6 },
    ...
  ]
}
```

The render is one round-trip — three top-level lookups plus the per-WO
candidate set. The frontend keeps everything in memory; drag-to-assign
is `POST /api/dispatch-board/{wo}/assign` with `{technician_id: 17}`.

Filters supported: `service_line_id`, `status` (scalar or comma list),
`priority`, `unassigned_only` (boolean), `date_from`/`date_to`.

The `open_workload` denorm is the count of in-flight WOs assigned to
that tech (status NOT IN ('completed','closed','cancelled')). The
dispatcher uses it as a tiebreaker — multiple eligible candidates,
pick the one with capacity.

---

## 5. Eligibility validation

`DispatchBoardService::assertTechnicianEligible($workorder, $technicianId)`
is the gate. It runs four checks in order:

```
1. user exists and is active
2. user.role IN ('admin', 'manager', 'technician')
3. if workorder has a service_line_id:
       user must be in that line via primary_service_line_id
       OR via user_service_lines (the m:n)
4. if workorder has a required_skill_id:
       user must hold the skill at >= min_proficiency_level
       (validated via SkillMatrixService::userIdsForSkill,
        which filters out expired certs)
```

A failure throws `InvalidArgumentException` → 422 with a message that
identifies the failing rule:

- `"technician 47 not found"` / `"is inactive"` / `"cannot be assigned"`
- `"technician 47 is not a member of service line 6"`
- `"technician 47 does not hold required skill 24 at >= expert"`

This is the only enforcement point. The board's `candidates` list is a
pre-filter for UX (so the dispatcher sees green checkmarks); the
`assertTechnicianEligible` call at assign time is what stops a
malformed POST from putting a HVAC tech on a Cisco config.

### 5.1 What about an admin override?

There isn't one. If you need to assign a tech who doesn't meet the
skill criteria, edit the WO to clear `required_skill_id` first, then
assign. Forcing the explicit edit makes the override visible in the
audit log (`workorder.updated` showing skill cleared, then
`dispatch_board.assigned`). A silent "force assign" flag would have
hidden this from compliance.

---

## 6. Trade KPI bundle

`GET /api/trade-kpis/{service_line_id}?from=YYYY-MM-DD&to=YYYY-MM-DD`
returns:

```jsonc
{
  "service_line": { "id": 6, "slug": "it_support", "name": "IT Support", ... },
  "period": { "from": "2026-03-01", "to": "2026-05-04" },

  "reliability": {
    "mttr_hours": 4.25,        // mean time to repair, completed WOs in window
    "mtbf_days": 18.7,         // mean time between failures, per-asset gap
    "sample_size": 142
  },

  "tickets": {
    "total": 320, "open": 24, "closed": 296,
    "sla_met": 281, "sla_pct": 94.9
  },

  "workorders": {
    "total": 428, "completed": 401, "completed_on_time": 372,
    "install_on_time_pct": 92.7,
    "revenue": 184560.50
  },

  "routes": {
    "planned": 168, "completed": 154, "missed": 8, "skipped": 6,
    "completion_pct": 91.7
  }
}
```

### 6.1 The MTTR / MTBF formulas

Both are scoped to **WOs that touch a `site_asset_id`** — only those
represent asset repair (vs. ad-hoc service work).

```
MTTR  = AVG( completed_at - started_at )
        across completed/closed WOs in the period
        with site_asset_id NOT NULL

MTBF  = AVG( gap_days between consecutive completed WOs per site_asset )
        using LAG() OVER (PARTITION BY site_asset_id ORDER BY completed_at)
        gaps with NULL or zero days excluded
```

MTBF inherently needs ≥ 2 completed WOs per asset to produce a gap; the
`sample_size` field is the count of contributing gaps so a viewer can
gauge confidence ("MTBF over 3 samples" is noise; "over 300" is signal).

### 6.2 Why no aggregation tables

Every metric is a SELECT against existing tables, period-bounded by the
operator's `from` / `to`. We considered a nightly rollup into a
`service_line_kpi_daily` table. Rejected:

- The four queries combined run in milliseconds for any sane period
  (≤ 1 year). The bottleneck would be the network round-trip, not
  the database.
- A rollup table introduces staleness ("why doesn't today's WO show
  up in the KPIs?") and a refresh cron that has its own failure
  modes.
- Operators want ad-hoc periods — "compare Q1 vs Q2", "the week of
  the deployment" — and a daily rollup grain doesn't grant that
  cheaply.

If a customer pushes data volume past the live-query budget, the right
move is to add a date-pruned index hint or a periodic OPTIMIZE TABLE,
not a rollup tier.

---

## 7. API surface

All endpoints under `Middleware::auth()`.

### 7.1 Skill matrix (`routes/modules/skills.php`)

Read perm: `skills.view`. Write perm: `skills.manage`.

| Method | Path                                               | Purpose                                       |
|--------|----------------------------------------------------|-----------------------------------------------|
| GET    | `/api/skills`                                      | List catalog (filters: service_line_id, category, is_active, search) |
| GET    | `/api/skills/{id}`                                 | Show                                          |
| POST   | `/api/skills`                                      | Create catalog entry                          |
| PUT    | `/api/skills/{id}`                                 | Update                                        |
| DELETE | `/api/skills/{id}`                                 | Delete (CASCADEs `user_skills`)               |
| GET    | `/api/skills/matrix`                               | Combined catalog + roster + grid (one round-trip for the matrix UI) |
| GET    | `/api/users/{id}/skills`                           | Per-tech skill list                           |
| PUT    | `/api/users/{userId}/skills/{skillId}`             | Grant or update grant (proficiency + dates)   |
| DELETE | `/api/users/{userId}/skills/{skillId}`             | Revoke single skill from a tech               |

### 7.2 Dispatch board (`routes/modules/dispatch_board.php`)

Read perm: `dispatch_board.view`. Assign perm: `dispatch_board.assign`.

| Method | Path                                | Purpose                                     |
|--------|-------------------------------------|---------------------------------------------|
| GET    | `/api/dispatch-board`               | Full board payload (filters: service_line_id, status, priority, unassigned_only, date_from/to) |
| POST   | `/api/dispatch-board/{wo}/assign`   | Drop a tech onto a WO (or unassign with NULL) |

### 7.3 Trade KPIs (`routes/modules/trade_kpis.php`)

Read perm: `trade_kpis.view`.

| Method | Path                                    | Purpose                                  |
|--------|-----------------------------------------|------------------------------------------|
| GET    | `/api/trade-kpis/service-lines`         | Picker — every service line + count of WOs in last period |
| GET    | `/api/trade-kpis/{serviceLineId}`       | Bundle for the period (`from` / `to` query params; defaults to last ~3 months) |

---

## 8. Permissions

Defined in `config/auth.php`:

| Permission                  | Grants                                                         |
|-----------------------------|----------------------------------------------------------------|
| `skills.view`               | Read catalog + per-user grants + matrix.                       |
| `skills.manage`             | Create / update / delete catalog; grant / revoke per user.     |
| `dispatch_board.view`       | Read the board payload.                                        |
| `dispatch_board.assign`     | Drop techs onto WOs (the writeable verb).                      |
| `trade_kpis.view`           | Read all KPI bundles.                                          |

Two notes:

- **`dispatch_board.assign` is its own perm.** Distinct from
  `dispatch_board.view` so a junior dispatcher can read the board
  without being able to commit assignments. Most shops give both to
  the same role.
- **No `trade_kpis.export`.** All KPI data goes out via the same JSON
  endpoint as the dashboard renders; CSV/PDF export is a frontend
  concern.

---

## 9. Operator runbook

### Maintaining the skill matrix

1. **Add a skill** — `POST /api/skills` with slug + name + service
   line. The seed catalog is a starting point; expect to extend it
   per shop.
2. **Grant a skill** — `PUT /api/users/{userId}/skills/{skillId}`
   with `{ proficiency_level, certified_at, expires_at, notes }`.
   Re-PUT to upgrade proficiency or refresh cert dates.
3. **Cert expiry** — there's no automated reminder cron yet (see
   §10). The matrix UI surfaces "expiring in next 30 days" via
   client-side filter against `expires_at`.

### Daily dispatching

1. Open the board (`GET /api/dispatch-board?unassigned_only=1` for
   the new-work view; drop the filter for the full board).
2. For each unassigned WO, the `candidates` list shows eligible techs
   sorted by `open_workload` ASC. Drag onto the desired tech; the
   POST validates again before persisting.
3. Reassign by dragging to a different tech (the POST overwrites
   `assigned_technician_id`). Unassign by dragging back to the
   "unassigned" lane (POST with `technician_id: null`).
4. Forced override (rare) — clear `required_skill_id` on the WO
   first via the WO edit endpoint, then assign. The audit log
   records both actions.

### Reading the KPI dashboard

- **MTTR rising over time** — usually a bench-strength problem
  (techs are taking longer per repair). Cross-reference with the
  skill matrix: are recent hires below `competent` on the relevant
  skills?
- **MTBF dropping** — assets are failing more often. Cross-reference
  with the inventory/install history; usually points at a vendor
  or batch quality issue.
- **install_on_time_pct dropping** — scheduling problem; check the
  dispatch board for backlog.
- **routes.completion_pct dropping** — see Phase 15 runbook;
  usually generator/sweeper or a coverage gap.

### Onboarding a new vertical

1. Add the service_line row (already present for the seeded nine —
   add new ones via `POST /api/service-lines`).
2. Add a starter set of skills: `POST /api/skills` per skill, with
   `service_line_id` set.
3. Grant existing techs into the new line via `user_service_lines`
   (the membership table) — the dispatch board will then surface
   them as candidates.
4. Trade KPIs become available the first day there are any WOs in
   the new line.

---

## 10. Known gaps and follow-ups

- **No cert-expiry alerting.** The schema has `expires_at` and the
  index, but there's no cron to surface "tech X's bloodborne cert
  expires next week" as a ticket or notification. Add when a
  customer asks; pattern would mirror the lease-expiry alerts in
  Phase 13.
- **No skill-required-suggestion at WO creation.** The WO form
  accepts `required_skill_id` as a free choice; we could suggest
  one based on the service line + WO type (e.g. "auto + brake job
  → auto_brakes"). Out of scope for this phase.
- **Eligibility doesn't consider on-shift status.** A tech assigned
  to today's board may be on PTO, in training, or off-shift; the
  board doesn't know. Cross-reference with the leave-request
  module by hand for now.
- **Trade KPI bundle is fixed at four sections.** A customer asking
  for "labor hours by skill" or "cost per work order" would need
  a new section + new SQL; the contract is open for extension but
  not yet versioned.
- **`open_workload` denorm is computed at board-render time.** Two
  dispatchers viewing the board concurrently may see slightly
  different counts as WOs move. Acceptable; the assign endpoint
  re-validates.
- **No bulk-grant endpoint.** Granting a batch of new hires the
  same N skills requires N×M PUTs. Add `POST /api/skills/bulk-grant`
  if onboarding load justifies it.

---

## 11. Engineering decisions worth keeping

### 11.1 Why the dispatch board returns one fat payload

We considered separate endpoints for workorders / candidates /
technicians / skills, joined client-side. Rejected: the dispatcher's
view changes rapidly as they drag-assign; refetching N endpoints per
change is wasteful, and the frontend already has the full payload in
memory after the first GET. The fat payload is a deliberate "render
the whole board, then assign deltas" model — fast to redraw, simple
to reason about.

### 11.2 Why eligibility is in the service, not a database trigger

A trigger on `workorders.assigned_technician_id` UPDATE would have
been "harder to bypass" — but also opaque to operators when it
rejected, hard to test, and impossible to override from a
maintenance script. The PHP service can return human-readable
errors, can be unit-tested without a database, and a maintenance
script can call `DispatchBoardService::assignWorkorder()` directly
or bypass it for an explicit override. Database triggers earn their
keep when the data layer must enforce something the application
layer might forget; here the application layer is the only writer.

### 11.3 Why VARCHAR for proficiency, not ENUM

The vocabulary will grow — a customer in healthcare might need
"licensed" as a fourth tier above expert; a customer in security
might split "competent" into "field-rated" vs "remote-only". The
`UserSkill::ALLOWED_PROFICIENCY_LEVELS` constant is the in-PHP
enforcement; extending the vocabulary is a one-line constant edit
and zero migration.

### 11.4 Why MTTR/MTBF are scoped to site-asset WOs

A non-asset WO ("inspection at customer X") doesn't represent
*repair* — including it would inflate the apparent reliability
sample and distort the metric for asset-heavy verticals. Scoping to
`site_asset_id NOT NULL` is the cleanest way to express "repair
work" in the existing schema. Verticals that don't track assets
(IT helpdesk for end-user troubleshooting, e.g.) get NULL MTTR/MTBF
in their KPI bundle, which the UI renders as "—" instead of
zero (zero would imply "instant repair", which is wrong).

### 11.5 Why service_line_id on `skills` is SET NULL on delete

If a customer retires a service line, we don't want their entire
skill catalog to disappear with it. SET NULL converts the orphaned
skills into cross-trade ones — preserved for historical lookup.
Customers can clean them up by hand or leave them; either way the
deletion isn't catastrophic.

### 11.6 Why we kept the seed catalog small

30 starter skills feel sparse for nine service lines. The
temptation to seed 200 is real ("we can cover every sub-trade!").
Resisted: every shop has its own opinions, and a too-detailed seed
becomes friction (operators have to delete or re-categorize what
they don't use). Seeding the broad strokes lets the customer
extend up to their level of granularity without first having to
prune.

---

## 12. Files of record

### Migrations
- `database/migrations/167_technician_skill_matrix.sql`
- `database/migrations/168_workorder_required_skill.sql`

### Models
- `src/Models/Skill.php`
- `src/Models/UserSkill.php` (PROFICIENCY_*, ALLOWED_PROFICIENCY_LEVELS)

### Services
- `src/Services/Skills/SkillMatrixService.php` (`userIdsForSkill`,
  `matrixForUsers`, `grantOrUpdate`, `revoke`)
- `src/Services/Skills/SkillMatrixController.php`
- `src/Services/Skills/SkillRepository.php`
- `src/Services/Skills/UserSkillRepository.php`
- `src/Services/DispatchBoard/DispatchBoardService.php`
  (`board`, `assignWorkorder`, `assertTechnicianEligible`)
- `src/Services/DispatchBoard/DispatchBoardController.php`
- `src/Services/TradeKpis/TradeKpiService.php`
  (`bundle`, `reliabilityMetrics`, `ticketMetrics`, `workorderMetrics`,
  `routeMetrics`)
- `src/Services/TradeKpis/TradeKpiController.php`

### Routes
- `routes/modules/skills.php`
- `routes/modules/dispatch_board.php`
- `routes/modules/trade_kpis.php`

### Frontend (representative)
- `src/react/views/skills/SkillMatrix.jsx`
- `src/react/views/skills/SkillCatalog.jsx`
- `src/react/views/dispatch/DispatchBoard.jsx`
- `src/react/views/trade-kpis/TradeKpiDashboard.jsx`
- `src/services/skills.service.js`
- `src/services/dispatch-board.service.js`
- `src/services/trade-kpis.service.js`

### Permissions
- `config/auth.php` — `skills.{view,manage}`,
  `dispatch_board.{view,assign}`, `trade_kpis.view`

---

## 13. How Phase 17 closes out the WOMS expansion

Phase 17 is the last phase in the original WOMS expansion plan
(`docs/woms-expansion-plan.md` Phases 11–17, with Phase 18 the
procurement / vendor-portal capstone documented separately). The
through-line:

| Phase | Brought                                                   |
|-------|-----------------------------------------------------------|
| 11    | Service lines as a routing dimension                      |
| 12    | Property management vertical (units, tenants, leases)     |
| 13    | Asset lease/acquisition/decommission lifecycle            |
| 14    | IT helpdesk severity, software CMDB, change management    |
| 15    | Recurring service routes (cleaning + PM rounds)           |
| 16    | Security credentials + POS terminal monitoring            |
| 17    | The operations layer that ties them together (THIS)       |
| 18    | Procurement + vendor self-service portal                  |

By the end of 17, a single operator can run all nine verticals from one
dispatch board, validate technician eligibility against an evolving
skill matrix, and read MTTR / MTBF / completion KPIs per vertical
without leaving the app. The remaining gaps (cert-expiry alerting,
shift-aware eligibility, KPI extensibility) are surfaced in §10 and
are the natural starting points for a Phase 19 or a customer-driven
extension.
