-- Phase 7.4 of docs/expansion-plan.md — fleet cost reports.
--
-- Adds a nullable fleet_unit_id link on workorders so cost-per-mile and
-- cost-per-hour reports can aggregate workorder spend (grand_total +
-- labor/parts split via workorder_items.type) against fleet_unit_readings
-- meter deltas. Nullable so non-fleet workorders (walk-in customers,
-- one-off repairs) keep working unchanged. ON DELETE SET NULL so a
-- retired/purged fleet unit doesn't cascade-delete its workorder history.

SET @has_workorder_fleet_unit_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'fleet_unit_id'
);
SET @workorder_fleet_unit_id_sql := IF(@has_workorder_fleet_unit_id = 0,
    'ALTER TABLE workorders ADD COLUMN fleet_unit_id INT UNSIGNED NULL AFTER vehicle_id',
    'SELECT 1');
PREPARE workorder_fleet_unit_id_stmt FROM @workorder_fleet_unit_id_sql;
EXECUTE workorder_fleet_unit_id_stmt;
DEALLOCATE PREPARE workorder_fleet_unit_id_stmt;

SET @has_idx_workorder_fleet_unit := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorder_fleet_unit'
);
SET @idx_workorder_fleet_unit_sql := IF(@has_idx_workorder_fleet_unit = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorder_fleet_unit (fleet_unit_id, completed_at)',
    'SELECT 1');
PREPARE idx_workorder_fleet_unit_stmt FROM @idx_workorder_fleet_unit_sql;
EXECUTE idx_workorder_fleet_unit_stmt;
DEALLOCATE PREPARE idx_workorder_fleet_unit_stmt;

-- FK guarded by fleet_units existence (runs cleanly on pre-Phase-7.1 DBs).
SET @fleet_units_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'fleet_units'
);
SET @has_fk_workorder_fleet_unit := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND constraint_name = 'fk_workorder_fleet_unit'
);
SET @fk_workorder_fleet_unit_sql := IF(@fleet_units_exists > 0 AND @has_fk_workorder_fleet_unit = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorder_fleet_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_workorder_fleet_unit_stmt FROM @fk_workorder_fleet_unit_sql;
EXECUTE fk_workorder_fleet_unit_stmt;
DEALLOCATE PREPARE fk_workorder_fleet_unit_stmt;
