-- Phase 8.4 of docs/expansion-plan.md: risk scoring + trend analysis.
--
-- One row per inspection report carrying a weighted risk score +
-- per-severity counts. The service layer upserts on report completion
-- (or on manual rescore) and the trend endpoints read this table
-- directly — no live aggregation against inspection_report_items, so
-- a fleet-level trend view stays cheap regardless of historical
-- inspection volume.
--
-- vehicle_id / customer_id / division_id are echoed from the report
-- (via its template for division) at scoring time so the trend
-- queries don't need a join back to inspection_reports +
-- inspection_templates on every read. If the underlying report is
-- ever reparented to another vehicle / customer, a rescore picks up
-- the new anchor via upsert.
--
-- All DDL idempotent. FKs guarded on referenced-table existence.

CREATE TABLE IF NOT EXISTS inspection_risk_scores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_report_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    division_id INT UNSIGNED NULL,
    total_score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
    failed_item_count INT UNSIGNED NOT NULL DEFAULT 0,
    critical_count INT UNSIGNED NOT NULL DEFAULT 0,
    high_count INT UNSIGNED NOT NULL DEFAULT 0,
    medium_count INT UNSIGNED NOT NULL DEFAULT 0,
    low_count INT UNSIGNED NOT NULL DEFAULT 0,
    compliance_tagged_count INT UNSIGNED NOT NULL DEFAULT 0,
    scored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scored_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_insp_risk_score_report (inspection_report_id),
    INDEX idx_insp_risk_score_vehicle (vehicle_id, scored_at),
    INDEX idx_insp_risk_score_division (division_id, scored_at),
    INDEX idx_insp_risk_score_customer (customer_id, scored_at),
    INDEX idx_insp_risk_score_level (risk_level, scored_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: inspection_report_id -> inspection_reports(id) CASCADE
SET @rep_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_risk_scores'
    AND constraint_name = 'fk_insp_risk_score_report');
SET @sql := IF(@rep_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_risk_scores ADD CONSTRAINT fk_insp_risk_score_report FOREIGN KEY (inspection_report_id) REFERENCES inspection_reports(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: vehicle_id -> vehicles(id) SET NULL
SET @veh_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'vehicles');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_risk_scores'
    AND constraint_name = 'fk_insp_risk_score_vehicle');
SET @sql := IF(@veh_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_risk_scores ADD CONSTRAINT fk_insp_risk_score_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: customer_id -> customers(id) SET NULL
SET @cust_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'customers');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_risk_scores'
    AND constraint_name = 'fk_insp_risk_score_customer');
SET @sql := IF(@cust_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_risk_scores ADD CONSTRAINT fk_insp_risk_score_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: division_id -> divisions(id) SET NULL
SET @div_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'divisions');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_risk_scores'
    AND constraint_name = 'fk_insp_risk_score_division');
SET @sql := IF(@div_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_risk_scores ADD CONSTRAINT fk_insp_risk_score_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: scored_by_user_id -> users(id) SET NULL
SET @user_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_risk_scores'
    AND constraint_name = 'fk_insp_risk_score_user');
SET @sql := IF(@user_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_risk_scores ADD CONSTRAINT fk_insp_risk_score_user FOREIGN KEY (scored_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
