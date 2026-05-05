-- =============================================================================
-- Direct workorder creation (no estimate) for contracted B2B customers, plus
-- per-visit odometer capture on the work record itself.
--
-- WHY estimate_id BECOMES NULLABLE
-- The estimate → workorder pipeline is the default flow, but contracted
-- customers (companies under an active service-line contract) can have a WO
-- created directly without first writing an estimate. The contract eligibility
-- check lives in WorkorderService::createDirect, not the schema.
--
-- Switching the FK to ON DELETE SET NULL also lets us prune an estimate
-- without orphan-failing the WO, matching how every other "linked document"
-- FK on workorders behaves (site_asset_id, fleet_unit_id, required_skill_id).
--
-- WHY mileage_in / mileage_out LIVE ON workorders
-- customer_vehicles.mileage_in/out is a per-vehicle "last seen" tracker, which
-- is wrong for capturing odometer at each service visit. The work record is
-- the per-visit row, so the per-visit odometer columns belong here. Estimates
-- intentionally do not get these columns — mileage is a service-time
-- observation, not a quoting input.
--
-- IDEMPOTENCY
-- Each step is guarded by an information_schema check so re-running the
-- migration is a no-op. The FK rebuild follows the pattern from migration
-- 047 (lookup CONSTRAINT_NAME, drop if present, MODIFY, re-add).
-- =============================================================================

-- 1) Drop the existing estimate_id FK (if any) so we can MODIFY then re-add
--    with ON DELETE SET NULL.
SET @constraint_name := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'estimate_id'
      AND CONSTRAINT_NAME != 'PRIMARY'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @drop_fk_sql := IF(@constraint_name IS NOT NULL,
    CONCAT('ALTER TABLE workorders DROP FOREIGN KEY ', @constraint_name),
    'SELECT 1');
PREPARE drop_fk_stmt FROM @drop_fk_sql;
EXECUTE drop_fk_stmt;
DEALLOCATE PREPARE drop_fk_stmt;

-- 2) workorders.estimate_id → NULLABLE (idempotent)
SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'estimate_id'
);
SET @modify_sql := IF(@is_nullable = 'NO',
    'ALTER TABLE workorders MODIFY COLUMN estimate_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE modify_stmt FROM @modify_sql;
EXECUTE modify_stmt;
DEALLOCATE PREPARE modify_stmt;

-- 3) Re-add the FK with ON DELETE SET NULL. Use a fixed name so future
--    migrations can find it deterministically.
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND constraint_name = 'fk_workorder_estimate'
      AND constraint_type = 'FOREIGN KEY'
);
SET @add_fk_sql := IF(@has_fk = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorder_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE add_fk_stmt FROM @add_fk_sql;
EXECUTE add_fk_stmt;
DEALLOCATE PREPARE add_fk_stmt;

-- 4) workorders.mileage_in (idempotent column add)
SET @has_mileage_in := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'mileage_in'
);
SET @add_mileage_in_sql := IF(@has_mileage_in = 0,
    'ALTER TABLE workorders ADD COLUMN mileage_in INT UNSIGNED NULL AFTER goa_billing_party',
    'SELECT 1');
PREPARE add_mileage_in_stmt FROM @add_mileage_in_sql;
EXECUTE add_mileage_in_stmt;
DEALLOCATE PREPARE add_mileage_in_stmt;

-- 5) workorders.mileage_out (idempotent column add)
SET @has_mileage_out := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'mileage_out'
);
SET @add_mileage_out_sql := IF(@has_mileage_out = 0,
    'ALTER TABLE workorders ADD COLUMN mileage_out INT UNSIGNED NULL AFTER mileage_in',
    'SELECT 1');
PREPARE add_mileage_out_stmt FROM @add_mileage_out_sql;
EXECUTE add_mileage_out_stmt;
DEALLOCATE PREPARE add_mileage_out_stmt;
