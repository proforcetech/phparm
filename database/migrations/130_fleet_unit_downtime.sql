-- Phase 7.3 of docs/expansion-plan.md — fleet downtime tracking.
--
-- A "downtime" is a contiguous window where a fleet unit was not
-- operational: breakdown, scheduled maintenance, accident, or other.
-- Opening a downtime flips fleet_units.status to out_of_service; closing
-- the last open window flips it back to active (unless the unit was
-- manually retired, which the service checks before re-enabling).
--
-- Shape decisions:
--
--   * started_at + ended_at DATETIMEs, ended_at NULL = currently open.
--     The service enforces at-most-one-open-window per unit by
--     transactionally closing any prior open row before opening a new
--     one — same close-then-open pattern as fleet_unit_assignments
--     (Phase 7.1) so concurrent staff can race without leaving two
--     "currently broken-down" rows.
--
--   * reason is a VARCHAR(24) rather than an ENUM so the catalog can
--     grow by data migration, not DDL. ALLOWED_REASONS on the model
--     enforces the domain at the service layer.
--
--   * FK ON DELETE CASCADE to fleet_units — if a unit is hard-purged
--     (rare; normally we soft-retire) the downtime history goes with
--     it. Soft-retire leaves history intact.

CREATE TABLE IF NOT EXISTS fleet_unit_downtime (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fleet_unit_id INT UNSIGNED NOT NULL,
    reason VARCHAR(24) NOT NULL DEFAULT 'breakdown',
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL DEFAULT NULL,
    notes VARCHAR(1000) NULL,
    started_by_user_id INT UNSIGNED NOT NULL,
    ended_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Per-unit "is there an open window?" lookup goes through this
    -- index first (fleet_unit_id + ended_at=NULL).
    INDEX idx_fleet_downtime_unit_open (fleet_unit_id, ended_at),
    INDEX idx_fleet_downtime_unit_started (fleet_unit_id, started_at),
    INDEX idx_fleet_downtime_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent FK install back to fleet_units.
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_unit_downtime'
      AND constraint_name = 'fk_fleet_downtime_unit'
);
SET @fleet_units_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'fleet_units'
);
SET @fk_sql := IF(
    @has_fk = 0 AND @fleet_units_exists = 1,
    'ALTER TABLE fleet_unit_downtime ADD CONSTRAINT fk_fleet_downtime_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s FROM @fk_sql; EXECUTE s; DEALLOCATE PREPARE s;
