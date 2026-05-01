-- =============================================================================
-- Phase A.3 of the multi-vertical document plan — first non-automotive
-- subject FK: site_asset_id.
--
-- For the property-management / building-repair verticals, the "thing the
-- document is about" is a site asset (HVAC unit, roof, elevator, fire panel,
-- camera, door controller, etc.) rather than a customer_vehicle. This adds
-- a NULLABLE FK alongside vehicle_id and fleet_unit_id on the four
-- customer-facing transactional tables.
--
-- This is the same sparse-nullable-FK pattern already used by
-- workorders.fleet_unit_id (migration 131): one column per subject type,
-- NULLABLE, indexed, FK-protected. Future verticals (equipment_repair,
-- it_support, security_systems) will add their own equivalent columns —
-- e.g. equipment_id, software_asset_id — under the same pattern.
--
-- WHICH SUBJECT IS "PRIMARY" FOR A GIVEN ROW
-- The application reads service_line_id (added in migration 154) as the
-- discriminator and loads the matching subject column. SubjectResolver
-- (PHP, Phase B) encapsulates that mapping so callers don't have to.
--
-- IDEMPOTENCY
-- Mirrors the prepared-statement IF() pattern from migration 152. Every
-- ALTER ADD COLUMN / INDEX / FK is guarded against information_schema, so a
-- second run is a no-op. No backfill is needed — every existing row predates
-- the property vertical, so they all stay NULL on this column.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- appointments.site_asset_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'site_asset_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE appointments ADD COLUMN site_asset_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND index_name = 'idx_appointments_site_asset');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE appointments ADD INDEX idx_appointments_site_asset (site_asset_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND constraint_name = 'fk_appointments_site_asset');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_site_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- estimates.site_asset_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'site_asset_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE estimates ADD COLUMN site_asset_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND index_name = 'idx_estimates_site_asset');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE estimates ADD INDEX idx_estimates_site_asset (site_asset_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND constraint_name = 'fk_estimates_site_asset');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE estimates ADD CONSTRAINT fk_estimates_site_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- workorders.site_asset_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'site_asset_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN site_asset_id INT UNSIGNED NULL AFTER fleet_unit_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_site_asset');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_site_asset (site_asset_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND constraint_name = 'fk_workorders_site_asset');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorders_site_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- invoices.site_asset_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'site_asset_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoices ADD COLUMN site_asset_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_site_asset');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_site_asset (site_asset_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND constraint_name = 'fk_invoices_site_asset');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_site_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
