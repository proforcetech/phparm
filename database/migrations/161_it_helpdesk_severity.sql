-- =============================================================================
-- Phase 14 of docs/woms-expansion-plan.md: IT Support vertical (M8)
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- Adds IT-helpdesk-specific fields to the existing `tickets` table so the same
-- table can serve both general support (auto repair, building, etc.) and IT
-- helpdesk workloads without forking the schema.
--
-- New columns:
--   * severity              VARCHAR(8)   NULL  — 'P1','P2','P3','P4' or NULL
--                                                when not categorized as IT.
--                                                Distinct from `priority`
--                                                (which is workflow ordering);
--                                                severity drives ITIL escalation
--                                                and SLA targets.
--   * affected_users_count  INT UNSIGNED NULL  — number of users impacted by
--                                                the incident; used to gate
--                                                outage escalation (e.g. >10
--                                                affected → auto-bump to P1).
--   * business_impact       TEXT         NULL  — free-text description of the
--                                                business consequence; required
--                                                for P1/P2 by service rules
--                                                (validated in PHP).
--   * it_request_kind       VARCHAR(40)  NULL  — 'incident','request',
--                                                'question','outage'. Allowed
--                                                values live in PHP
--                                                (Ticket::IT_REQUEST_KINDS) so
--                                                adding a kind stays a code
--                                                change.
--
-- New indexes:
--   * idx_tickets_severity (severity)            — filter the IT helpdesk board
--   * idx_tickets_it_request_kind (it_request_kind) — segment incidents from
--                                                    requests in dashboards
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- Mirrors the prepared-statement information_schema pattern from migration 152.
-- Every ALTER is guarded; every column is NULLABLE and additive; a re-run is
-- a no-op. There are no destructive operations and no backfills (severity is
-- intentionally NULL for non-IT tickets — the IT helpdesk service populates it
-- when an IT-categorized ticket is created or reclassified).
--
-- ROLLBACK NOTE (manual)
-- -----------------------------------------------------------------------------
--   ALTER TABLE tickets DROP INDEX idx_tickets_severity;
--   ALTER TABLE tickets DROP INDEX idx_tickets_it_request_kind;
--   ALTER TABLE tickets DROP COLUMN severity;
--   ALTER TABLE tickets DROP COLUMN affected_users_count;
--   ALTER TABLE tickets DROP COLUMN business_impact;
--   ALTER TABLE tickets DROP COLUMN it_request_kind;
-- =============================================================================

-- tickets.severity
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'severity');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE tickets ADD COLUMN severity VARCHAR(8) NULL AFTER priority',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND index_name = 'idx_tickets_severity');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE tickets ADD INDEX idx_tickets_severity (severity)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tickets.affected_users_count
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'affected_users_count');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE tickets ADD COLUMN affected_users_count INT UNSIGNED NULL AFTER severity',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tickets.business_impact
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'business_impact');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE tickets ADD COLUMN business_impact TEXT NULL AFTER affected_users_count',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tickets.it_request_kind
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'it_request_kind');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE tickets ADD COLUMN it_request_kind VARCHAR(40) NULL AFTER business_impact',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND index_name = 'idx_tickets_it_request_kind');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE tickets ADD INDEX idx_tickets_it_request_kind (it_request_kind)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
