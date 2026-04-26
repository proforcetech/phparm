-- Phase 10.1 of docs/expansion-plan.md: Subcontractor management.
--
-- A field-service shop frequently augments in-house techs with outside trades
-- (electricians, plumbers, HVAC specialists). The work needs to be tracked on
-- the originating work order, but the vendor is a first-class record so we
-- can manage insurance, terms, payouts, and performance ratings over time.
--
-- Three tables:
--   1) subcontractors           — vendor master record (W9/insurance/terms).
--   2) subcontractor_divisions  — M2M of which divisions a sub is approved
--                                 to work for (so a manager can scope filters).
--   3) subcontractor_assignments — N:1 of an assignment on a single work order.
--                                  Tracks state (pending → accepted → ... → done)
--                                  and a closeout rating + cost reconciliation.
--
-- All DDL idempotent. FKs to divisions / workorders / users are guarded on
-- the referenced table existing so this migration runs cleanly on a
-- partially-set-up DB or in a SQLite test harness.

CREATE TABLE IF NOT EXISTS subcontractors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(160) NOT NULL,
    contact_name VARCHAR(160) NULL,
    email VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    address_line1 VARCHAR(200) NULL,
    address_line2 VARCHAR(200) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(40) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(60) NULL,
    tax_id VARCHAR(40) NULL,
    payment_terms VARCHAR(40) NULL,
    hourly_rate_cents INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    insurance_expires_at DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subcontractors_status (status),
    INDEX idx_subcontractors_insurance (insurance_expires_at),
    INDEX idx_subcontractors_company_name (company_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subcontractor_divisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subcontractor_id INT UNSIGNED NOT NULL,
    division_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subcontractor_divisions (subcontractor_id, division_id),
    INDEX idx_subcontractor_divisions_division (division_id),
    CONSTRAINT fk_subcontractor_divisions_sub FOREIGN KEY (subcontractor_id)
        REFERENCES subcontractors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: subcontractor_divisions.division_id -> divisions(id) CASCADE
SET @div_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'divisions');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_divisions'
    AND constraint_name = 'fk_subcontractor_divisions_division');
SET @sql := IF(@div_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_divisions ADD CONSTRAINT fk_subcontractor_divisions_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS subcontractor_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subcontractor_id INT UNSIGNED NOT NULL,
    workorder_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    description TEXT NULL,
    internal_notes TEXT NULL,
    estimated_cost_cents INT UNSIGNED NULL,
    actual_cost_cents INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    declined_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    assigned_by_user_id INT UNSIGNED NULL,
    rating TINYINT UNSIGNED NULL,
    rating_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subassign_subcontractor (subcontractor_id),
    INDEX idx_subassign_workorder (workorder_id),
    INDEX idx_subassign_status (status),
    INDEX idx_subassign_assigned_by (assigned_by_user_id),
    CONSTRAINT fk_subassign_sub FOREIGN KEY (subcontractor_id)
        REFERENCES subcontractors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: subcontractor_assignments.workorder_id -> workorders(id) CASCADE
SET @wo_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_assignments'
    AND constraint_name = 'fk_subassign_workorder');
SET @sql := IF(@wo_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_assignments ADD CONSTRAINT fk_subassign_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: subcontractor_assignments.assigned_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_assignments'
    AND constraint_name = 'fk_subassign_assigned_by');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_assignments ADD CONSTRAINT fk_subassign_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
