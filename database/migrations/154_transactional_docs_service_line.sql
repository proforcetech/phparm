-- =============================================================================
-- Phase 11.5 of docs/woms-expansion-plan.md (and of the multi-vertical doc plan)
--
-- Adds the service_line_id discriminator to the three customer-facing
-- transactional document tables that didn't get one in migration 152:
--   • appointments
--   • estimates
--   • invoices
--
-- workorders, tickets, contracts, site_assets and labor_tasks already received
-- the column in migration 152 (Phase 11). Without this column on the document
-- side, the UI cannot tell whether an estimate is for an automotive vehicle
-- repair or a property/IT/security service call, and the service layer cannot
-- enforce per-line "what subject this document is about" rules.
--
-- WHY auto_repair AS THE DEFAULT BACKFILL
-- Every existing row in production was authored under the original automotive
-- product. Tagging legacy rows as auto_repair preserves filter accuracy on
-- dashboards/reports and lets us flip non-auto verticals on without rewriting
-- history.
--
-- IDEMPOTENCY
-- Mirrors the prepared-statement IF() pattern from migration 152: every ADD
-- COLUMN / INDEX / FK is guarded against information_schema, every backfill
-- UPDATE has a WHERE NULL guard. A second run is a no-op.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- appointments.service_line_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE appointments ADD COLUMN service_line_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND index_name = 'idx_appointments_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE appointments ADD INDEX idx_appointments_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND constraint_name = 'fk_appointments_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- estimates.service_line_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE estimates ADD COLUMN service_line_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND index_name = 'idx_estimates_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE estimates ADD INDEX idx_estimates_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND constraint_name = 'fk_estimates_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE estimates ADD CONSTRAINT fk_estimates_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- invoices.service_line_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoices ADD COLUMN service_line_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND constraint_name = 'fk_invoices_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- Backfill: tag every legacy row as auto_repair.
-- Resolved by slug rather than hard-coded id so this stays correct if the
-- service_lines table is reseeded with different auto_increment values.
-- -----------------------------------------------------------------------------
SET @auto_id := (SELECT id FROM service_lines WHERE slug = 'auto_repair' LIMIT 1);

UPDATE appointments SET service_line_id = @auto_id WHERE service_line_id IS NULL AND @auto_id IS NOT NULL;
UPDATE estimates    SET service_line_id = @auto_id WHERE service_line_id IS NULL AND @auto_id IS NOT NULL;
UPDATE invoices     SET service_line_id = @auto_id WHERE service_line_id IS NULL AND @auto_id IS NOT NULL;
