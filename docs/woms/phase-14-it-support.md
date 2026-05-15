# Phase 14 — IT Support Vertical

> Plan reference: `docs/woms-expansion-plan.md` Phase 14, items **M8** (helpdesk
> severity overlay), **M9** (software CMDB / licensing), **S3** (change
> management with CAB).
>
> Phase status: **shipped**. Migrations 161–164 are deployed; routes and
> services are wired into the staff app.

Phase 14 turns the existing ticketing primitive into an ITIL-flavored helpdesk
without forking it, and adds two new top-level modules — software inventory
(license pools, assignments, installs, compliance) and change management
(RFCs with CAB voting). The three concerns are bundled because they share an
audience: the IT-vertical customer. A managed-services provider running a
phone bill on top of the same workorder pipeline as a body shop needs all
three to come up at once.

The three sub-modules are deliberately **independent** at the data layer.
Tickets do not foreign-key software_assets; change_requests reference an
*originating* ticket but only as a SET NULL pointer. You can adopt any
subset (just helpdesk severity, just software CMDB, just CAB) without the
others.

---

## 1. What shipped

### M8 — IT helpdesk overlay (migration 161, 162)

Adds four columns to `tickets` and one column to `ticket_escalation_rules`:

| Column                          | Type            | Purpose                                                             |
|---------------------------------|-----------------|---------------------------------------------------------------------|
| `tickets.severity`              | VARCHAR(8)      | ITIL severity (`P1`–`P4`). Distinct from `priority`.                |
| `tickets.affected_users_count`  | INT UNSIGNED    | Headcount blast-radius signal (drives auto-derivation).             |
| `tickets.business_impact`       | TEXT            | Free-text narrative; **required** for P1/P2.                        |
| `tickets.it_request_kind`       | VARCHAR(40)     | `outage` / `incident` / `request` / `question` (gate for IT logic). |
| `ticket_escalation_rules.match_severity` | VARCHAR(8) | Lets escalation rules match by severity, ANDed with other matchers. |

**No new entity.** A non-IT ticket leaves `it_request_kind` NULL and the
helpdesk overlay is a pure no-op — the existing ticket pipeline keeps
working unchanged for body-shop / dispatch workflows.

### M9 — Software CMDB (migration 163)

Four new tables:

| Table                    | Role                                                                     |
|--------------------------|--------------------------------------------------------------------------|
| `software_assets`        | Catalog: publisher / title / SKU / category. Customer-scoped or shared.  |
| `license_seats`          | License pool: type (perpetual/subscription/concurrent), seat counts.     |
| `license_assignments`    | Consumption ledger: seat ↔ user OR seat ↔ site_asset (XOR via CHECK).    |
| `installed_software`     | Discovery / install record: site_asset ↔ software_asset, optional license link. |

The `license_seats.seats_assigned` counter is **denormalized** (kept in sync
by `SoftwareInventoryService` inside the same transaction as the
assignment write) so the over-allocation guard is O(1) on insert, not a
COUNT(*) on every assign. A reconcile endpoint exists for drift recovery.

### S3 — Change management with CAB (migration 164)

Two new tables:

| Table              | Role                                                                                                  |
|--------------------|-------------------------------------------------------------------------------------------------------|
| `change_requests`  | The RFC: type, risk, planned vs actual windows, originating ticket, affected asset, full state machine. |
| `cab_approvals`    | Per-CAB-member vote (approve / reject / abstain) with UNIQUE (change_request_id, user_id).            |

State machine is enforced in PHP (`ChangeRequest::TRANSITIONS`). Originating
ticket and affected asset are SET NULL — the RFC outlives the ticket and
asset that prompted it.

---

## 2. Data model

### 2.1 IT helpdesk overlay (additive on `tickets`)

```sql
ALTER TABLE tickets
    ADD COLUMN severity              VARCHAR(8)    NULL  AFTER priority,
    ADD COLUMN affected_users_count  INT UNSIGNED  NULL  AFTER severity,
    ADD COLUMN business_impact       TEXT          NULL  AFTER affected_users_count,
    ADD COLUMN it_request_kind       VARCHAR(40)   NULL  AFTER business_impact;

CREATE INDEX idx_tickets_severity         ON tickets (severity);
CREATE INDEX idx_tickets_it_request_kind  ON tickets (it_request_kind);
```

Whitelisted values come from `App\Models\Ticket`:

```php
public const SEVERITIES        = ['P1', 'P2', 'P3', 'P4'];
public const IT_REQUEST_KINDS  = ['outage', 'incident', 'request', 'question'];
```

`ticket_escalation_rules` gains:

```sql
ALTER TABLE ticket_escalation_rules
    ADD COLUMN match_severity VARCHAR(8) NULL AFTER match_priority;

CREATE INDEX idx_ter_match_severity ON ticket_escalation_rules (match_severity);
```

`TicketEscalationService` matches it as **AND** with the other matchers — a
rule with `match_priority = 'high'` AND `match_severity = 'P1'` only fires
on tickets that match both. NULL on the rule means "any severity".

### 2.2 Software CMDB

```
software_assets (catalog)
    ↓ 1..N
license_seats (pool: 25 seats of "Office 365 E3" for customer X)
    ↓ 1..N
license_assignments (ledger: seat #4 → user U OR site_asset A)

site_assets (existing, from CMDB)              software_assets
    ↓ 1..N                                          ↑ 1..N
installed_software (discovery: machine has the title installed)
    └── optional FK → license_assignments.id (if licensed)
```

Key constraints:

- `software_assets.customer_id` is NULL for **shared catalog** entries
  (e.g. a vendor-distributed title every tenant can install). NOT NULL for
  customer-private titles.
- `license_assignments` has a CHECK enforcing exactly one of
  `assignee_user_id` / `assignee_site_asset_id` is set — a seat is assigned
  to a person OR a machine, never both.
- Both assignee FKs are **SET NULL on delete**: when a user or asset is
  removed, the assignment row stays for audit (with assignee = NULL)
  rather than being silently deleted.
- `installed_software.license_assignment_id` is **nullable**. An install
  without a license is a finding for the compliance feed, not an error.
- `license_seats.seats_assigned` is denormalized; see §3.2 for the write
  protocol.

### 2.3 Change management

```
change_requests (RFC)
    ├── customer_id             (NOT NULL — RFC always belongs to a customer)
    ├── originating_ticket_id   (NULL on SET NULL — RFC outlives ticket)
    ├── affected_site_asset_id  (NULL on SET NULL)
    ├── requested_by_user_id    (RESTRICT — author must exist)
    ├── assigned_to_user_id     (SET NULL)
    ├── status                  (state machine; VARCHAR(32))
    ├── change_type             ('standard' / 'normal' / 'emergency')
    ├── risk_level              ('low' / 'medium' / 'high')
    ├── planned_start, planned_end       (calendar grid input)
    ├── actual_start, actual_end         (filled when in_progress / completed)
    ├── implementation_plan, rollback_plan, test_plan  (TEXT)
    ├── decision_notes          (filled at approve/reject)
    └── created_at, updated_at

cab_approvals (one row per voter per RFC)
    ├── change_request_id  (CASCADE)
    ├── user_id            (RESTRICT — voter identity must persist)
    ├── decision           ('approve' / 'reject' / 'abstain')
    ├── comment            (TEXT)
    ├── voted_at
    └── UNIQUE (change_request_id, user_id)  — one vote per CAB member
```

The UNIQUE constraint is the integrity backbone: re-voting overwrites via
`INSERT ... ON DUPLICATE KEY UPDATE`, so a CAB member can flip their vote
without spawning duplicate rows.

---

## 3. The two load-bearing decisions

Two ideas in this phase need to be understood before reading the code.

### 3.1 Severity is not priority — and the auto-derivation rule

Pre-Phase-14 tickets had `priority` only. The new `severity` is **separate**
and means a different thing:

| Concept   | Source           | Drives                                  |
|-----------|------------------|-----------------------------------------|
| Priority  | Operator opinion | Queue ordering, "what do I work on next" |
| Severity  | ITIL classification | Escalation pages, SLA, CAB urgency thresholds |

A P1 ticket might still be `priority=low` if no one is online to work it;
a P4 ticket might be `priority=high` because the customer is loud. Don't
collapse them.

`ItHelpdeskService::deriveSeverity()` auto-fills severity when the caller
doesn't pass one explicitly:

```
kind        →  base severity
outage      →  P2
incident    →  P3
request     →  P4
question    →  P4

then upgraded by affected_users_count:
  ≥ 200 users  →  at least P1
  ≥  50 users  →  at least P2
```

Two non-obvious rules:

1. **Auto-derivation never lowers.** `maxSeverity()` returns the more
   severe of (caller value, derived value). An operator can manually
   bump P3 → P1 and a later edit that re-derives a lower base will not
   silently undo them.
2. **P1/P2 require `business_impact`.** Validated server-side; the
   controller surfaces 400. This is the only "must fill in narrative"
   forced by the helpdesk overlay — P3/P4 may omit.

### 3.2 The denormalized `seats_assigned` counter

Compliance views want to answer "is this license pool over-allocated?" in
O(1). Computing `COUNT(*) WHERE license_seat_id = X AND released_at IS NULL`
on every page render does not scale to thousands of seats × dozens of
operators clicking.

`license_seats.seats_assigned` carries the count and is **only ever
mutated by `SoftwareInventoryService`** via `incrementAssigned()` /
`decrementAssigned()`, called inside the same transaction as the
assignment row insert/update. The over-allocation check reads the column
under `FOR UPDATE` and rejects when `seats_assigned + 1 > seats_total`.

Drift is still possible in two cases — a hand-edited row, a transaction
that committed the assignment but rolled back the counter due to bug —
so the service exposes `reconcileSeatCounters()` (admin-only) which
re-derives the truth from `COUNT(*)` and patches the column. Each fix is
audited as `license_seat.counters_reconciled` so post-incident reviews
can spot how often it fires.

**Rule of thumb for callers:** never write `seats_assigned` directly,
even from a migration. If you must adjust it, do it through the service
(or the reconcile endpoint), and the audit log will reflect the change.

---

## 4. Change request state machine

Defined in `App\Models\ChangeRequest::TRANSITIONS`. Forward edges only,
plus `cancelled` reachable from any non-terminal state:

```
                                         ┌───────────┐
                                         │ rejected  │ (terminal)
                                         └───────────┘
                                              ▲
draft ──► submitted ──► cab_review ───────────┤
                                              ▼
                                         ┌───────────┐
                                         │ approved  │
                                         └─────┬─────┘
                                               ▼
                                         ┌───────────┐
                                         │ scheduled │
                                         └─────┬─────┘
                                               ▼
                                         ┌───────────────┐
                                         │  in_progress  │
                                         └─────┬─────────┘
                                               ▼
                              ┌────────────────┴────────────────┐
                              ▼                                 ▼
                       ┌─────────────┐                  ┌──────────────┐
                       │  completed  │ (terminal)       │ rolled_back  │ (terminal)
                       └─────────────┘                  └──────────────┘

  any non-terminal status ──► cancelled (terminal)
```

Transition gating:

- Each transition is its own POST (`/api/change-requests/{id}/transition`
  with body `{ to_status: 'submitted', note: '...' }`). The service
  validates the edge is in `TRANSITIONS` for the current status; an
  invalid edge throws `InvalidArgumentException` → 400.
- Moving into `in_progress` stamps `actual_start = NOW()` if not already
  set. Moving into `completed` / `rolled_back` stamps `actual_end`.
- Moving into `approved` or `rejected` requires `decision_notes`
  (validated by the service).

### 4.1 The CAB tally and `decideFromCab`

For RFCs in `cab_review`, individual votes are recorded via
`POST /api/change-requests/{id}/votes`. A separate endpoint —
`POST /api/change-requests/{id}/decide` — auto-resolves the RFC from the
current tally:

```
body options:
  minimum_voters: integer ≥ 1  (default 1)
  threshold:      'majority' | 'unanimous'  (default 'majority')

algorithm:
  1. RFC must be in cab_review (else: { decided: false }, no-op)
  2. Sum approve + reject votes; ignore abstentions for quorum
  3. If voted < minimum_voters: { decided: false }
  4. threshold='majority':  approve > reject  →  approve
                            else              →  reject
  5. threshold='unanimous': zero rejects AND approve ≥ min_voters → approve
                            else                                  → reject
  6. Auto-write decision_notes summarizing the tally so the audit
     trail records what the math was at the moment of decision.
```

The tally call is the one place a non-CAB-member operator can move the
RFC out of `cab_review` — it's a deterministic projection of the votes,
not a fresh decision, so authorization is `change_management.manage`
(not a separate "decide" perm).

Abstentions count toward the per-RFC participation record but not
toward quorum. This matches typical CAB practice where a member who
declares conflict-of-interest abstains and the remaining members
decide.

---

## 5. API surface

All endpoints under `Middleware::auth()`. Read perms / write perms vary
per module — see §6.

### 5.1 IT helpdesk overlay

No new routes. The existing `/api/tickets` endpoints accept the four
new fields on POST/PUT bodies; `TicketController` invokes
`ItHelpdeskService::applyRules()` before persisting to validate +
auto-derive. Non-IT tickets are unchanged because `applyRules()` is a
no-op when `it_request_kind` is NULL.

`/api/ticket-escalation-rules` similarly accepts `match_severity` on
POST/PUT.

### 5.2 Software CMDB (`routes/modules/software_inventory.php`)

| Method | Path                                                  | Purpose                                            |
|--------|-------------------------------------------------------|----------------------------------------------------|
| GET    | `/api/software-titles`                                | List catalog (filters: customer, publisher, search, include_shared) |
| GET    | `/api/software-titles/{id}`                           | Show one title                                     |
| POST   | `/api/software-titles`                                | Create title                                       |
| PUT    | `/api/software-titles/{id}`                           | Update title                                       |
| DELETE | `/api/software-titles/{id}`                           | Delete title (blocked if seats/installs reference) |
| GET    | `/api/software-license-pools`                         | List license pools                                 |
| GET    | `/api/software-license-pools/{id}`                    | Show pool with seat counts                         |
| POST   | `/api/software-license-pools`                         | Create pool                                        |
| PUT    | `/api/software-license-pools/{id}`                    | Update pool                                        |
| DELETE | `/api/software-license-pools/{id}`                    | Delete pool                                        |
| GET    | `/api/software-license-assignments`                   | List assignments (active_only filter)              |
| POST   | `/api/software-license-assignments`                   | **Assign** seat to user or asset (decrements seat) |
| POST   | `/api/software-license-assignments/{id}/unassign`     | Release seat (increments back)                     |
| GET    | `/api/software-installs`                              | List installs (filter `unlicensed_only` for compliance) |
| POST   | `/api/software-installs`                              | Record an install                                  |
| DELETE | `/api/software-installs/{id}`                         | Remove install                                     |
| POST   | `/api/software-installs/{id}/link`                    | Link install to a license assignment retroactively |
| GET    | `/api/software-compliance`                            | Over-allocation + unlicensed-install feed (per-customer) |
| POST   | `/api/software-reconcile`                             | Admin: rebuild `seats_assigned` counters from truth |

### 5.3 Change management (`routes/modules/change_management.php`)

| Method | Path                                       | Purpose                                            |
|--------|--------------------------------------------|----------------------------------------------------|
| GET    | `/api/change-requests`                     | List (filters: customer, status, type, risk, window range) |
| GET    | `/api/change-requests/{id}`                | Show RFC + approvals tally                         |
| POST   | `/api/change-requests`                     | Create RFC (status starts `draft`)                 |
| PUT    | `/api/change-requests/{id}`                | Update RFC fields (state machine fields are read-only) |
| POST   | `/api/change-requests/{id}/transition`     | Move state via state machine                       |
| GET    | `/api/change-requests/{id}/approvals`      | List CAB votes + tally                             |
| POST   | `/api/change-requests/{id}/votes`          | Record / overwrite this caller's vote              |
| POST   | `/api/change-requests/{id}/decide`         | Auto-resolve from tally (majority / unanimous)     |
| GET    | `/api/change-window`                       | Calendar query: RFCs with planned_start / planned_end overlap a window |

`/api/change-window` returns RFCs scheduled to touch a date range, intended
to back a calendar widget so an operator can see "what changes are
landing this weekend" without paging through the list. It's a read-only
projection with `start` / `end` query params (ISO 8601).

---

## 6. Permissions

Defined in `config/auth.php`:

| Permission                       | Grants                                                     |
|----------------------------------|------------------------------------------------------------|
| `tickets.view` / `tickets.manage` | Existing — IT overlay reuses ticket perms.                |
| `software_inventory.view`        | Read titles, pools, assignments, installs, compliance.    |
| `software_inventory.manage`      | All write actions on software CMDB.                       |
| `software_inventory.reconcile`   | `POST /api/software-reconcile` (admin-only).              |
| `change_management.view`         | Read RFCs and approvals.                                  |
| `change_management.manage`       | Create / update / transition / vote / decide.             |

Two design notes:

1. **No separate "approve" perm on change_management.** A CAB member is
   anyone with `change_management.manage` — membership is implicit, not
   table-driven. Customers who need a smaller CAB can scope the perm
   tighter via roles. We chose this over a `cab_members` table because
   small shops want CAB = "the IT team", not a curated list.
2. **`software_inventory.reconcile` is its own perm.** Reconcile reads
   the entire customer's seat space and rewrites counters; we didn't
   want it implied by `manage` (which a junior tech might have).

---

## 7. Operator runbook

### Daily

- IT ops walks the **unlicensed installs** feed
  (`GET /api/software-installs?unlicensed_only=1`). Each row is either
  (a) an install we haven't yet linked to an existing seat, or (b) an
  unauthorized install. Linking is one POST to `/link`; unauthorized
  installs become tickets via the standard ticket flow.
- IT ops walks the **over-allocation** feed (`/api/software-compliance`)
  to find pools where `seats_assigned > seats_total`. Counter drift is
  the usual cause; otherwise it's an unauthorized assign that bypassed
  the service (rare — should investigate).

### Weekly / on-demand

- CAB meeting drives `/api/change-requests?status=cab_review`. Members
  vote individually; chair runs `POST /decide` after the meeting with
  the agreed `threshold` (most teams use `majority` with
  `minimum_voters` matching their quorum bylaws).
- Standard changes (low-risk, pre-approved templates) skip CAB by
  going `draft → submitted → approved → scheduled` directly. The state
  machine permits this because the `submitted → approved` edge is
  legal — it's an operator decision whether a particular RFC needs
  CAB review.

### Counter reconcile

If the compliance feed shows drift, run:

```
POST /api/software-reconcile
```

Returns a list of `{ seat_id, before, after }`. Each fix is audited.
Run during a low-traffic window — the reconcile holds row locks across
all seats it touches. Frequency to set: monthly until you've gone three
runs with zero fixes, then drop to quarterly. If a single run produces
more than ~10 fixes you have a write path bypassing the service, which
is a bug to chase.

### Severity escalation tuning

Severity-aware escalation rules are configured via the existing
`/api/ticket-escalation-rules` endpoints. Pattern:

```
rule: { match_severity: 'P1', match_status: 'open',
        delay_minutes: 15, action: 'page_oncall' }
rule: { match_severity: 'P2', match_status: 'open',
        delay_minutes: 60, action: 'notify_team_lead' }
```

`TicketEscalationService` ANDs all the `match_*` predicates, so adding
severity to existing rules narrows them — verify intent before
introducing severity matchers on previously-firing rules.

---

## 8. Frontend integration

### Helpdesk overlay

Existing ticket forms got four optional fields below the usual
priority / queue selectors:

- `it_request_kind` — select; revealing this field reveals the others.
- `severity` — select; placeholder shows "Auto: P3" so operators see
  what the server will derive.
- `affected_users_count` — number input; mouseover hint mentions the
  P1/P2 thresholds.
- `business_impact` — textarea; required-asterisk only when severity
  is P1/P2.

### Software CMDB

New page set under "IT" in the staff nav (visible to users with
`software_inventory.view`):

- **Titles** — catalog browser, supports the shared/private toggle.
- **License pools** — per-customer view; click-through to seat
  utilization bar with assigned/total.
- **Assignments** — search by user or asset; bulk-unassign for
  deprovisioning.
- **Installs** — filterable table; the unlicensed feed has its own
  saved view.
- **Compliance** — single-screen dashboard: over-allocated pools at
  top, unlicensed installs below, expiring-soon pools sidebar.

### Change management

New page set under "Operations" in the staff nav:

- **RFC list** — filter by status, type, risk, customer.
- **RFC detail** — tabbed: Overview, Plans (impl/rollback/test), CAB
  votes, History (audit log filtered to entity_type='change_request').
- **CAB voting widget** — inline on RFC detail; the caller's own vote
  is editable, others are read-only.
- **Change calendar** — `/api/change-window`-backed week/month view
  with planned vs actual ranges drawn as overlapping bars (so a
  change that overran shows visually).

---

## 9. Known gaps and follow-ups

- **No native CAB-membership table.** As noted in §6, CAB = anyone with
  `change_management.manage`. Customers asking for "this RFC needs
  votes from these specific 3 people" will need a follow-up phase.
- **`installed_software` discovery has no agent.** The table is
  populated manually or by future integrations (an SCCM connector,
  a JAMF connector, etc.). Until then, expect "manual install entry"
  to be tedious for fleet customers.
- **Severity SLA timers are not modeled.** P1 → "must respond in 15 min,
  resolve in 4 hours" is currently expressed as escalation rules, not
  as SLA breach reporting. A future S* item should add SLA targets
  per severity and a breach feed.
- **Change calendar doesn't yet block on conflicts.** The `/decide`
  endpoint and `/transition` to `scheduled` will happily put two
  changes in the same window for the same asset. Conflict detection
  is a known follow-up.
- **`software_assets.deletion` is hard-blocked when references exist.**
  No "archive" status yet — operators have to unassign all seats and
  remove all installs first, which is correct but tedious for retired
  titles. A soft-archive flag may follow.

---

## 10. Engineering decisions worth keeping

These are the choices that surprised reviewers, with the rationale that
keeps them stable across future edits.

### 10.1 Why severity is a column on `tickets`, not a join

We considered `ticket_severity_overlay (ticket_id, severity, ...)` so the
overlay could be optional at the schema level. Rejected for two reasons:
(1) every IT ticket would do an extra join on every list query — the
helpdesk views are the hot path; (2) a ticket without an overlay row
is indistinguishable in queries from one with severity='P3', so the
overlay row would have to be created eagerly anyway. Columns on
`tickets` directly are NULL for non-IT tickets and free at index time.

### 10.2 Why `seats_assigned` is denormalized

The COUNT(*) approach is fine until you have a fleet customer with 5,000
seats across 40 pools and a tech opens the compliance page. We measured
~80ms per pool with the join approach — 3+ seconds for the dashboard.
Denormalization is invasive but localized: only `SoftwareInventoryService`
writes the column, and `reconcileSeatCounters()` is the relief valve.
Reviewers should object only if they find a write path bypassing the
service.

### 10.3 Why CAB votes don't go through the state machine

A vote isn't a state transition — it's input into a *future* transition.
We deliberately kept votes in their own table with their own POST so
operators can record votes incrementally during a meeting without
the RFC churning between statuses. The `decide` endpoint is the bridge
back to the state machine.

### 10.4 Why the IT-helpdesk overlay extends `tickets` instead of forking

A managed-services provider often runs both kinds of work — an IT
incident *and* a body shop on the same backend (think: MSP that does
both desk-side support and fleet vehicle work). Forking would have
forced operators to learn two separate ticket workflows. The overlay
approach means the same `/api/tickets` endpoint serves both, gated
solely by whether `it_request_kind` is set.

### 10.5 Why originating ticket / affected asset are SET NULL

An RFC may take weeks to work through CAB, scheduling, and execution.
The ticket that prompted it might be closed and archived in the
meantime; the asset might be retired. The RFC should still be readable
and auditable after both are gone — a SET NULL preserves the historical
narrative ("we did this change *because* of an outage we don't have a
ticket row for anymore") without leaving a dangling FK.

---

## 11. Files of record

### Migrations
- `database/migrations/161_it_helpdesk_severity.sql`
- `database/migrations/162_ticket_escalation_severity.sql`
- `database/migrations/163_software_cmdb.sql`
- `database/migrations/164_change_management.sql`

### Models
- `src/Models/Ticket.php` (SEVERITIES, IT_REQUEST_KINDS constants)
- `src/Models/SoftwareAsset.php`
- `src/Models/LicenseSeat.php`
- `src/Models/LicenseAssignment.php`
- `src/Models/InstalledSoftware.php`
- `src/Models/ChangeRequest.php` (STATUS_*, TRANSITIONS)
- `src/Models/CabApproval.php`

### Services
- `src/Services/Tickets/ItHelpdeskService.php` (severity derivation, validation)
- `src/Services/Tickets/TicketEscalationService.php` (severity-aware match)
- `src/Services/SoftwareInventory/SoftwareInventoryService.php`
- `src/Services/SoftwareInventory/SoftwareInventoryController.php`
- `src/Services/SoftwareInventory/{SoftwareAsset,LicenseSeat,LicenseAssignment,InstalledSoftware}Repository.php`
- `src/Services/ChangeManagement/ChangeRequestService.php`
- `src/Services/ChangeManagement/ChangeRequestController.php`
- `src/Services/ChangeManagement/{ChangeRequest,CabApproval}Repository.php`

### Routes
- `routes/modules/change_management.php`
- `routes/modules/software_inventory.php`
- (helpdesk overlay rides on existing `routes/modules/tickets.php`)

### Frontend (representative)
- `src/react/views/it-support/SoftwareTitles.jsx`
- `src/react/views/it-support/LicensePools.jsx`
- `src/react/views/it-support/LicenseAssignments.jsx`
- `src/react/views/it-support/SoftwareCompliance.jsx`
- `src/react/views/change-management/ChangeRequestList.jsx`
- `src/react/views/change-management/ChangeRequestDetail.jsx`
- `src/react/views/change-management/ChangeCalendar.jsx`
- `src/services/software-inventory.service.js`
- `src/services/change-management.service.js`

### Permissions
- `config/auth.php` — `software_inventory.*`, `change_management.*` keys.
