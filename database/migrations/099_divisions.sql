-- Phase 0.2 of docs/expansion-plan.md: introduce divisions as a first-class
-- tenancy dimension so each service line (auto repair, commercial cleaning,
-- IT support, telecom, surveillance, access control, fleet, etc.) can have
-- its own categories, templates, billing rules, and technicians.
--
-- This migration:
--   1. Creates the divisions reference table
--   2. Seeds a default "auto_repair" division so existing rows have a home
--   3. Adds nullable division_id columns (with indexes) to core entities
--   4. Backfills every row to the default division
--
-- The division_id columns stay NULLABLE for now. A later migration (after
-- code is updated to always write a division_id) will promote them to
-- NOT NULL with a FK constraint.

-- -----------------------------------------------------------------------------
-- 1. divisions table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS divisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_divisions_active (is_active),
    INDEX idx_divisions_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. seed default division (auto_repair is the legacy service line)
-- -----------------------------------------------------------------------------
INSERT INTO divisions (code, name, description, is_active, sort_order)
SELECT 'auto_repair', 'Auto Repair', 'Legacy auto repair shop operations (pre-expansion default)', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM divisions WHERE code = 'auto_repair');

-- -----------------------------------------------------------------------------
-- 3. add division_id to core entities + backfill
-- Helper pattern (mirrors 090_add_branch_ids.sql): check information_schema
-- before altering, so the migration is idempotent.
-- -----------------------------------------------------------------------------

-- users
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE users ADD COLUMN division_id INT UNSIGNED NULL AFTER branch_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE users ADD INDEX idx_users_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- customers
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE customers ADD COLUMN division_id INT UNSIGNED NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND index_name = 'idx_customers_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE customers ADD INDEX idx_customers_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- workorders
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN division_id INT UNSIGNED NULL AFTER branch_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- estimates
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE estimates ADD COLUMN division_id INT UNSIGNED NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND index_name = 'idx_estimates_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE estimates ADD INDEX idx_estimates_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- invoices
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoices ADD COLUMN division_id INT UNSIGNED NULL AFTER branch_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inventory_items
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inventory_items ADD COLUMN division_id INT UNSIGNED NULL AFTER branch_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND index_name = 'idx_inventory_items_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE inventory_items ADD INDEX idx_inventory_items_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE appointments ADD COLUMN division_id INT UNSIGNED NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND index_name = 'idx_appointments_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE appointments ADD INDEX idx_appointments_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inspection_templates (templates vary by service line)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_templates' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_templates ADD COLUMN division_id INT UNSIGNED NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inspection_templates' AND index_name = 'idx_inspection_templates_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE inspection_templates ADD INDEX idx_inspection_templates_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_types (categorization by service line)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'service_types' AND column_name = 'division_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE service_types ADD COLUMN division_id INT UNSIGNED NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'service_types' AND index_name = 'idx_service_types_division');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE service_types ADD INDEX idx_service_types_division (division_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. backfill existing rows to the default auto_repair division
-- -----------------------------------------------------------------------------
SET @default_division_id := (SELECT id FROM divisions WHERE code = 'auto_repair' LIMIT 1);

UPDATE users             SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE customers         SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE workorders        SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE estimates         SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE invoices          SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE inventory_items   SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE appointments      SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE inspection_templates SET division_id = @default_division_id WHERE division_id IS NULL;
UPDATE service_types     SET division_id = @default_division_id WHERE division_id IS NULL;
