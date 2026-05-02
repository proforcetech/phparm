-- =============================================================================
-- Phase 12 of docs/woms-expansion-plan.md: Property Management vertical (M6)
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- Mirrors migration 157 onto the invoices table:
--   - Adds `invoices.unit_id` (NULLABLE FK → units(id)) so a property-mgmt
--     invoice can be traced back to the leasable space it covers.
--   - Adds `invoices.tenant_billable_party` (NULLABLE VARCHAR(20)) snapshotted
--     from the source workorder at conversion time, so a later lease change
--     cannot retroactively re-route a paid invoice.
--
-- Both columns are NULL for every non-property-mgmt invoice, preserving legacy
-- semantics. Populated only when the source workorder carries the property-
-- mgmt snapshot.
--
-- IDEMPOTENCY
-- -----------------------------------------------------------------------------
-- Fully idempotent — guarded by information_schema checks. Re-runs are no-ops.
--
-- ROLLBACK NOTE (manual)
-- -----------------------------------------------------------------------------
--   ALTER TABLE invoices DROP FOREIGN KEY fk_invoices_unit;
--   ALTER TABLE invoices DROP INDEX idx_invoices_unit;
--   ALTER TABLE invoices DROP COLUMN unit_id;
--   ALTER TABLE invoices DROP COLUMN tenant_billable_party;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: invoices.unit_id
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'unit_id');
-- AFTER workorder_id keeps the snapshot columns adjacent to the source-of-
-- truth FK and avoids a hard dependency on migration 154 having been applied
-- (which adds invoices.service_line_id). Both 154 and 158 are independent.
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoices ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER workorder_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_unit');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_unit (unit_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND constraint_name = 'fk_invoices_unit');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- PART B: invoices.tenant_billable_party
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'tenant_billable_party');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoices ADD COLUMN tenant_billable_party VARCHAR(20) NULL AFTER unit_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_tenant_billable');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_tenant_billable (tenant_billable_party)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
