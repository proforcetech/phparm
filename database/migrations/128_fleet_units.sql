-- Phase 7.1 of docs/expansion-plan.md — fleet maintenance core schema.
--
-- Three tables land together because they form one cohesive unit:
--
--   * fleet_units — a company-owned vehicle/equipment record. Distinct
--     from customer_vehicles, which carry a single customer's personal
--     vehicle. Fleet units are commercial B2B assets owned by a company
--     (FK companies.id) — the same tenants that wear white-label themes
--     from Phase 6.8.
--
--   * fleet_unit_readings — append-only meter history (odometer in miles
--     and/or engine_hours). The fleet_units row carries a denormalized
--     current_* cache for fast list rendering, but this table is the
--     source of truth. Every reading is timestamped + attributed +
--     optionally linked to a workorder (so WO closeout can stamp the
--     meter as part of its flow).
--
--   * fleet_unit_assignments — append-only history of who's using the
--     unit. An assignment has a start (assigned_from) and optional end
--     (assigned_until NULL = currently active). Service layer enforces
--     "at most one active assignment per unit" by closing the prior
--     assignment in the same transaction that opens the new one.
--
-- Scope enforcement happens in the service — every fleet_units row
-- carries company_id, and the readings/assignments tables FK back to
-- fleet_units (no duplicate company_id; joins are cheap via the id FK).
--
-- VIN is UNIQUE across the platform when present so two companies can't
-- claim the same VIN. unit_number is UNIQUE within a company so "TRK-42"
-- can exist in every tenant without collision.

CREATE TABLE IF NOT EXISTS fleet_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    unit_number VARCHAR(40) NOT NULL,
    unit_type VARCHAR(32) NOT NULL DEFAULT 'truck',
    vin VARCHAR(30) NULL,
    year SMALLINT UNSIGNED NULL,
    make VARCHAR(120) NULL,
    model VARCHAR(120) NULL,
    trim VARCHAR(120) NULL,
    license_plate VARCHAR(30) NULL,
    home_site_id INT UNSIGNED NULL,
    meter_type VARCHAR(16) NOT NULL DEFAULT 'odometer',
    current_odometer INT UNSIGNED NULL,
    current_engine_hours DECIMAL(10,2) UNSIGNED NULL,
    odometer_last_read_at TIMESTAMP NULL DEFAULT NULL,
    engine_hours_last_read_at TIMESTAMP NULL DEFAULT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fleet_units_company_unit (company_id, unit_number),
    UNIQUE KEY uq_fleet_units_vin (vin),
    INDEX idx_fleet_units_company_status (company_id, status),
    INDEX idx_fleet_units_home_site (home_site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fleet_unit_readings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fleet_unit_id INT UNSIGNED NOT NULL,
    reading_type VARCHAR(16) NOT NULL,
    value DECIMAL(12,2) UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    source VARCHAR(24) NOT NULL DEFAULT 'manual',
    workorder_id INT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fleet_readings_unit_type_time (fleet_unit_id, reading_type, recorded_at),
    INDEX idx_fleet_readings_unit_created (fleet_unit_id, created_at),
    INDEX idx_fleet_readings_workorder (workorder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fleet_unit_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fleet_unit_id INT UNSIGNED NOT NULL,
    assignment_type VARCHAR(24) NOT NULL DEFAULT 'driver',
    assigned_user_id INT UNSIGNED NULL,
    assigned_site_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    assigned_from DATETIME NOT NULL,
    assigned_until DATETIME NULL DEFAULT NULL,
    notes VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fleet_assign_unit_until (fleet_unit_id, assigned_until),
    INDEX idx_fleet_assign_unit_from (fleet_unit_id, assigned_from),
    INDEX idx_fleet_assign_user (assigned_user_id),
    INDEX idx_fleet_assign_site (assigned_site_id),
    INDEX idx_fleet_assign_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent FK installs — companies is required (core schema); sites
-- and customers are nullable references so we SET NULL on delete; the
-- readings + assignments FK back to fleet_units with CASCADE so retiring
-- a unit history-removes cleanly when the parent row is purged.

SET @has_fk_company := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_units'
      AND constraint_name = 'fk_fleet_units_company'
);
SET @companies_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'companies'
);
SET @fk_company_sql := IF(
    @has_fk_company = 0 AND @companies_exists = 1,
    'ALTER TABLE fleet_units ADD CONSTRAINT fk_fleet_units_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s1 FROM @fk_company_sql; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @has_fk_site := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_units'
      AND constraint_name = 'fk_fleet_units_home_site'
);
SET @sites_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sites'
);
SET @fk_site_sql := IF(
    @has_fk_site = 0 AND @sites_exists = 1,
    'ALTER TABLE fleet_units ADD CONSTRAINT fk_fleet_units_home_site FOREIGN KEY (home_site_id) REFERENCES sites (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE s2 FROM @fk_site_sql; EXECUTE s2; DEALLOCATE PREPARE s2;

SET @has_fk_read_unit := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_unit_readings'
      AND constraint_name = 'fk_fleet_readings_unit'
);
SET @fk_read_unit_sql := IF(
    @has_fk_read_unit = 0,
    'ALTER TABLE fleet_unit_readings ADD CONSTRAINT fk_fleet_readings_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s3 FROM @fk_read_unit_sql; EXECUTE s3; DEALLOCATE PREPARE s3;

SET @has_fk_assign_unit := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_unit_assignments'
      AND constraint_name = 'fk_fleet_assign_unit'
);
SET @fk_assign_unit_sql := IF(
    @has_fk_assign_unit = 0,
    'ALTER TABLE fleet_unit_assignments ADD CONSTRAINT fk_fleet_assign_unit FOREIGN KEY (fleet_unit_id) REFERENCES fleet_units (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE s4 FROM @fk_assign_unit_sql; EXECUTE s4; DEALLOCATE PREPARE s4;

SET @has_fk_assign_site := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_unit_assignments'
      AND constraint_name = 'fk_fleet_assign_site'
);
SET @fk_assign_site_sql := IF(
    @has_fk_assign_site = 0 AND @sites_exists = 1,
    'ALTER TABLE fleet_unit_assignments ADD CONSTRAINT fk_fleet_assign_site FOREIGN KEY (assigned_site_id) REFERENCES sites (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE s5 FROM @fk_assign_site_sql; EXECUTE s5; DEALLOCATE PREPARE s5;

SET @has_fk_assign_customer := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'fleet_unit_assignments'
      AND constraint_name = 'fk_fleet_assign_customer'
);
SET @customers_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'customers'
);
SET @fk_assign_customer_sql := IF(
    @has_fk_assign_customer = 0 AND @customers_exists = 1,
    'ALTER TABLE fleet_unit_assignments ADD CONSTRAINT fk_fleet_assign_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE s6 FROM @fk_assign_customer_sql; EXECUTE s6; DEALLOCATE PREPARE s6;
