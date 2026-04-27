-- Phase 10.8 of docs/expansion-plan.md — Kit/bundle install support on
-- workorders. Bundles already power the *estimate* side via
-- BundleService::applyToEstimate(); this migration introduces the parallel
-- workorder-side concept: applying a bundle (a "kit") directly to a
-- workorder once the WO is in flight, with proper inventory consumption
-- and an audit trail of what was installed when, by whom, and against
-- which inventory rows.
--
-- Two tables:
--
--   workorder_kit_installs
--     Header row — one per "we installed kit X on WO Y" event. The
--     bundle_id FK is SET NULL because bundles can be retired by admins
--     while installed instances must remain queryable; bundle_name_snapshot
--     preserves the human-readable kit name for historical reads.
--
--   workorder_kit_install_items
--     Per-item snapshot of what the kit *was* at install time (description,
--     unit price, quantity) plus the workorder_item_id of the line that the
--     install workflow created on the WO. PART rows additionally carry
--     stock_consumed and stock_consumed_at so cancellation knows exactly
--     how much to return to inventory and confirms whether consumption
--     actually happened (zero-stock items installed without consumption are
--     legal — the install proceeds and the parts pull request system picks
--     up the slack).
--
-- Lifecycle:
--   planned   → kit selected, snapshot persisted, no WO line items created
--                yet, no inventory movement
--   installed → workorder_items lines materialised, inventory consumed for
--                PART items with inventory_item_id, total_parts_consumed set
--   cancelled → terminal; if the install had reached "installed" state the
--                cancel action restores inventory and removes the WO line
--                items it created, with a cancellation_reason on record
--
-- All FKs guarded on referenced-table existence so the migration is safe
-- on partially-set-up databases.

CREATE TABLE IF NOT EXISTS workorder_kit_installs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    workorder_job_id INT UNSIGNED NULL,
    bundle_id INT UNSIGNED NULL,
    bundle_name_snapshot VARCHAR(160) NOT NULL,
    installed_by_user_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    planned_at DATETIME NULL,
    installed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason TEXT NULL,
    notes TEXT NULL,
    total_parts_consumed INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "All kit installs on this workorder" — the WO timeline & summary view
-- need this for the "kits installed" rollup.
SET @idx_wo := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND index_name = 'idx_wki_workorder');
SET @sql := IF(@idx_wo = 0,
    'CREATE INDEX idx_wki_workorder ON workorder_kit_installs (workorder_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Installs scoped to a specific job" — kits often map to a single job
-- (e.g. "front brake kit" → the brake job) and the per-job UI filters this
-- way.
SET @idx_job := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND index_name = 'idx_wki_job');
SET @sql := IF(@idx_job = 0,
    'CREATE INDEX idx_wki_job ON workorder_kit_installs (workorder_job_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "What did we install from this bundle, ever?" — bundle analytics
-- (popular kits, install rate) and the cancel-bundle prompt that warns
-- "you have N planned installs of this kit pending".
SET @idx_bundle := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND index_name = 'idx_wki_bundle');
SET @sql := IF(@idx_bundle = 0,
    'CREATE INDEX idx_wki_bundle ON workorder_kit_installs (bundle_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Status filter — "show me planned installs across the shop" is the
-- dispatch view so they can prep parts ahead of the tech reaching that
-- line item.
SET @idx_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND index_name = 'idx_wki_status');
SET @sql := IF(@idx_status = 0,
    'CREATE INDEX idx_wki_status ON workorder_kit_installs (status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FKs guarded on referenced-table existence so the migration is safe on
-- partially-bootstrapped databases (e.g. a fresh install where bundles is
-- created later).
SET @has_workorders := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND constraint_name = 'fk_wki_workorder');
SET @sql := IF(@has_workorders = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_installs ADD CONSTRAINT fk_wki_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_jobs := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_jobs');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND constraint_name = 'fk_wki_job');
SET @sql := IF(@has_jobs = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_installs ADD CONSTRAINT fk_wki_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_bundles := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'bundles');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND constraint_name = 'fk_wki_bundle');
SET @sql := IF(@has_bundles = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_installs ADD CONSTRAINT fk_wki_bundle FOREIGN KEY (bundle_id) REFERENCES bundles(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_users := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_installs'
      AND constraint_name = 'fk_wki_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_installs ADD CONSTRAINT fk_wki_user FOREIGN KEY (installed_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Per-item snapshot table.
CREATE TABLE IF NOT EXISTS workorder_kit_install_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    install_id INT UNSIGNED NOT NULL,
    workorder_item_id INT UNSIGNED NULL,
    bundle_item_id INT UNSIGNED NULL,
    inventory_item_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_consumed INT NOT NULL DEFAULT 0,
    stock_consumed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "All items for this install" — the show endpoint joins this on every
-- read of an install header.
SET @idx_install := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_install_items'
      AND index_name = 'idx_wkii_install');
SET @sql := IF(@idx_install = 0,
    'CREATE INDEX idx_wkii_install ON workorder_kit_install_items (install_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Reverse lookup — "which installs touched this inventory row?" — used
-- by inventory drilldown to show "consumed by kit install on WO 42".
SET @idx_inv := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_install_items'
      AND index_name = 'idx_wkii_inventory');
SET @sql := IF(@idx_inv = 0,
    'CREATE INDEX idx_wkii_inventory ON workorder_kit_install_items (inventory_item_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FKs.
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_install_items'
      AND constraint_name = 'fk_wkii_install');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE workorder_kit_install_items ADD CONSTRAINT fk_wkii_install FOREIGN KEY (install_id) REFERENCES workorder_kit_installs(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_witems := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_items');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_install_items'
      AND constraint_name = 'fk_wkii_woitem');
SET @sql := IF(@has_witems = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_install_items ADD CONSTRAINT fk_wkii_woitem FOREIGN KEY (workorder_item_id) REFERENCES workorder_items(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_bitems := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'bundle_items');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_install_items'
      AND constraint_name = 'fk_wkii_bitem');
SET @sql := IF(@has_bitems = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_install_items ADD CONSTRAINT fk_wkii_bitem FOREIGN KEY (bundle_item_id) REFERENCES bundle_items(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_inv := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_kit_install_items'
      AND constraint_name = 'fk_wkii_inventory');
SET @sql := IF(@has_inv = 1 AND @has_fk = 0,
    'ALTER TABLE workorder_kit_install_items ADD CONSTRAINT fk_wkii_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
