-- Phase 7.5 of docs/expansion-plan.md — external vendor repair logs.
--
-- Tracks repair work performed by third-party vendors (outsourced
-- engine rebuilds, tire shops, paint, towing, dealer warranty claims)
-- so the fleet cost picture is complete. Parallels the internal
-- workorder_items type split with labor_cost + parts_cost + other_cost
-- columns so the Phase 7.4 cost reports can union both sources into one
-- utilization picture when includeExternal=true.

CREATE TABLE IF NOT EXISTS fleet_external_repairs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fleet_unit_id INT UNSIGNED NOT NULL,
    vendor_name VARCHAR(120) NOT NULL,
    vendor_invoice_number VARCHAR(80) NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'repair',
    service_date DATE NOT NULL,
    description VARCHAR(500) NOT NULL,
    labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    parts_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    odometer_at_service INT UNSIGNED NULL,
    engine_hours_at_service DECIMAL(10,2) NULL,
    notes TEXT NULL,
    attachment_path VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fleet_ext_repair_unit_date (fleet_unit_id, service_date),
    INDEX idx_fleet_ext_repair_vendor (vendor_name),
    INDEX idx_fleet_ext_repair_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK guarded by fleet_units existence so re-runs and pre-Phase-7.1 DBs
-- don't fail.
SET @fleet_units_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'fleet_units'
);
SET @has_fk_fleet_ext_repair_unit := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'fleet_external_repairs' AND constraint_name = 'fk_fleet_ext_repair_unit'
);
SET @fk_fleet_ext_repair_unit_sql := IF(@fleet_units_exists > 0 AND @has_fk_fleet_ext_repair_unit = 0,
    'ALTER TABLE fleet_external_repairs ADD CONSTRAINT fk_fleet_ext_repair_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_fleet_ext_repair_unit_stmt FROM @fk_fleet_ext_repair_unit_sql;
EXECUTE fk_fleet_ext_repair_unit_stmt;
DEALLOCATE PREPARE fk_fleet_ext_repair_unit_stmt;
