# Phase 16 — Security & POS Verticals

> Plan reference: `docs/woms-expansion-plan.md` Phase 16, items **S1**
> (security credential register + programming log + scheduled access
> policies) and **S2** (POS heartbeat ingestion + stale-heartbeat ticket
> worker).
>
> Phase status: **shipped**. Migration 166 is deployed; the credential
> register and POS heartbeat receiver are live; the per-minute stale
> sweeper cron is wired into the unified runner.

Phase 16 brings two vertical-specific feature sets onto the platform:
**physical security** (issue / track / revoke door credentials with
schedules and a bulletproof audit ledger) and **POS device monitoring**
(devices push heartbeats over a signed webhook, a sweeper opens tickets
when they go silent). They ship together because they share two
load-bearing primitives — the polymorphic `programming_logs` audit
table and the convention that "device-as-asset" lives in the existing
CMDB (`site_assets`) rather than spawning new entity tables.

---

## 1. What shipped

### S1 — Security credential register (migration 166, parts A–D)

Three security tables plus the shared audit ledger:

| Table                   | Role                                                                |
|-------------------------|---------------------------------------------------------------------|
| `access_schedules`      | Recurring access policy: days-of-week + start/end + timezone.       |
| `credential_registers`  | Issued credentials (card / fob / PIN / mobile / biometric / plate). |
| `credential_doors`      | M:N credential ↔ door grant, optional per-grant access schedule.    |
| `programming_logs`      | Polymorphic config-change audit; shared with POS (see §2.4).        |

Doors are not a new entity — they're `site_assets` with
`asset_type.code = 'access_door'`. The convention keeps door management
inside the existing CMDB instead of forking a parallel "doors" table
and parallel CMDB views.

### S2 — POS terminal monitoring (migration 166, parts E–F + cron)

Two POS tables and a cron:

| Table              | Role                                                              |
|--------------------|-------------------------------------------------------------------|
| `pos_terminals`    | Device registry; owns the HMAC `shared_secret` + staleness config. |
| `pos_heartbeats`   | Append-only time-series of received heartbeats.                   |

| Component                            | Role                                                  |
|--------------------------------------|-------------------------------------------------------|
| `PosHeartbeatIngestionService`       | HMAC-verifies inbound webhook, writes heartbeat, auto-clears prior alert ticket on recovery. |
| `bin/cron/pos-stale-sweeper.php`     | Per-minute sweep: opens a ticket per stale terminal, stamps `last_alert_ticket_id` so it doesn't double-fire. |

Heartbeat receipt is **public** (no JWT) — the HMAC over the raw body
against the per-terminal `shared_secret` is the entire credential.
Everything else (terminal management, heartbeat history) is JWT-gated
under `pos_terminals.{view,manage}`.

---

## 2. Data model

### 2.1 access_schedules

```sql
CREATE TABLE access_schedules (
    id BIGINT UNSIGNED PK,
    customer_id INT UNSIGNED NOT NULL,
    name, description,
    days_of_week  VARCHAR(40) NOT NULL,   -- 'mon,tue,wed,...'
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    timezone      VARCHAR(64) NULL,
    is_active     TINYINT(1) DEFAULT 1
);
```

Two non-obvious decisions:

- `days_of_week` is a comma-separated string of `mon|tue|wed|thu|fri|sat|sun`,
  validated in PHP. We chose this over a cross-table `schedule_days`
  child table because (a) the value is small and bounded, (b) it's
  always read in full (you don't query "schedules that include
  Wednesday"), and (c) the join was pure overhead.
- **Cross-midnight schedules are modeled as two rows.** A "21:00 to
  06:00" pass becomes `{21:00–23:59}` + `{00:00–06:00}`, both pointing
  at the same logical policy via the same `name`. Within-day
  comparisons stay trivial — no special-cased "if end < start" code
  paths in the query layer.

### 2.2 credential_registers

```sql
CREATE TABLE credential_registers (
    id BIGINT UNSIGNED PK,
    customer_id INT UNSIGNED NOT NULL,
    site_id     INT UNSIGNED NULL,
    holder_name, holder_email, holder_phone, holder_employee_id,
    credential_type     VARCHAR(40) NOT NULL,   -- card|fob|pin|mobile|biometric|plate
    credential_code     VARCHAR(160) NOT NULL,  -- on-the-wire id (PIN: salted hash)
    credential_format   VARCHAR(40) NULL,       -- wiegand-26|hid-prox|mifare|...
    status              VARCHAR(20) DEFAULT 'active', -- active|suspended|revoked|lost
    issued_at, expires_at, suspended_at, revoked_at, revoke_reason,
    notes,
    created_by_user_id, updated_by_user_id,
    UNIQUE (customer_id, credential_type, credential_code)
);
```

Critical constraint: the UNIQUE on
`(customer_id, credential_type, credential_code)` blocks duplicate
enrollment of the same physical credential. The service rejects with
422 instead of swallowing the conflict.

PINs are stored as a **salted hash** — never plaintext. The
`CredentialRegisterService` does the hash; bypassing the service is a
bug. There is no "view PIN" endpoint; if a holder forgets, you issue
a new credential.

### 2.3 credential_doors (m:n grant)

```sql
CREATE TABLE credential_doors (
    id BIGINT UNSIGNED PK,
    credential_id      BIGINT UNSIGNED NOT NULL,    -- → credential_registers (CASCADE)
    site_asset_id      INT UNSIGNED NOT NULL,       -- → site_assets (CASCADE)
    access_schedule_id BIGINT UNSIGNED NULL,        -- → access_schedules (SET NULL)
                                                    -- NULL = 24/7
    granted_at, granted_by_user_id,
    revoked_at, revoked_by_user_id, revoke_reason,
    notes,
    UNIQUE (credential_id, site_asset_id)
);
```

Three rules:

1. **Revoke flips `revoked_at`; we do not DELETE.** The audit ledger
   and per-credential history view depend on the row staying around.
   The UNIQUE means re-granting the same credential at the same door
   updates the existing row (same id) rather than spawning a duplicate.
2. **`access_schedule_id` is per-grant, not per-credential.** The same
   credential can be granted at the front door 24/7 and at the data
   center "weekdays 9–5" — each grant carries its own schedule pointer.
3. **`access_schedule_id` is SET NULL on schedule delete.** Deleting a
   schedule reverts affected grants to 24/7 access (the safer default
   for an empty pointer is "no access", but that's a footgun for ops
   who delete a schedule by mistake — see §10).

### 2.4 programming_logs (polymorphic audit, shared with POS)

```sql
CREATE TABLE programming_logs (
    id BIGINT UNSIGNED PK,
    customer_id INT UNSIGNED NOT NULL,
    site_id     INT UNSIGNED NULL,
    target_type VARCHAR(40) NOT NULL,   -- credential|credential_door|access_schedule
                                        -- |pos_terminal|door
    target_id   BIGINT UNSIGNED NOT NULL,
    action      VARCHAR(40) NOT NULL,   -- created|updated|deleted|assigned|revoked
                                        -- |enabled|disabled|config_changed
                                        -- |webhook_received|sweep_alert
    summary     VARCHAR(255) NULL,
    before_snapshot JSON NULL,
    after_snapshot  JSON NULL,
    programmed_at, programmed_by_user_id, programmed_by_external,
    ip_address,
    INDEX (target_type, target_id, programmed_at),
    INDEX (customer_id, programmed_at),
    INDEX (action),
    INDEX (programmed_by_user_id)
);
```

This is the load-bearing table for both verticals. Why one table:

- **Single audit pane** — "show me the last 30 days of changes against
  customer X's security and POS configs" is one indexed query, not a
  UNION across four per-target audit tables.
- **Identical shape across targets** — every config change has a
  who/what/when + before/after JSON. Forcing per-target tables would
  duplicate columns and indexes.
- **Composite index `(target_type, target_id, programmed_at)`** keeps
  the per-target lookup ("who touched this credential?") fast.

`before_snapshot` is NULL on creation; `after_snapshot` is NULL on
deletion. Both NULL on a noisy `webhook_received` action means
"nothing meaningful changed, this is just a receipt log."

### 2.5 pos_terminals

```sql
CREATE TABLE pos_terminals (
    id BIGINT UNSIGNED PK,
    customer_id INT UNSIGNED NOT NULL,
    site_id     INT UNSIGNED NOT NULL,
    site_asset_id INT UNSIGNED NULL,            -- optional CMDB link
    terminal_code VARCHAR(80) NOT NULL,         -- public id in webhook URL
    name, vendor, model, serial_number,
    shared_secret CHAR(64) NOT NULL,            -- HMAC-SHA256 hex (256-bit key)
    heartbeat_interval_seconds INT  DEFAULT 60, -- expected cadence (metadata)
    stale_after_seconds        INT  DEFAULT 300,-- alerting threshold
    status VARCHAR(20) DEFAULT 'active',
    last_seen_at  DATETIME NULL,                -- denormalized
    last_status   VARCHAR(20) NULL,             -- denormalized
    last_alert_ticket_id INT UNSIGNED NULL,     -- → tickets (SET NULL)
    UNIQUE (customer_id, terminal_code)
);
```

Three load-bearing details:

- **`terminal_code` is public** (it's in the webhook URL). The internal
  `id` is never exposed. Rotating the code is a deliberate act
  (re-program the device) — done via standard PUT.
- **`shared_secret` is the only credential.** 64 hex chars = 256 bits.
  Rotated via `POST /api/pos/terminals/{id}/rotate-secret`; the old
  secret is invalidated immediately (no grace window — the device gets
  re-keyed in the same operator action).
- **`last_alert_ticket_id` is the de-dupe key for the sweeper.** When
  set, the sweeper skips this terminal. Cleared by the ingestion
  service when a fresh heartbeat lands (with a 'recovered' note on
  the prior ticket — see §4).

### 2.6 pos_heartbeats (append-only)

```sql
CREATE TABLE pos_heartbeats (
    id BIGINT UNSIGNED PK,
    terminal_id BIGINT UNSIGNED NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reported_at DATETIME NULL,                  -- device clock (untrusted)
    status      VARCHAR(20) DEFAULT 'online',   -- online|offline|degraded|error
    payload     JSON NULL,                      -- full webhook body
    ip_address  VARCHAR(45) NULL,               -- source IP (cross-check)
    INDEX (terminal_id, received_at),
    INDEX (received_at),
    INDEX (terminal_id, status)
);
```

Append-only — no UPDATE, no DELETE. Why we don't do upsert-latest-only:
customers ask "did the terminal flap last week?" and that question
needs the time-series. The `last_seen_at`/`last_status` denorm on
`pos_terminals` keeps the hot path (sweeper + dashboard "is it up?")
cheap; the time-series is for forensics and reports. If a customer
pushes thousands of terminals, partition `pos_heartbeats` by month —
the schema's ready for it.

---

## 3. The two load-bearing decisions

### 3.1 Doors are `site_assets`, POS terminals are not

Both physical doors AND POS terminals are "installed equipment at a
customer site." Yet doors live in `site_assets` and POS terminals get
their own table. The split reflects what's specific to the device:

| Feature                         | Door                | POS terminal                         |
|---------------------------------|---------------------|--------------------------------------|
| Has install date, vendor, model | Yes (CMDB columns)  | Yes (CMDB columns)                   |
| Has CMDB warranty, location     | Yes                 | Yes                                  |
| Has HMAC `shared_secret`        | No                  | **Yes — POS-specific**               |
| Has staleness config            | No                  | **Yes — POS-specific**               |
| Has `last_seen_at`/`last_status`| No                  | **Yes — POS-specific hot-path denorm** |
| Has time-series of receipts     | No                  | **Yes — append-only `pos_heartbeats`** |

Inlining `shared_secret` + the heartbeat denorm onto every
`site_asset` row would clutter the CMDB for every customer who's never
seen a POS device. The POS-specific columns belong in their own table,
with an optional FK back to `site_assets` for the CMDB view.

The convention going forward: if a piece of equipment has device-class
behavior the CMDB doesn't model (secrets, cadenced telemetry,
autonomous state), give it its own table with a SET NULL pointer back
to `site_assets`. If it's just static metadata, leave it in
`site_assets`.

### 3.2 The sweeper / ingestion handshake

The de-duplication pattern is worth understanding because it's the
reason the sweeper doesn't spam tickets and the operator doesn't have
to manually close stale tickets when a terminal recovers.

```
Sweeper tick (every minute):
  for each terminal where last_seen_at < now - stale_after_seconds:
    if last_alert_ticket_id IS NOT NULL:
      skip   ──── (already alerted; waiting for recovery)
    else:
      open ticket  → set last_alert_ticket_id

Heartbeat ingestion (per webhook):
  HMAC-verify against shared_secret.
  Insert pos_heartbeats row.
  Update last_seen_at, last_status.
  if last_alert_ticket_id IS NOT NULL:
    append 'terminal recovered at <timestamp>' note to ticket
    clear last_alert_ticket_id
```

Two consequences:

- **Operators never see "stale terminal" ticket spam.** Once one
  ticket is open, no new ones fire until ingestion clears the
  pointer.
- **Recovery is automatic.** When the terminal comes back, the
  ticket gets a recovery note and the dedup pointer clears. The
  ticket itself is left open for the operator to formally close
  with a root-cause note (we don't auto-resolve because "came back"
  doesn't imply "fixed for good").

The ticket-close action does NOT clear `last_alert_ticket_id` — only
ingestion does. If an operator closes the ticket while the terminal
is still silent, the next sweep will open a fresh one. This is
intentional: closing a ticket on a still-broken terminal is operator
error; the system shouldn't disguise it.

---

## 4. POS heartbeat protocol

### 4.1 Webhook contract

```
POST /api/pos/heartbeats/{terminal_code}
Content-Type: application/json
X-PHParm-Signature: <hex hmac-sha256 of raw body, key=shared_secret>

{
  "status": "online" | "offline" | "degraded" | "error",
  "reported_at": "2026-05-04T08:00:00Z",     // optional; device clock
  "...vendor-specific fields...": "..."       // stashed in payload JSON
}
```

The header name is `X-PHParm-Signature` (also accepted as
`X-PHPArm-Signature` for case-tolerant gateways). Signature is hex,
not base64.

### 4.2 Result codes → HTTP

`PosHeartbeatIngestionService::receive()` returns a result discriminator
the route layer maps to HTTP:

| Result                       | HTTP | Body                                     |
|------------------------------|------|------------------------------------------|
| `RESULT_OK`                  | 200  | `{ success: true, data: { heartbeat_id, received_at } }` |
| `RESULT_BAD_SIGNATURE`       | 401  | message: "invalid signature"             |
| `RESULT_UNKNOWN_TERMINAL`    | 404  | message: "unknown terminal_code"         |
| `RESULT_DISABLED_TERMINAL`   | 403  | message includes the terminal status     |
| `RESULT_BAD_PAYLOAD`         | 400  | message: "payload is not valid JSON"     |

A 401 from a *correct* secret almost always means the device or proxy
mangled the body (gzipped vs raw, charset transformation, etc.). The
server signs against the **raw** body bytes. If you put a body-rewriting
middleware in front of this endpoint, signing breaks.

### 4.3 Why the public webhook is HMAC-only, not JWT

A POS terminal isn't a user — it has no session, no refresh, no
device-side cookie storage worth trusting. Bearer tokens would have to
be embedded in firmware and rotated by physical re-program; HMAC over
a per-terminal shared secret achieves the same security model with no
session state and clean rotation via the admin endpoint.

The webhook IS in the public URL space (no `/auth` prefix) because
JWT middleware would reject unsigned requests before our HMAC check
ran.

---

## 5. Credential lifecycle

`CredentialRegister::ALLOWED_STATUSES = ['active', 'suspended', 'revoked', 'lost']`.

There's no formal state machine in the model — `CredentialRegisterService`
applies rules at the service layer:

- `active → suspended` — temporary disable; door grants are ignored at
  query time but kept on the row. Re-enable with `suspended → active`.
- `* → revoked` — terminal. Records `revoke_reason`. Door grants are
  preserved for audit but ignored.
- `* → lost` — terminal, semantic synonym for revoked but distinguishes
  "physically misplaced" from "deliberately decommissioned" in
  reports.

Status changes go through `POST /api/security/credentials/{id}/status`,
which emits a `programming_logs` entry with `action='disabled'` /
`'enabled'` / `'revoked'` and the prior + new status snapshots.

Door grants on a non-active credential are filtered out at access-check
query time — we do not delete them. This preserves the
"who had access when" historical view a security auditor would ask
for.

---

## 6. API surface

All endpoints under `Middleware::auth()` **except** the public
heartbeat receiver.

### 6.1 Security credentials (`routes/modules/security_credentials.php`)

Read perm: `security_credentials.view`. Write perm: `security_credentials.manage`.

| Method | Path                                                    | Purpose                                  |
|--------|---------------------------------------------------------|------------------------------------------|
| GET    | `/api/security/credentials`                             | List (filters: status, type, search, expires_before) |
| GET    | `/api/security/credentials/{id}`                        | Show                                     |
| POST   | `/api/security/credentials`                             | Issue credential                         |
| PUT    | `/api/security/credentials/{id}`                        | Update fields (not status)               |
| POST   | `/api/security/credentials/{id}/status`                 | Suspend / revoke / reactivate / mark lost |
| DELETE | `/api/security/credentials/{id}`                        | Delete (rare — prefer revoke)            |
| GET    | `/api/security/credentials/{id}/doors`                  | List grants                              |
| POST   | `/api/security/credentials/{id}/doors`                  | Grant door                               |
| PUT    | `/api/security/credential-doors/{id}`                   | Update grant (e.g. change schedule)      |
| POST   | `/api/security/credential-doors/{id}/revoke`            | Revoke grant (keeps row, sets revoked_at)|
| GET    | `/api/security/access-schedules`                        | List schedules                           |
| GET    | `/api/security/access-schedules/{id}`                   | Show schedule                            |
| POST   | `/api/security/access-schedules`                        | Create schedule                          |
| PUT    | `/api/security/access-schedules/{id}`                   | Update schedule                          |
| DELETE | `/api/security/access-schedules/{id}`                   | Delete (grants pointing at it go to NULL = 24/7) |
| GET    | `/api/security/programming-logs`                        | Customer-wide audit feed (filters: target_type/id, action, date range) |

### 6.2 POS terminals (`routes/modules/pos_terminals.php`)

Read perm: `pos_terminals.view`. Write perm: `pos_terminals.manage`.

| Method | Path                                            | Auth   | Purpose                                  |
|--------|-------------------------------------------------|--------|------------------------------------------|
| POST   | `/api/pos/heartbeats/{terminal_code}`           | HMAC   | **PUBLIC** webhook receiver              |
| GET    | `/api/pos/terminals`                            | JWT    | List terminals                           |
| GET    | `/api/pos/terminals/{id}`                       | JWT    | Show + denorm last_seen_at / last_status |
| POST   | `/api/pos/terminals`                            | JWT    | Register terminal (returns shared_secret once) |
| PUT    | `/api/pos/terminals/{id}`                       | JWT    | Update fields (NOT secret)               |
| POST   | `/api/pos/terminals/{id}/rotate-secret`         | JWT    | Generate new secret (returned once)      |
| DELETE | `/api/pos/terminals/{id}`                       | JWT    | Delete (CASCADEs heartbeats)             |
| GET    | `/api/pos/terminals/{id}/heartbeats`            | JWT    | Paged heartbeat history                  |

Notes:

- POST and rotate-secret return the **plaintext** `shared_secret`
  exactly once. The UI displays it with copy + warning ("store this
  before closing the dialog"). Subsequent reads return only that the
  secret is set.
- Heartbeat history is read via the terminal endpoint (paged); there's
  no top-level `/api/pos/heartbeats` listing because everyone querying
  heartbeats wants them per-terminal.

---

## 7. Permissions

Defined in `config/auth.php`:

| Permission                       | Grants                                          |
|----------------------------------|-------------------------------------------------|
| `security_credentials.view`      | Read credentials, doors, schedules, audit logs. |
| `security_credentials.manage`    | All security writes + status changes.           |
| `pos_terminals.view`             | Read terminals + heartbeat history.             |
| `pos_terminals.manage`           | Register/rotate-secret/delete terminals.        |

Two notes:

1. **No separate "security audit" perm.** `security_credentials.view`
   is enough — the audit feed is a customer-scoped read filtered by
   the same gate. We didn't want to gatekeep "look at history" behind
   a perm that operators don't naturally have.
2. **The public webhook has no perm** — HMAC is the gate. Don't
   accidentally re-add `Middleware::auth()` to its route group.

---

## 8. Operator runbook

### Daily

- **Security:** walk the programming-log feed
  (`/api/security/programming-logs?programmed_from=…`) for unexpected
  changes. Anything tagged `programmed_by_external='cron:pos-sweeper'`
  in this feed (yes, the same table) is a POS event, not security.
- **POS:** dashboard sorted by `last_seen_at ASC` surfaces silent
  terminals before they go stale. Cross-reference any with open
  alert tickets — those are the ones the sweeper has paged.

### When a POS terminal goes silent

1. Sweeper opens `tickets` row with `source='pos-sweeper'`,
   `severity='sev2'`, `priority='p2_high'`. Title: `POS terminal
   "<code>" stopped reporting`.
2. Investigate: device power, network, firewall to the webhook URL.
3. **Do not close the ticket** until you've either fixed the device
   or formally retired it. If the device recovers on its own, the
   ingestion service appends a 'recovered' note to the ticket and
   clears the de-dupe pointer; you can then close with a root-cause
   note.

### Rotating a POS shared secret

1. `POST /api/pos/terminals/{id}/rotate-secret`.
2. Copy the new secret from the response (shown exactly once).
3. Re-program the device with the new secret.
4. Wait for the next successful heartbeat — the audit log will show
   `webhook_received` against the terminal at `received_at >`
   the rotate timestamp.

If you lose the new secret before re-programming, rotate again. The
old secret is dead the instant rotate completes — there's no grace
window. This is intentional (a leaked secret is a leaked secret).

### Issuing & revoking a credential

1. `POST /api/security/credentials` with holder details + type +
   on-the-wire code (or PIN, which the service hashes). UNIQUE
   constraint catches re-enrollment attempts as 422.
2. Grant doors via `POST /api/security/credentials/{id}/doors` per
   door, optionally pointing at an `access_schedule_id`.
3. Lost credential: `POST /api/security/credentials/{id}/status`
   with `{ status: 'lost', revoke_reason: '...' }`. All door grants
   become inert at the next access query; the rows stay for audit.

### Deleting an access schedule

A schedule deletion sets `credential_doors.access_schedule_id = NULL`
on every grant pointing at it — meaning those grants revert to **24/7
access**. This is a footgun. Before deleting a schedule:

1. `GET /api/security/credentials?schedule_id=…` (search filter)
   to see who's affected.
2. Either reassign grants to a replacement schedule first, or
   accept the 24/7 fallback.

Yes, ON DELETE RESTRICT would have been safer. We chose SET NULL so
operators don't get stuck with a "can't delete this schedule" error
when retiring an old policy. A future minor may add a flag to
`access_schedules` ("blocking_delete: true") so high-stakes schedules
get the RESTRICT behavior opt-in.

---

## 9. Frontend integration

### Security (`src/react/views/security/`)

- **Credentials list** — filter by status, type, holder. Bulk
  suspend/revoke for offboarding flows.
- **Credential detail** — holder card, doors granted (with per-grant
  schedule), audit timeline filtered to this credential.
- **Doors view** — drives off `site_assets` filtered by
  `asset_type.code='access_door'`; clicks through to "credentials
  with access here."
- **Schedules** — week-grid editor; cross-midnight schedules show as
  two stacked rows.
- **Audit feed** — full programming-log timeline; filters mirror the
  `/api/security/programming-logs` query params.

### POS (`src/react/views/pos/`)

- **Terminal list** — sortable by last_seen_at, status badge with
  "stale for Xm" countdown.
- **Terminal detail** — device card + heartbeat sparkline + paged
  heartbeat table + "rotate secret" action.
- **Issue secret modal** — single-display dialog; "Copy" + "Done"
  buttons; closing without copy is allowed but warned.
- **Stale-alert ticket badges** — terminal cards show their open
  alert ticket inline so the operator can click straight through.

---

## 10. Known gaps and follow-ups

- **No access-event ingestion.** We track *who's allowed* but not
  *who actually scanned*. Most reader vendors will eventually push
  events; a future S* would add `access_events` (similar to
  `pos_heartbeats` — append-only time-series with a per-credential
  index).
- **`access_schedule_id` SET NULL is a footgun** (see runbook §8).
  Add an opt-in "blocking delete" flag.
- **PIN reset workflow.** Currently a hashed-PIN credential is
  revoke-and-reissue only. A "reset PIN" endpoint that updates the
  hash without breaking the door grants would be operator-friendly.
- **Heartbeat partitioning.** `pos_heartbeats` will grow; partition
  by month when a customer pushes >100 terminals or when retention
  needs to bound storage.
- **Heartbeat replay attack window.** HMAC-only signatures don't
  prevent a captured heartbeat from being replayed within the
  staleness window (which would falsely clear an alert ticket).
  Consider adding a `nonce` or `received_at` upper-bound check; not
  done yet because real devices haven't shown the threat in
  practice.
- **POS sweeper is single-process.** Two instances of the cron
  running simultaneously could double-fire alerts (the
  `last_alert_ticket_id` UPDATE isn't atomic with the ticket
  create). A row lock would fix it; not needed under the unified
  cron runner which serializes ticks.

---

## 11. Engineering decisions worth keeping

### 11.1 One `programming_logs` table for two verticals

Reviewers asked why we share an audit table across security and POS.
The answer is that a shared shape (`target_type` + `target_id` +
before/after JSON) lets one query power "show me all changes for
customer X" — the audit pane is one of the highest-value features for
both verticals, and per-target tables would have meant unioning four
or five queries. The composite index
`(target_type, target_id, programmed_at)` keeps per-target lookups
fast.

### 11.2 Why heartbeats are append-only

We considered `last_heartbeat` columns on `pos_terminals` as the only
storage. Rejected: the time-series answers questions
(`flap detection`, `uptime %`, `last hour rate`) the dashboard +
forensics teams ask routinely. The denormalized
`last_seen_at`/`last_status` on `pos_terminals` covers the hot path;
`pos_heartbeats` covers the cold-but-important path.

### 11.3 The sweeper / ingestion de-dup handshake

Every alert system eventually answers the question "how do we
auto-resolve and avoid spam?" Most production alert systems get this
wrong by either (a) auto-resolving too aggressively (and hiding still-
broken systems), or (b) spamming a fresh ticket every minute the
component is down. Our handshake — sweeper sets the pointer, ingestion
clears it — picks the operator-friendly middle: one ticket per
incident, recovery surfaced as a note rather than a close.

### 11.4 Why ticket close doesn't clear `last_alert_ticket_id`

If an operator closes a ticket while the device is still silent, the
next sweep will open a new one. We didn't want close-button behavior
to suppress real ongoing alerts — that's a footgun in incident
management ("looks like nothing's wrong because nobody's reporting
it"). The safer default: clearing the dedup pointer is a recovery
signal, not a close signal.

### 11.5 Why doors live in `site_assets`, not their own table

A door is just installed equipment — it has install date, vendor,
location, parent panel — all of which the CMDB already models. The
only door-specific question we ask is "is this site_asset a door?"
which a `asset_type.code='access_door'` check answers without a
schema change. A separate `doors` table would have meant operators
maintain TWO inventories of physical-plant equipment, and the CMDB
view would need a UNION to show both. The convention we're keeping:
generic CMDB equipment goes in `site_assets`; device-class equipment
with secrets / cadence / autonomous state gets its own table with a
SET NULL pointer back.

---

## 12. Files of record

### Migrations
- `database/migrations/166_security_pos_verticals.sql`

### Models
- `src/Models/CredentialRegister.php` (TYPE_*, STATUS_*, ALLOWED_TYPES, ALLOWED_STATUSES)
- `src/Models/CredentialDoor.php`
- `src/Models/AccessSchedule.php`
- `src/Models/ProgrammingLog.php` (TARGET_*, ACTION_*)
- `src/Models/PosTerminal.php`
- `src/Models/PosHeartbeat.php`

### Services
- `src/Services/Security/CredentialRegisterService.php` (PIN hashing, status transitions, audit emit)
- `src/Services/Security/CredentialRegisterController.php`
- `src/Services/Security/CredentialRegisterRepository.php`
- `src/Services/Security/CredentialDoorRepository.php`
- `src/Services/Security/AccessScheduleRepository.php`
- `src/Services/Security/ProgrammingLogRepository.php` (shared with POS)
- `src/Services/POS/PosTerminalService.php` (registration, rotate-secret, audit)
- `src/Services/POS/PosTerminalController.php`
- `src/Services/POS/PosTerminalRepository.php` (`listStale`, `setAlertTicket`, `clearAlertTicket`)
- `src/Services/POS/PosHeartbeatIngestionService.php` (HMAC verify, ingestion, alert auto-clear)
- `src/Services/POS/PosHeartbeatRepository.php`

### Routes
- `routes/modules/security_credentials.php`
- `routes/modules/pos_terminals.php`

### Cron
- `bin/cron/pos-stale-sweeper.php` (every minute)

### Frontend (representative)
- `src/react/views/security/CredentialList.jsx`
- `src/react/views/security/CredentialDetail.jsx`
- `src/react/views/security/AccessSchedules.jsx`
- `src/react/views/security/ProgrammingLog.jsx`
- `src/react/views/pos/TerminalList.jsx`
- `src/react/views/pos/TerminalDetail.jsx`
- `src/services/security-credentials.service.js`
- `src/services/pos-terminals.service.js`

### Permissions
- `config/auth.php` — `security_credentials.{view,manage}`, `pos_terminals.{view,manage}`
