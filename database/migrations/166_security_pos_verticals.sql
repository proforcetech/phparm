-- =============================================================================
-- Phase 16 of docs/woms-expansion-plan.md: Security + POS verticals.
-- Should-have S1 (security credential register + programming log + scheduled
-- access policies) and S2 (POS heartbeat ingestion + stale-heartbeat ticket
-- worker).
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- S1 — Security:
--   1. `credential_registers`   — the credentials themselves (card, fob, PIN,
--                                  mobile token, biometric id). One row per
--                                  issued credential, scoped to a customer.
--   2. `credential_doors`       — many-to-many credential ↔ door (door is a
--                                  site_asset). Optional access_schedule_id
--                                  per assignment so the same credential can
--                                  follow different schedules at different doors.
--   3. `access_schedules`       — recurring time windows (days-of-week + start/
--                                  end time) that can be referenced from
--                                  credential_doors.
--
-- S1 + S2 — Shared:
--   4. `programming_logs`       — append-only ledger of every config change
--                                  against a credential, door, schedule, or
--                                  POS terminal. Carries before/after JSON
--                                  snapshots so an auditor can reconstruct the
--                                  history without replaying app logs.
--
-- S2 — POS:
--   5. `pos_terminals`          — registry of POS devices. Owns the HMAC
--                                  shared_secret used to sign heartbeat
--                                  webhooks plus the per-terminal staleness
--                                  thresholds and last_seen_at denorm.
--   6. `pos_heartbeats`         — time-series of received heartbeats. Indexed
--                                  for "latest per terminal" + time-range
--                                  queries by the staleness sweeper.
--
-- WHY DOORS LIVE IN site_assets (NO NEW TABLE)
-- -----------------------------------------------------------------------------
-- A controlled door is just another piece of installed equipment from a CMDB
-- perspective — type, install date, parent asset (panel), location. Forcing it
-- into a separate `doors` table would split asset management. Convention:
-- asset_type.code = 'access_door' marks a site_asset as a door for the
-- security UI.
--
-- WHY pos_terminals IS A SEPARATE TABLE (NOT site_assets)
-- -----------------------------------------------------------------------------
-- Heartbeat config (shared_secret, interval, stale_after) and the last_seen_at
-- denorm are POS-specific and would clutter site_assets if inlined. Keeping
-- pos_terminals separate (with optional FK back to site_assets for the CMDB
-- view) keeps the schema honest. The terminal_code is the public-facing ID
-- used in webhook URLs — never expose the internal id.
--
-- WHY ONE programming_logs TABLE INSTEAD OF FOUR
-- -----------------------------------------------------------------------------
-- The audit shape is identical across credentials, doors, schedules, and POS
-- terminal configs (who/what/when, before, after, action). Splitting per
-- target type would force a UNION on every "show me the history of this
-- customer's security/POS changes" query. The (target_type, target_id)
-- composite index makes per-target lookups fast.
--
-- WHY pos_heartbeats AS APPEND-ONLY (NOT UPSERT-LATEST-ONLY)
-- -----------------------------------------------------------------------------
-- Customers ask "did the terminal flap last week?" — we need the time-series.
-- The denormalized last_seen_at + last_status on pos_terminals makes the hot
-- path (sweeper + dashboard "is it up?") cheap; the time-series is for
-- forensics and reports. Consider partitioning by month if a customer pushes
-- hundreds of terminals through this.
--
-- FK TYPE NOTES
-- -----------------------------------------------------------------------------
-- Mirrors migrations 163-165: legacy tables (customers, sites, site_assets,
-- users, tickets) are INT UNSIGNED; new Phase 16 tables use BIGINT UNSIGNED
-- PKs. Cross-table FKs to legacy tables stay INT UNSIGNED on the FK column
-- so the type matches the parent column.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- Every table created via CREATE TABLE IF NOT EXISTS. No ALTERs to existing
-- tables, no DROPs, no destructive ops. Re-runs are no-ops.
--
-- ROLLBACK NOTE (manual; never auto-rollback in production)
-- -----------------------------------------------------------------------------
--   DROP TABLE pos_heartbeats;
--   DROP TABLE pos_terminals;
--   DROP TABLE programming_logs;
--   DROP TABLE credential_doors;
--   DROP TABLE credential_registers;
--   DROP TABLE access_schedules;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: access_schedules — recurring access policy (S1)
--
-- days_of_week: comma-separated lowercase three-letter codes from
--   ('mon','tue','wed','thu','fri','sat','sun'). Validated at the application
--   layer so we don't need a CHECK constraint that varies by MariaDB version.
--
-- start_time/end_time: same-day window. Cross-midnight schedules are modeled
--   as TWO rows (e.g. "21:00-23:59" + "00:00-06:00") so a single row stays
--   trivially comparable.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS access_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    days_of_week VARCHAR(40) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    timezone VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_access_schedules_customer (customer_id),
    INDEX idx_access_schedules_active (customer_id, is_active),
    CONSTRAINT fk_access_schedules_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: credential_registers — issued credentials (S1)
--
-- credential_type: 'card' | 'fob' | 'pin' | 'mobile' | 'biometric' | 'plate'
-- format:          free-form (wiegand-26, hid-prox, mifare, ble-token, etc.)
-- credential_code: the on-the-wire identifier. For PINs we store a salted
--                  hash, NEVER plaintext — enforced by the service layer.
--                  UNIQUE(customer_id, credential_type, credential_code)
--                  prevents duplicate enrollment.
-- status:          'active' | 'suspended' | 'revoked' | 'lost'
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credential_registers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NULL,
    holder_name VARCHAR(191) NOT NULL,
    holder_email VARCHAR(191) NULL,
    holder_phone VARCHAR(40) NULL,
    holder_employee_id VARCHAR(80) NULL,
    credential_type VARCHAR(40) NOT NULL,
    credential_code VARCHAR(160) NOT NULL,
    credential_format VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    suspended_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    UNIQUE KEY uq_credential_code (customer_id, credential_type, credential_code),
    INDEX idx_credentials_customer (customer_id),
    INDEX idx_credentials_site (site_id),
    INDEX idx_credentials_status (customer_id, status),
    INDEX idx_credentials_holder_email (holder_email),
    INDEX idx_credentials_expires (expires_at),
    CONSTRAINT fk_credentials_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_credentials_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: credential_doors — credential ↔ door (site_asset) m:n (S1)
--
-- A row is the formal grant of access. Revocation flips revoked_at + records
-- a reason; we keep the row (not DELETE) so the programming_logs trail
-- + the per-credential history view stay populated.
--
-- access_schedule_id is optional: NULL means 24/7 access at this door for
-- this credential.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credential_doors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credential_id BIGINT UNSIGNED NOT NULL,
    site_asset_id INT UNSIGNED NOT NULL,
    access_schedule_id BIGINT UNSIGNED NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    granted_by_user_id INT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    revoked_by_user_id INT UNSIGNED NULL,
    revoke_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_credential_door (credential_id, site_asset_id),
    INDEX idx_credential_doors_credential (credential_id),
    INDEX idx_credential_doors_site_asset (site_asset_id),
    INDEX idx_credential_doors_active (site_asset_id, revoked_at),
    INDEX idx_credential_doors_schedule (access_schedule_id),
    CONSTRAINT fk_credential_doors_credential FOREIGN KEY (credential_id)
        REFERENCES credential_registers(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_doors_asset FOREIGN KEY (site_asset_id)
        REFERENCES site_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_doors_schedule FOREIGN KEY (access_schedule_id)
        REFERENCES access_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART D: programming_logs — polymorphic config-change audit (S1 + S2)
--
-- target_type:   'credential' | 'credential_door' | 'access_schedule'
--                | 'pos_terminal' | 'door'
-- target_id:     id of the row in the target table (BIGINT to fit either
--                INT or BIGINT parent ids; the application asserts the type
--                ↔ id pairing on insert).
-- action:        'created' | 'updated' | 'deleted' | 'assigned' | 'revoked'
--                | 'enabled' | 'disabled' | 'config_changed'
--                | 'webhook_received' (for POS) | 'sweep_alert' (for POS sweeper)
-- before/after:  JSON snapshots of the row at programming time. NULL on
--                creation (before) / deletion (after).
-- programmed_by_user_id: internal actor when the change came from a logged-in
--                user. NULL means external/system source — see
--                programmed_by_external for the label ('POS-WEBHOOK',
--                'cron:pos-sweeper', etc.).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS programming_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    summary VARCHAR(255) NULL,
    before_snapshot JSON NULL,
    after_snapshot JSON NULL,
    programmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    programmed_by_user_id INT UNSIGNED NULL,
    programmed_by_external VARCHAR(120) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_programming_logs_target (target_type, target_id, programmed_at),
    INDEX idx_programming_logs_customer (customer_id, programmed_at),
    INDEX idx_programming_logs_action (action),
    INDEX idx_programming_logs_actor (programmed_by_user_id),
    CONSTRAINT fk_programming_logs_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_programming_logs_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART E: pos_terminals — POS device registry (S2)
--
-- terminal_code:           public-facing identifier the device sends in the
--                          webhook URL. UNIQUE per customer.
-- shared_secret:           HMAC-SHA256 secret. Webhook receiver computes
--                          HMAC over the raw body and compares to the
--                          X-PHParm-Signature header. Stored hex (64 chars).
-- heartbeat_interval_seconds: expected cadence. The sweeper uses
--                          stale_after_seconds (NOT this) for alerting; this
--                          is metadata for the dashboard.
-- stale_after_seconds:     last_seen_at older than this triggers an alert
--                          ticket via the cron sweeper. Default 300 (5 min)
--                          matches the Phase 16 exit criterion.
-- last_seen_at / last_status: hot-path denorm so the dashboard and sweeper
--                          don't have to scan pos_heartbeats. Updated by
--                          the heartbeat ingestion service inside the same
--                          transaction as the heartbeat insert.
-- last_alert_ticket_id:    points at the most-recent stale-alert ticket so
--                          the sweeper doesn't re-fire while the ticket is
--                          still open. Cleared when a fresh heartbeat lands.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pos_terminals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    site_asset_id INT UNSIGNED NULL,
    terminal_code VARCHAR(80) NOT NULL,
    name VARCHAR(120) NULL,
    vendor VARCHAR(80) NULL,
    model VARCHAR(80) NULL,
    serial_number VARCHAR(120) NULL,
    shared_secret CHAR(64) NOT NULL,
    heartbeat_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    stale_after_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_seen_at DATETIME NULL,
    last_status VARCHAR(20) NULL,
    last_alert_ticket_id INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pos_terminal_code (customer_id, terminal_code),
    INDEX idx_pos_terminals_customer (customer_id),
    INDEX idx_pos_terminals_site (site_id),
    INDEX idx_pos_terminals_status (status),
    INDEX idx_pos_terminals_last_seen (last_seen_at),
    INDEX idx_pos_terminals_alert (last_alert_ticket_id),
    CONSTRAINT fk_pos_terminals_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_pos_terminals_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_pos_terminals_asset FOREIGN KEY (site_asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_pos_terminals_alert FOREIGN KEY (last_alert_ticket_id)
        REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART F: pos_heartbeats — append-only time-series of received heartbeats (S2)
--
-- received_at: when the server received the webhook (authoritative).
-- reported_at: timestamp the device put in the payload (NULL if absent).
-- status:      device-reported status — 'online' | 'offline' | 'degraded' |
--              'error'. Online is the common case; the others come from the
--              POS vendor's own self-test.
-- payload:     full JSON the device sent, for debugging.
-- ip_address:  source IP of the webhook, for cross-checking that the
--              terminal hasn't been moved/spoofed.
--
-- Composite index (terminal_id, received_at DESC) is the hot path for
-- "newest-first per terminal." A separate (received_at) index supports
-- "all heartbeats in this hour" reporting.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pos_heartbeats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id BIGINT UNSIGNED NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reported_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'online',
    payload JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pos_heartbeats_terminal_received (terminal_id, received_at),
    INDEX idx_pos_heartbeats_received (received_at),
    INDEX idx_pos_heartbeats_status (terminal_id, status),
    CONSTRAINT fk_pos_heartbeats_terminal FOREIGN KEY (terminal_id)
        REFERENCES pos_terminals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
