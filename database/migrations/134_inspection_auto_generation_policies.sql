-- Phase 8.2 of docs/expansion-plan.md: deficiency → auto-WO/quote
-- generation.
--
-- Introduces a policy-driven auto-generation layer that fires on
-- inspection completion: when a report has failed items matching a
-- policy's severity + compliance-tag filter, the bridge service
-- creates an estimate (or sub-estimate on the open workorder) without
-- manual intervention.
--
-- Two tables:
--   1. inspection_auto_generation_policies — the rules (severity
--      threshold, optional compliance tag filter, target kind)
--   2. inspection_auto_generation_runs — idempotency marker + audit
--      trail of which policies fired on which reports
--
-- All DDL is idempotent. FK installs are guarded on referenced-table
-- existence so older inspection schemas don't fail.

-- -----------------------------------------------------------------------------
-- 1. inspection_auto_generation_policies
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_auto_generation_policies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    trigger_severity VARCHAR(16) NOT NULL DEFAULT 'high',
    compliance_tag_id INT UNSIGNED NULL,
    target_kind VARCHAR(32) NOT NULL DEFAULT 'auto',
    min_failed_items INT UNSIGNED NOT NULL DEFAULT 1,
    require_customer_approval TINYINT(1) NOT NULL DEFAULT 1,
    auto_assign_to_technician_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_insp_auto_gen_policy_division (division_id, is_active, sort_order),
    INDEX idx_insp_auto_gen_policy_tag (compliance_tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. inspection_auto_generation_runs — idempotency marker
-- -----------------------------------------------------------------------------
-- UNIQUE (policy_id, inspection_report_id) ensures a policy fires at
-- most once per report. Re-running evaluateReport is a no-op.
CREATE TABLE IF NOT EXISTS inspection_auto_generation_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_id INT UNSIGNED NOT NULL,
    inspection_report_id INT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'triggered',
    estimate_id INT UNSIGNED NULL,
    sub_estimate_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    items_generated INT UNSIGNED NOT NULL DEFAULT 0,
    note TEXT NULL,
    triggered_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_insp_auto_gen_run_policy_report (policy_id, inspection_report_id),
    INDEX idx_insp_auto_gen_run_report (inspection_report_id),
    INDEX idx_insp_auto_gen_run_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. foreign keys (idempotent, guarded on referenced-table existence)
-- -----------------------------------------------------------------------------

-- fk: inspection_auto_generation_policies.division_id -> divisions.id SET NULL
SET @div_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'divisions');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_auto_generation_policies'
    AND constraint_name = 'fk_insp_auto_gen_policy_division');
SET @sql := IF(@div_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_auto_generation_policies ADD CONSTRAINT fk_insp_auto_gen_policy_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_auto_generation_policies.compliance_tag_id -> inspection_compliance_tags.id SET NULL
SET @tag_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_compliance_tags');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_auto_generation_policies'
    AND constraint_name = 'fk_insp_auto_gen_policy_tag');
SET @sql := IF(@tag_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_auto_generation_policies ADD CONSTRAINT fk_insp_auto_gen_policy_tag FOREIGN KEY (compliance_tag_id) REFERENCES inspection_compliance_tags(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_auto_generation_policies.auto_assign_to_technician_id -> users.id SET NULL
SET @user_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_auto_generation_policies'
    AND constraint_name = 'fk_insp_auto_gen_policy_technician');
SET @sql := IF(@user_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_auto_generation_policies ADD CONSTRAINT fk_insp_auto_gen_policy_technician FOREIGN KEY (auto_assign_to_technician_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_auto_generation_runs.policy_id -> policies.id CASCADE
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_auto_generation_runs'
    AND constraint_name = 'fk_insp_auto_gen_run_policy');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE inspection_auto_generation_runs ADD CONSTRAINT fk_insp_auto_gen_run_policy FOREIGN KEY (policy_id) REFERENCES inspection_auto_generation_policies(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_auto_generation_runs.inspection_report_id -> inspection_reports.id CASCADE
SET @rep_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_auto_generation_runs'
    AND constraint_name = 'fk_insp_auto_gen_run_report');
SET @sql := IF(@rep_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_auto_generation_runs ADD CONSTRAINT fk_insp_auto_gen_run_report FOREIGN KEY (inspection_report_id) REFERENCES inspection_reports(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
