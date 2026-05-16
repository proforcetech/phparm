# Phase 15 — Recurring Service Routes

> Plan reference: `docs/woms-expansion-plan.md` Phase 15, items **M7**
> (recurring service routes / route generator) and **S8** (visit-photo
> verification heuristics).
>
> Phase status: **shipped**. Migration 165 is deployed; route generator
> cron is live; mobile QR scan + photo upload paths are wired into the
> field PWA.

Phase 15 turns "PM rounds" into a first-class object. Cleaning is the
loud use case — a janitorial customer wants twelve identical visits a
month, each producing a small WO, each backed by a photo trail that
proves the work was done — but the same primitives serve recurring
security audits, equipment-PM rounds, and any "same techs, same sites,
same cadence" workflow.

The two halves of the phase (M7 generator + S8 verification) ship as
one unit because they share a data model: the generator produces the
visits the heuristics later score. Splitting them would have meant two
migrations and a doubled set of "did you wire the photos table to the
right visit row" tests.

---

## 1. What shipped

### M7 — Route definitions, materializer, lifecycle (migration 165)

Four new tables:

| Table                | Role                                                                  |
|----------------------|-----------------------------------------------------------------------|
| `service_routes`     | Route template + recurrence rule (cadence, horizon, defaults).        |
| `route_stops`        | Ordered stops (one per site/asset), `UNIQUE (route, sequence)`.       |
| `route_visits`       | Materialized occurrences — one per stop per scheduled slot.           |
| `route_visit_photos` | Verification photos with EXIF + perceptual hash.                      |

Plus:

- A unified cron entrypoint (`bin/cron/route-visit-generator.php`) that
  rolls visits forward and sweeps overdue ones into `missed`.
- A six-state visit lifecycle (planned → en_route → arrived → completed,
  with skipped / missed terminal branches) enforced in PHP.
- A QR-token scan endpoint (`/api/route-visits/scan/{token}`) so the
  printed sticker at each site is the mobile app's "I'm here" gesture.
- A lightweight auto-WO created per visit (linked via SET NULL) — the
  back office still bills, dispatches, and reports against WOs; the
  visit is the *route* lens on the same work.

### S8 — Photo verification heuristics

The `PhotoVerifier` service runs three independent checks per photo and
aggregates per visit:

| Heuristic            | Catches                                            |
|----------------------|----------------------------------------------------|
| EXIF time-window     | "I took this yesterday and uploaded today."        |
| EXIF geo-fence       | "I took this in the truck before driving to site." |
| Perceptual-hash dup  | "Tech reused last week's clean shot."              |

Heuristics are **advisory**: they set `verification_passed` (1 / 0 /
NULL) and `verification_notes` but never block the state transition.
The product position is "let the tech complete, flag for the back
office" — making the field worker fight a heuristic at 6am on a snowy
parking lot is the wrong tradeoff.

---

## 2. Data model

### 2.1 service_routes — the template

```sql
CREATE TABLE service_routes (
    id BIGINT UNSIGNED PK,
    customer_id INT UNSIGNED NOT NULL,
    service_line_id BIGINT UNSIGNED NULL,
    default_assigned_user_id INT UNSIGNED NULL,
    name, description, status,
    -- recurrence rule
    recurrence_type            VARCHAR(20)   -- daily|weekly|monthly|custom
    recurrence_interval        INT UNSIGNED  -- every N units
    recurrence_days_of_week    VARCHAR(20)   -- "1,3,5" (Sun=0) for weekly
    recurrence_day_of_month    TINYINT       -- 1-31, capped to month end
    recurrence_time_of_day     TIME
    -- materialization control
    start_date, end_date,
    generation_horizon_days SMALLINT DEFAULT 14,
    last_generated_through  DATETIME NULL,    -- generator checkpoint
    -- per-route defaults
    photo_verification_required TINYINT(1) DEFAULT 0,
    min_photos_per_visit         TINYINT  DEFAULT 0,
    estimated_visit_minutes      SMALLINT DEFAULT 30,
    notes
);
```

`last_generated_through` is the **only** mutable state the generator
keeps on the route — every other materialization decision falls out of
the recurrence rule. Resetting it to NULL re-emits visits from
`start_date` (idempotent because of the UNIQUE on
`route_visits.(route_stop_id, scheduled_for)`).

### 2.2 route_stops — ordered stops

```sql
CREATE TABLE route_stops (
    id BIGINT UNSIGNED PK,
    service_route_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    site_asset_id INT UNSIGNED NULL,
    stop_name, estimated_minutes, checklist_template_id,
    required_photos TINYINT NULL,   -- NULL = use route default
    notes, is_active,
    UNIQUE (service_route_id, sequence)
);
```

The `(service_route_id, sequence)` UNIQUE means a route's stops have a
deterministic order. Reorder via `POST /api/service-routes/{id}/stops/reorder`,
which atomically renumbers in a single transaction (avoids the temporary-
duplicate problem of "swap two rows with a UNIQUE on the column").

### 2.3 route_visits — materialized occurrences

```sql
CREATE TABLE route_visits (
    id BIGINT UNSIGNED PK,
    service_route_id BIGINT UNSIGNED NOT NULL,    -- denormalized from stop
    route_stop_id BIGINT UNSIGNED NOT NULL,
    workorder_id INT UNSIGNED NULL,               -- SET NULL on WO purge
    assigned_user_id INT UNSIGNED NULL,
    scheduled_for DATETIME NOT NULL,
    scheduled_window_minutes SMALLINT DEFAULT 60,
    status VARCHAR(20) DEFAULT 'planned',
    qr_token VARCHAR(64) NOT NULL,                -- UNIQUE
    en_route_at, arrived_at, arrival_lat, arrival_lng,
    completed_at, skipped_at, skip_reason, missed_at,
    photos_uploaded SMALLINT DEFAULT 0,           -- denormalized
    verification_passed TINYINT(1) NULL,          -- NULL=not yet evaluated
    verification_notes TEXT NULL,
    UNIQUE (qr_token),
    UNIQUE (route_stop_id, scheduled_for)         -- generator idempotency
);
```

Three denormalizations earn their keep:

1. **`service_route_id`** is duplicated from `route_stop_id` so the
   dispatcher's "today on this route" query doesn't have to join.
   Stops never move between routes (you'd delete and recreate), so
   the duplication is safe.
2. **`photos_uploaded`** lets the completion-time photo-count guard run
   in O(1). `RouteVisitService` is the only writer; it adjusts the
   counter inside the same transaction as the photos insert/delete.
3. **UNIQUE `(route_stop_id, scheduled_for)`** turns the generator into
   a true idempotent — re-running `runDueRoutes()` mid-window does
   `INSERT IGNORE` on the dup and moves on.

### 2.4 route_visit_photos — verification material

```sql
CREATE TABLE route_visit_photos (
    id BIGINT UNSIGNED PK,
    route_visit_id BIGINT UNSIGNED NOT NULL,    -- CASCADE
    uploaded_by_user_id INT UNSIGNED NULL,
    file_path VARCHAR(255) NOT NULL,
    file_mime, file_size_bytes,
    exif_taken_at DATETIME NULL,
    exif_lat DECIMAL(10,7) NULL,
    exif_lng DECIMAL(10,7) NULL,
    perceptual_hash CHAR(16) NULL,              -- 64-bit dHash, indexed
    caption, uploaded_at, created_at, updated_at
);
```

EXIF and perceptual hash are extracted **at upload time** by
`PhotoVerifier::extractFromFile()` so the heuristics run cheaply later
without re-opening the file. A failed extraction (non-image upload,
EXIF stripped) leaves the columns NULL — heuristics treat NULL as
"can't evaluate", not as a fail.

---

## 3. The two load-bearing decisions

### 3.1 Visits are materialized, not computed-on-demand

We considered storing only routes + recurrence rules and computing the
"what visits are happening today" view in SQL on read. Rejected:

- **Assignment ownership** — once a visit is materialized, a dispatcher
  can reassign it (`/api/route-visits/{id}/reassign`) without forking
  the route. A computed view has no row to assign to.
- **Audit history** — we want a permanent record of "this stop was
  skipped, here's the reason", which only exists if the visit row
  exists.
- **Idempotent state machine** — the lifecycle (planned → en_route →
  arrived → completed) needs a stable identity to transition against.
  A computed projection has no identity.
- **WO linkage** — the per-visit auto-WO needs a permanent FK target.

The cost is the generator: a cron has to roll visits forward in time.
The benefit is everything downstream — assignment, audit, WO linkage —
becomes a normal CRUD problem.

### 3.2 Photos verify visits, not work orders

The auto-WO already accepts photo uploads (the field PWA has had this
forever). We deliberately added `route_visit_photos` as a **separate
table** rather than reusing the WO photo table. Rationale:

- A cleaning customer wants proof **this visit** happened, not just
  that "the WO has some photos". The visit-scoped table makes the
  query trivial (`WHERE route_visit_id = ?`) instead of "join WO
  photos, then filter by upload time near visit window".
- The S8 heuristics index a tight, purpose-built table — the
  perceptual-hash dedupe scan is bounded to a single customer's
  recent visits, not the entire site's WO photo history.
- The visit must outlive its auto-WO (FK is SET NULL), so the audit
  ledger and verification photos survive a WO purge or merge.

WO photos are still useful (they're what the office shows on the
invoice). Visit photos are the *compliance* lens — the one a customer
would subpoena.

---

## 4. The visit state machine

Defined in `App\Models\RouteVisit::TRANSITIONS`:

```
                              ┌─────────────┐
                              │   missed    │  (terminal — set by sweep cron)
                              └─────────────┘
                                    ▲
                                    │  (window expired without arrival)
                                    │
planned ──► en_route ──► arrived ──► completed   (terminal)
   │           │            │
   ├───────────┤            ├──► skipped         (terminal — tech declined w/ reason)
   │           │            │
   ├───────────┴────────────┘
   │     direct skip / missed allowed from planned & en_route
```

Transition specifics:

- **`planned → en_route`** — operator action; stamps `en_route_at`.
- **`planned → arrived` (skip en_route)** — allowed for the "I drove
  straight here" case. Stamps `arrived_at` + GPS.
- **`en_route → arrived`** — usually triggered by the QR scan, which
  also stamps GPS from the device.
- **`arrived → completed`** — gates on `min_photos_per_visit` (route
  default, with stop-level override). If the gate fails, the
  transition throws → 422 to the mobile app, which surfaces "you
  need N photos to complete this visit".
- **`arrived → completed`** also fires `PhotoVerifier::evaluateVisit()`
  inline, which sets `verification_passed` + `verification_notes`
  before returning the row. The mobile UI gets the score back
  immediately so the tech sees flags before they walk away from the
  site (and can re-shoot).
- **`* → skipped`** — operator action, requires `skip_reason`.
- **`planned/en_route → missed`** — usually set by the sweep cron, not
  the operator. The endpoint allows manual missed-marking for
  back-office cleanup.

The QR-scan endpoint is a thin wrapper around the state machine: it
resolves the token to a visit, attempts the planned→en_route or
en_route→arrived transition (depending on current state), and returns
the post-transition row. No new state is needed for "scanned" — the
scan IS the transition.

---

## 5. The generator and the sweep cron

`bin/cron/route-visit-generator.php` runs every 5 minutes and does two
passes per tick.

### 5.1 Pass 1 — generate due visits

`RouteVisitGenerator::runDueRoutes()`:

```
for each active route where status='active'
                   and (last_generated_through IS NULL
                        or last_generated_through < now + horizon_days):
    generateForRoute(route, now)
```

`generateForRoute`:

1. Compute the next slot dates from `now` to `now + horizon_days`,
   filtered by the recurrence rule.
2. For each slot × each active stop, `INSERT IGNORE` a new
   `route_visits` row with status='planned', a fresh `qr_token`,
   `assigned_user_id` defaulted from the route, and a 1:1 lightweight
   WO created via the existing WO service.
3. Update `last_generated_through = now + horizon_days`.

Three properties to keep:

- **Idempotent**: the UNIQUE `(route_stop_id, scheduled_for)` makes
  re-runs no-ops. A crashed generator that committed half its inserts
  will resume cleanly.
- **Cheap per route**: O(stops × slots), and a typical week-cadence
  route with 8 stops and a 14-day horizon emits 16 visits per tick at
  most.
- **Single-source-of-truth checkpoint**: `last_generated_through` is
  the only mutable state. Resetting it to NULL re-emits visits from
  `start_date` (idempotently) — useful for "we changed the recurrence
  rule, regenerate".

### 5.2 Pass 2 — sweep overdue

`RouteVisitService::sweepOverdueOpen()`:

```
UPDATE route_visits
   SET status = 'missed', missed_at = NOW()
 WHERE status IN ('planned', 'en_route')
   AND scheduled_for + INTERVAL scheduled_window_minutes MINUTE < NOW()
```

The 5-minute cadence is chosen so the dispatcher board's "today's
visits" view tracks reality within ~5 minutes. Lengthening the cadence
delays the missed flag; shortening it doesn't help (the windows are in
hours, not minutes).

### 5.3 Cron output

Stdout for healthy run:

```
[2026-05-04 08:00:00] route-visit-generator: routes_processed=42 visits_created=18 missed_swept=2
```

Failures exit non-zero with the message on stderr — the unified cron
runner picks both up for the ops dashboard.

---

## 6. Photo verification heuristics (S8)

`PhotoVerifier` has two integration points:

### 6.1 At upload (`extractFromFile`)

Called by the controller's photo-upload path. Reads EXIF (`exif_taken_at`,
`exif_lat`, `exif_lng`) and computes a 64-bit dHash → 16-char hex
string into `perceptual_hash`. Failures are silent — the photo is
accepted, the columns are NULL, and the dependent heuristic is a
"can't evaluate" rather than a fail.

### 6.2 At completion (`evaluateVisit`)

Called by `RouteVisitService` inside the same transaction as the
arrived→completed transition. For each photo on the visit:

```
score the three heuristics independently:

1. time_window:
   |exif_taken_at - arrived_at| ≤ time_window_minutes  (default 60)

2. geo_fence:
   haversine(exif_lat/lng, site.lat/lng) ≤ geo_radius_meters  (default 250m)

3. dup:
   for each candidate photo from the same customer in the last
   dup_lookback_days (default 30, capped at dup_candidate_limit=200):
     if hamming(perceptual_hash, candidate.perceptual_hash) ≤ 6:
       FAIL (with the candidate's visit id for forensic notes)
```

Aggregation: if ANY photo fails ANY heuristic, the visit is flagged
(`verification_passed = 0`); otherwise it passes (`= 1`). NULL means
"not yet evaluated" (a manual completion bypass, or a transition path
that didn't go through the verifier).

The defaults are deliberately lax — false-positives erode operator
trust faster than false-negatives. Override per route via the
`PhotoVerifier` constructor's `$config` arg if a customer needs strict
SLAs.

---

## 7. API surface

All endpoints under `Middleware::auth()`. Permission split:

- `service_routes.view` — read all
- `service_routes.manage` — write route + stop definitions, generator
  trigger, dispatcher reassign
- `service_routes.execute` — mobile/PWA state transitions, photo
  recording, QR scan

### Routes (`routes/modules/service_routes.php`)

| Method | Path                                                  | Purpose                                       |
|--------|-------------------------------------------------------|-----------------------------------------------|
| GET    | `/api/service-routes`                                 | List routes (filters: customer, status, etc.) |
| GET    | `/api/service-routes/{id}`                            | Show route                                    |
| POST   | `/api/service-routes`                                 | Create route                                  |
| PUT    | `/api/service-routes/{id}`                            | Update route + recurrence rule                |
| DELETE | `/api/service-routes/{id}`                            | Delete route (CASCADEs stops/visits/photos)   |
| POST   | `/api/service-routes/{id}/generate`                   | Manually run the generator for this route     |
| GET    | `/api/service-routes/{id}/stops`                      | List stops                                    |
| POST   | `/api/service-routes/{id}/stops`                      | Create stop                                   |
| POST   | `/api/service-routes/{id}/stops/reorder`              | Bulk reorder (atomic)                         |
| PUT    | `/api/route-stops/{id}`                               | Update stop                                   |
| DELETE | `/api/route-stops/{id}`                               | Delete stop (CASCADE existing visits)         |
| GET    | `/api/route-visits`                                   | List visits (filters: scheduled_from/to, status, route, stop, etc.) |
| GET    | `/api/route-visits/{id}`                              | Visit detail + photos                         |
| POST   | `/api/route-visits/{id}/transition`                   | Move state via state machine                  |
| POST   | `/api/route-visits/{id}/reassign`                     | Dispatcher reassigns to another tech          |
| GET    | `/api/route-visits/scan/{token}`                      | QR-scan resolver (transitions if applicable)  |
| GET    | `/api/route-visits/{id}/photos`                       | List visit photos                             |
| POST   | `/api/route-visits/{id}/photos`                       | Record a photo (already-uploaded path)        |
| POST   | `/api/route-visits/{id}/photos/upload`                | Multipart upload from PWA                     |
| DELETE | `/api/route-visit-photos/{id}`                        | Delete a photo                                |

The `/photos/upload` (multipart) and `/photos` (record-already-uploaded)
split lets the offline sync queue work both ways: the PWA can either
post the file directly, or upload via a separate path and then record
the metadata once the upload completes.

---

## 8. Operator runbook

### Setting up a new route

1. Create the `service_route` (cadence + horizon + per-route photo
   minimums).
2. Add `route_stops` in the order techs should visit them. Most stops
   are site-level; pin to a `site_asset` only when the work is on a
   specific machine.
3. POST `/api/service-routes/{id}/generate` to materialize the first
   wave of visits without waiting for cron.
4. Print QR stickers using `qr_token` from the generated visits — or
   print site-level QR stickers that resolve to the *next* visit at
   that site (UI choice; the scan endpoint accepts either flow).

### Daily ops

- Dispatcher views `/api/route-visits?scheduled_from=…&scheduled_to=…`
  to see today's planned vs in-flight visits across all routes.
- Tech mobile flow: scan QR → arrived → walk + photograph → complete.
  Photo verification fires inline at completion; failures show a
  yellow banner ("verification flagged: dup of visit #1234") but the
  visit is still completed.
- Back office walks the daily failed-verification feed
  (`/api/route-visits?status=completed&verification_passed=0`) and
  decides per case: re-shoot, escalate to manager, or override the
  flag.

### When the cron is unhealthy

- Symptom: dispatcher board shows visits in `planned` state past their
  window. Either the generator or the sweeper is stalled.
- Check `last_generated_through` on active routes — if it's far behind
  `now`, the generator hasn't run.
- Manual recovery: `POST /api/service-routes/{id}/generate` per route
  to catch up; check the cron runner logs for the root cause.
- The sweep is idempotent and reversible: a visit incorrectly marked
  missed can be transitioned back (the state machine doesn't allow
  `missed → planned` directly, so the workflow is "delete missed
  visit → re-generate" or accept the miss and let the next slot
  cover the work).

### Tuning verification thresholds

If a customer reports false positives ("the heuristic flagged my tech
who really was on site"), tighten upstream first:

1. **time_window_minutes**: bump from 60 → 120 if their team uploads
   in batches end-of-day.
2. **geo_radius_meters**: bump from 250m → 500m for sprawling
   industrial sites where the GPS-tagged building entrance is far
   from the cleaning crew's actual work spot.
3. **dup_max_hamming_distance**: lower from 6 → 4 if the customer's
   sites genuinely look identical week-to-week and the dedupe is
   firing on legitimate work.

Per-instance overrides go to `PhotoVerifier::__construct($config)`;
there's no per-route persistence yet (see §10 known gaps).

---

## 9. Frontend integration

Two distinct surfaces.

### Staff app (`src/react/views/service-routes/`)

- **Route list / detail** — recurrence editor, stop reorder (drag),
  per-route verification toggle.
- **Visit calendar / list** — filterable; status badges color-coded;
  visit detail drawer shows the timeline (planned → en_route →
  arrived → completed) and the photos with their verification
  scores.
- **Compliance dashboard** — failed-verification feed, missed-visit
  feed, "stops never visited in the last N days" feed.

### Field PWA (`src/react/views/field/`)

- **Today's route** — chronological list of `planned` / `en_route`
  visits assigned to the current user.
- **Scan QR** — opens the camera, calls
  `/api/route-visits/scan/{token}` on a successful read; the
  endpoint returns the post-transition visit so the next screen
  renders immediately with the right state.
- **Photo capture** — uses the PWA's existing camera+upload pipeline,
  posts to `/photos/upload`. Verification flags surface inline as
  yellow banners on the visit detail.
- **Skip with reason** — a modal that captures the reason string
  before posting the planned→skipped transition.

The offline queue handles transitions and photo uploads symmetrically:
both go into the queue if offline, both replay on reconnect with
last-write-wins semantics. The QR token on the visit row is what
makes the offline replay safe — a stale scanned token still resolves
correctly even if the server-side visit has moved on.

---

## 10. Known gaps and follow-ups

- **Verification thresholds are global per-deploy.** Per-route
  overrides require a config column on `service_routes` and a wiring
  pass through `PhotoVerifier`. Hold until a customer asks; one set
  of thresholds has been adequate so far.
- **No "make-up visit" workflow.** A missed visit is just terminal;
  if the customer's contract requires a make-up, the operator
  manually creates an extra visit by hand. Consider a
  `/api/route-visits/{id}/reschedule` action that closes the missed
  one and emits a one-shot replacement.
- **Stop-level checklists are referenced (`checklist_template_id`)
  but not yet rendered in the PWA.** The data path is wired; the UI
  binding ships in a future minor.
- **The auto-WO created per visit is currently always
  service-line-derived.** Multi-trade routes (e.g. cleaning + minor
  pest control as one stop) need richer WO templating. See Phase 17
  for the multi-trade direction.
- **`recurrence_type='custom'` is a placeholder.** It's accepted by
  the schema but the generator treats custom-type routes as inactive.
  The intended extension is a per-route plugin hook for irregular
  cadences (e.g. "every other Tuesday except holidays"); design TBD.

---

## 11. Engineering decisions worth keeping

### 11.1 Why the generator is cron-driven, not on-write

We considered "regenerate visits when the route or its stops change"
as a write-time hook. Rejected: it makes route updates O(stops × slots)
slow, and it's hard to reason about partial failures (some visits
emitted, some not). The cron approach trades up-to-the-minute
materialization for a clean failure model — the worst case is a
5-minute delay, which is fine because the visits in question are
hours or days in the future.

### 11.2 Why photos are advisory, not blocking

A cleaning crew at 6am with cold hands and a dying phone battery is
not the right audience for "the heuristic doesn't like your photo".
Blocking completion would push them to game the system (turn off
location, retake until it passes) — making the heuristic useless.
Advisory + flag-for-back-office preserves the heuristic's signal
without making the field worker fight it.

### 11.3 Why `service_route_id` is duplicated on `route_visits`

Stops never move between routes (you delete and recreate). The
denormalization is safe in that sense, and saves a join on every
dispatcher-board query. If we ever do allow stop migration between
routes, the migration would have to update `service_route_id` on
matching visits at the same time — tracked as a known-fragility
that earns its keep until proven otherwise.

### 11.4 Why visit photos are a separate table from WO photos

Two different audiences, two different access patterns: visit photos
are queried by `route_visit_id` for the compliance feed; WO photos
are queried by `workorder_id` for the invoice. Conflating them would
have meant either (a) joining WO photos by upload time to a visit
window — fragile and slow — or (b) tagging WO photos with a
`route_visit_id` column, which then has to be backfilled across
every customer's history. Cleaner to just have two tables.

### 11.5 Why the QR scan endpoint transitions the visit

We considered returning the visit unchanged and letting the PWA POST
the transition explicitly. Rejected because (a) it doubles the
request count from the device on a slow connection, and (b) the scan
IS the gesture — a tech who scanned and then lost the network would
have an unscanned visit on the dispatcher board. Coupling the
transition to the scan keeps the dispatcher board honest with what
the field actually saw.

---

## 12. Files of record

### Migrations
- `database/migrations/165_recurring_service_routes.sql`

### Models
- `src/Models/ServiceRoute.php`
- `src/Models/RouteStop.php`
- `src/Models/RouteVisit.php` (STATUS_*, TRANSITIONS)
- `src/Models/RouteVisitPhoto.php`

### Services
- `src/Services/ServiceRoutes/ServiceRouteController.php`
- `src/Services/ServiceRoutes/ServiceRouteRepository.php`
- `src/Services/ServiceRoutes/RouteStopRepository.php`
- `src/Services/ServiceRoutes/RouteVisitService.php`
- `src/Services/ServiceRoutes/RouteVisitRepository.php`
- `src/Services/ServiceRoutes/RouteVisitPhotoRepository.php`
- `src/Services/ServiceRoutes/RouteVisitGenerator.php`
- `src/Services/ServiceRoutes/PhotoVerifier.php`

### Routes
- `routes/modules/service_routes.php`

### Cron
- `bin/cron/route-visit-generator.php` (every 5 min)

### Frontend (representative)
- `src/react/views/service-routes/RouteList.jsx`
- `src/react/views/service-routes/RouteDetail.jsx`
- `src/react/views/service-routes/VisitCalendar.jsx`
- `src/react/views/service-routes/VerificationFeed.jsx`
- `src/react/views/field/TodayRoute.jsx`
- `src/react/views/field/QrScan.jsx`
- `src/services/service-routes.service.js`

### Permissions
- `config/auth.php` — `service_routes.{view,manage,execute}`
