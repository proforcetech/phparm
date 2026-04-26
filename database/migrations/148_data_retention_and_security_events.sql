-- Cross-cutting: Retention policies + SOC logging.
--
-- Three tables that together replace the hardcoded retention behavior in
-- `bin/cron/data-cleanup.php` with configurable per-entity policies, AND
-- introduce a SOC-style security event log distinct from the generic
-- audit_logs surface.
--
-- Why two separate logs:
--   audit_logs       — every domain mutation (create/update/delete on
--                       any business entity) for general traceability.
--                       High write volume; mostly informational.
--   security_events  — SOC-relevant events only: auth success/failure,
--                       privilege escalations, admin actions, lockouts,
--                       suspicious patterns. Lower write volume; each
--                       row is something a security analyst should be
--                       able to triage. Indexed differently (severity
--                       + actor_user_id + event_type) so SOC queries
--                       don't compete with audit reads on the same hot
--                       index pages.
--
-- Tables:
--
--   data_retention_policies
--     One row per entity type with its retention window, action
--     (delete vs archive), and last-run summary. The runner reads this
--     table and applies each active policy on its schedule.
--
--   data_retention_runs
--     History of every retention run (manual or scheduled) with
--     start/end times, dry-run flag, records examined/affected, error
--     message on failure. Lets ops review "what did we delete on
--     2026-04-25 at 03:00 and was it right?"
--
--   security_events
--     SOC log: auth events, privilege changes, admin actions,
--     suspicious activity. Records actor + target so we can answer
--     "who did this to whom" (e.g., admin A demoted user B).
--
-- All FKs guarded on referenced-table existence so the migration is
-- safe on partially-set-up databases.

CREATE TABLE IF NOT EXISTS data_retention_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(80) NOT NULL,
    table_name VARCHAR(80) NOT NULL,
    timestamp_column VARCHAR(60) NOT NULL DEFAULT 'created_at',
    retention_days INT NOT NULL DEFAULT 90,
    action VARCHAR(20) NOT NULL DEFAULT 'delete',
    archive_table_name VARCHAR(80) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_run_status VARCHAR(20) NULL,
    last_run_records INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "Look up the policy for entity X" — primary read pattern from the
-- runner + the management UI. Kept UNIQUE because each entity type
-- gets exactly one active policy at a time (multiple windows on the
-- same entity is a configuration mistake we want to catch at insert).
SET @idx_entity := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'data_retention_policies'
      AND index_name = 'uniq_drp_entity');
SET @sql := IF(@idx_entity = 0,
    'CREATE UNIQUE INDEX uniq_drp_entity ON data_retention_policies (entity_type)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Active-only filter for the runner ("which policies are due?").
SET @idx_active := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'data_retention_policies'
      AND index_name = 'idx_drp_active');
SET @sql := IF(@idx_active = 0,
    'CREATE INDEX idx_drp_active ON data_retention_policies (is_active)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS data_retention_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    records_examined INT NULL,
    records_affected INT NULL,
    dry_run TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    triggered_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "Show me runs for this policy, newest first" — the management UI
-- joins this on every detail view of a policy.
SET @idx_policy := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'data_retention_runs'
      AND index_name = 'idx_drr_policy');
SET @sql := IF(@idx_policy = 0,
    'CREATE INDEX idx_drr_policy ON data_retention_runs (policy_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Time-window queries — "what ran in the last 7 days?" for the SOC
-- dashboard's "retention activity" widget.
SET @idx_started := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'data_retention_runs'
      AND index_name = 'idx_drr_started');
SET @sql := IF(@idx_started = 0,
    'CREATE INDEX idx_drr_started ON data_retention_runs (started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FKs for retention runs.
SET @has_drp := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'data_retention_policies');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'data_retention_runs'
      AND constraint_name = 'fk_drr_policy');
SET @sql := IF(@has_drp = 1 AND @has_fk = 0,
    'ALTER TABLE data_retention_runs ADD CONSTRAINT fk_drr_policy FOREIGN KEY (policy_id) REFERENCES data_retention_policies(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_users := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'data_retention_runs'
      AND constraint_name = 'fk_drr_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE data_retention_runs ADD CONSTRAINT fk_drr_user FOREIGN KEY (triggered_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS security_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    actor_user_id INT NULL,
    target_user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    request_path VARCHAR(500) NULL,
    context JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "All events of this type" — the SOC dashboard breakdown by event type
-- ("show me all login_failure events").
SET @idx_evt := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'security_events'
      AND index_name = 'idx_se_event');
SET @sql := IF(@idx_evt = 0,
    'CREATE INDEX idx_se_event ON security_events (event_type)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All events for this actor" — "what did admin X do this week?"
-- which is a primary forensic query.
SET @idx_actor := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'security_events'
      AND index_name = 'idx_se_actor');
SET @sql := IF(@idx_actor = 0,
    'CREATE INDEX idx_se_actor ON security_events (actor_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Critical events first" — the SOC inbox shows critical events at the
-- top of the queue, then warnings, then info.
SET @idx_sev := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'security_events'
      AND index_name = 'idx_se_severity');
SET @sql := IF(@idx_sev = 0,
    'CREATE INDEX idx_se_severity ON security_events (severity)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Time-window queries — "show me events in the last hour" for the
-- live SOC view that ticks every 60s.
SET @idx_created := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'security_events'
      AND index_name = 'idx_se_created');
SET @sql := IF(@idx_created = 0,
    'CREATE INDEX idx_se_created ON security_events (created_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FKs for security_events. Both nullable + SET NULL because user
-- deletion shouldn't break the security log — anonymising the actor
-- is preferable to losing the event entirely.
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'security_events'
      AND constraint_name = 'fk_se_actor');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE security_events ADD CONSTRAINT fk_se_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'security_events'
      AND constraint_name = 'fk_se_target');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE security_events ADD CONSTRAINT fk_se_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Seed defaults — these mirror the hardcoded retention windows that
-- previously lived in `bin/cron/data-cleanup.php` so the new policy-
-- driven runner picks up the same behavior on first boot. The runner
-- only operates on tables that exist; missing tables produce a
-- skipped run rather than an error so the seed is safe even on a
-- partially-set-up DB.
INSERT IGNORE INTO data_retention_policies
    (entity_type, table_name, timestamp_column, retention_days, action, is_active, notes)
VALUES
    ('audit_logs', 'audit_logs', 'created_at', 730, 'delete', 1,
        'General audit trail — 2 years matches SOC compliance windows.'),
    ('notification_logs', 'notification_logs', 'created_at', 90, 'delete', 1,
        'Customer-facing notifications — 90 days for delivery dispute window.'),
    ('reminder_logs', 'reminder_logs', 'created_at', 90, 'delete', 1,
        'Reminder send history — same window as notifications.'),
    ('security_events_info', 'security_events', 'created_at', 365, 'delete', 1,
        'SOC info-level events — 1 year sliding window. Critical/warning rows are NOT subject to this — they are filtered out by the runner so they live forever (or until a separate retention policy is added).'),
    ('payment_sessions', 'payment_sessions', 'created_at', 1, 'delete', 1,
        'Payment session tokens — 24h. Replaces hardcoded 24-hour DELETE in data-cleanup.');
