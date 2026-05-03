-- =============================================================================
-- Phase 14 of docs/woms-expansion-plan.md: IT Support vertical (M8) escalation
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- Adds a `match_severity` column + index to `ticket_escalation_rules` so a
-- single escalation policy can target an ITIL severity (P1..P4) independent of
-- the workflow `priority` field. This keeps the auto-repair priority semantics
-- and the IT helpdesk severity semantics on separate axes — a P1 IT incident
-- is not the same thing as a p1_critical auto-repair priority, and operators
-- need to be able to write rules like "any open P1 incident older than 15 min
-- pages oncall" without touching priority.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- Mirrors the prepared-statement information_schema pattern from migration 152.
-- The column is NULLABLE and additive; the index is conditional. A re-run is
-- a no-op. There are no destructive operations and no backfills (existing
-- rules continue to match by priority/status/queue/division as before; severity
-- matching only fires when an operator explicitly sets `match_severity`).
--
-- ROLLBACK NOTE (manual)
-- -----------------------------------------------------------------------------
--   ALTER TABLE ticket_escalation_rules DROP INDEX idx_ter_match_severity;
--   ALTER TABLE ticket_escalation_rules DROP COLUMN match_severity;
-- =============================================================================

-- ticket_escalation_rules.match_severity
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ticket_escalation_rules' AND column_name = 'match_severity');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE ticket_escalation_rules ADD COLUMN match_severity VARCHAR(8) NULL AFTER match_priority',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ticket_escalation_rules' AND index_name = 'idx_ter_match_severity');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE ticket_escalation_rules ADD INDEX idx_ter_match_severity (match_severity)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
