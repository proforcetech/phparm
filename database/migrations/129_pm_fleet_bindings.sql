-- Phase 7.2 of docs/expansion-plan.md — fleet-specific PM inheritance.
--
-- Layers fleet inheritance on top of the Phase 5.1 PM infrastructure.
-- Two shape changes:
--
--   1. New table pm_fleet_bindings — binds a pm_plan to either a single
--      fleet_unit (scope_type='unit') or an entire unit_type such as
--      'truck' / 'van' (scope_type='unit_type'). Unit-specific bindings
--      win over unit_type bindings at resolve time so a tenant can say
--      "every truck gets quarterly safety inspection" AND "truck #17
--      additionally gets brake service every 10k miles".
--
--   2. Add fleet_unit_id nullable column + index + FK to pm_schedules
--      so a generated schedule can target a fleet unit the same way it
--      already targets a (site, asset). The existing site_id / asset_id
--      columns stay untouched — fleet units occupy their own scope slot
--      rather than overloading asset_id.
--
-- Meter-driven inheritance reuses Phase 5.2's 'meter' frequency_kind +
-- advanceForReading(). When FleetUnitService::recordReading commits a
-- new odometer or engine-hours reading, the PmFleetInheritanceService
-- hook walks active meter schedules for that unit and auto-advances
-- next_due_at through the existing frequency engine — no new scheduling
-- logic, just a new entry point.

CREATE TABLE IF NOT EXISTS pm_fleet_bindings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    -- scope_type='unit' pins to fleet_unit_id; 'unit_type' pins to unit_type.
    scope_type VARCHAR(16) NOT NULL,
    fleet_unit_id INT UNSIGNED NULL,
    unit_type VARCHAR(32) NULL,
    -- Same frequency shape as pm_schedules (Phase 5.1) so the binding
    -- can describe either meter cadence ('every 5000 miles') or a
    -- calendar/date cadence ('every 90 days'). Bindings at unit_type
    -- scope almost always use meter for fleet.
    frequency_kind VARCHAR(30) NOT NULL DEFAULT 'meter',
    frequency_config JSON NULL,
    lead_time_days INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_fleet_bindings_company (company_id, scope_type, is_active),
    INDEX idx_pm_fleet_bindings_unit (fleet_unit_id),
    INDEX idx_pm_fleet_bindings_type (unit_type),
    INDEX idx_pm_fleet_bindings_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent column add: fleet_unit_id on pm_schedules.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_schedules'
      AND column_name = 'fleet_unit_id'
);
SET @add_col_sql := IF(
    @has_col = 0,
    'ALTER TABLE pm_schedules ADD COLUMN fleet_unit_id INT UNSIGNED NULL AFTER asset_id',
    'SELECT 1'
);
PREPARE s FROM @add_col_sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Idempotent index add for fleet_unit_id (hot path: listForUnit read).
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_schedules'
      AND index_name = 'idx_pm_schedules_fleet_unit'
);
SET @add_idx_sql := IF(
    @has_idx = 0,
    'ALTER TABLE pm_schedules ADD INDEX idx_pm_schedules_fleet_unit (fleet_unit_id)',
    'SELECT 1'
);
PREPARE s FROM @add_idx_sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Idempotent FK installs.

SET @fleet_units_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'fleet_units'
);

SET @has_fk_binding_plan := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_fleet_bindings'
      AND constraint_name = 'fk_pm_fleet_bindings_plan'
);
SET @fk_binding_plan_sql := IF(
    @has_fk_binding_plan = 0,
    'ALTER TABLE pm_fleet_bindings ADD CONSTRAINT fk_pm_fleet_bindings_plan FOREIGN KEY (plan_id) REFERENCES pm_plans (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s FROM @fk_binding_plan_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk_binding_unit := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_fleet_bindings'
      AND constraint_name = 'fk_pm_fleet_bindings_unit'
);
SET @fk_binding_unit_sql := IF(
    @has_fk_binding_unit = 0 AND @fleet_units_exists = 1,
    'ALTER TABLE pm_fleet_bindings ADD CONSTRAINT fk_pm_fleet_bindings_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s FROM @fk_binding_unit_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk_sched_unit := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'pm_schedules'
      AND constraint_name = 'fk_pm_schedules_fleet_unit'
);
SET @fk_sched_unit_sql := IF(
    @has_fk_sched_unit = 0 AND @fleet_units_exists = 1,
    'ALTER TABLE pm_schedules ADD CONSTRAINT fk_pm_schedules_fleet_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s FROM @fk_sched_unit_sql; EXECUTE s; DEALLOCATE PREPARE s;
