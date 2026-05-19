-- =============================================================================
-- Migration 195 — PM plan target kind
--
-- The PM plan UI lets managers choose whether a plan applies to site assets
-- or fleet units. Persist that selection on the plan template so schedules can
-- infer the intended target family.
-- =============================================================================

SET @pm_plans_table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_plans'
);

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_plans'
      AND column_name = 'target_kind'
);
SET @sql := IF(@pm_plans_table_exists > 0 AND @has_col = 0,
    'ALTER TABLE pm_plans ADD COLUMN target_kind VARCHAR(30) NOT NULL DEFAULT ''site_asset'' AFTER division_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_plans'
      AND index_name = 'idx_pm_plans_target_kind'
);
SET @sql := IF(@pm_plans_table_exists > 0 AND @has_index = 0,
    'CREATE INDEX idx_pm_plans_target_kind ON pm_plans (target_kind)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
