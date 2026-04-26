-- Phase 8.3 of docs/expansion-plan.md: failed-item escalation rules.
--
-- While Phase 8.2 handles "machine-generates a quote" as the default
-- outcome of a failed inspection item, some failures also need a
-- human in the loop — a DOT officer has to sign off on a critical
-- brake finding, a manager has to approve continued use of a fleet
-- unit flagged for safety, etc. Phase 8.3 tracks those human-in-loop
-- obligations as first-class escalation records with acknowledgement
-- + resolution lifecycle + per-role routing.
--
-- Two tables:
--   1. inspection_escalation_rules — the rule vocabulary (who gets
--      notified when what severity + tag combination fails)
--   2. inspection_escalations — per-item records created by rule
--      evaluation, tracking assignee + status + acknowledgement +
--      resolution
--
-- All DDL idempotent. FKs guarded on referenced-table existence.

-- -----------------------------------------------------------------------------
-- 1. inspection_escalation_rules
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_escalation_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    trigger_severity VARCHAR(16) NOT NULL DEFAULT 'critical',
    compliance_tag_id INT UNSIGNED NULL,
    assign_to_user_id INT UNSIGNED NULL,
    assign_to_role VARCHAR(32) NULL,
    notify_via VARCHAR(16) NULL,
    notification_template VARCHAR(120) NULL,
    priority VARCHAR(16) NOT NULL DEFAULT 'normal',
    require_acknowledgment TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_insp_escalation_rule_division (division_id, is_active, sort_order),
    INDEX idx_insp_escalation_rule_tag (compliance_tag_id),
    INDEX idx_insp_escalation_rule_user (assign_to_user_id),
    INDEX idx_insp_escalation_rule_role (assign_to_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. inspection_escalations — per-item records
-- -----------------------------------------------------------------------------
-- UNIQUE (rule_id, inspection_report_id, inspection_report_item_id)
-- ensures a rule creates at most one escalation per failing item,
-- even if evaluateReport is called multiple times. Identical to the
-- idempotency pattern used in 8.2's auto_generation_runs.
CREATE TABLE IF NOT EXISTS inspection_escalations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    inspection_report_id INT UNSIGNED NOT NULL,
    inspection_report_item_id INT UNSIGNED NOT NULL,
    priority VARCHAR(16) NOT NULL DEFAULT 'normal',
    severity VARCHAR(16) NOT NULL DEFAULT 'high',
    assigned_to_user_id INT UNSIGNED NULL,
    assigned_to_role VARCHAR(32) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    notification_status VARCHAR(24) NULL,
    notification_error VARCHAR(500) NULL,
    acknowledged_by_user_id INT UNSIGNED NULL,
    acknowledged_at DATETIME NULL,
    resolved_by_user_id INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    resolution_note TEXT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_insp_escalation_rule_report_item (rule_id, inspection_report_id, inspection_report_item_id),
    INDEX idx_insp_escalation_report (inspection_report_id),
    INDEX idx_insp_escalation_status (status, priority),
    INDEX idx_insp_escalation_assignee (assigned_to_user_id, status),
    INDEX idx_insp_escalation_role_queue (assigned_to_role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. foreign keys (idempotent, guarded on referenced-table existence)
-- -----------------------------------------------------------------------------

-- Rules
SET @div_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'divisions');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalation_rules'
    AND constraint_name = 'fk_insp_escalation_rule_division');
SET @sql := IF(@div_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_escalation_rules ADD CONSTRAINT fk_insp_escalation_rule_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @tag_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_compliance_tags');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalation_rules'
    AND constraint_name = 'fk_insp_escalation_rule_tag');
SET @sql := IF(@tag_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_escalation_rules ADD CONSTRAINT fk_insp_escalation_rule_tag FOREIGN KEY (compliance_tag_id) REFERENCES inspection_compliance_tags(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @user_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalation_rules'
    AND constraint_name = 'fk_insp_escalation_rule_user');
SET @sql := IF(@user_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_escalation_rules ADD CONSTRAINT fk_insp_escalation_rule_user FOREIGN KEY (assign_to_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Escalations
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalations'
    AND constraint_name = 'fk_insp_escalation_rule');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE inspection_escalations ADD CONSTRAINT fk_insp_escalation_rule FOREIGN KEY (rule_id) REFERENCES inspection_escalation_rules(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @rep_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalations'
    AND constraint_name = 'fk_insp_escalation_report');
SET @sql := IF(@rep_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_escalations ADD CONSTRAINT fk_insp_escalation_report FOREIGN KEY (inspection_report_id) REFERENCES inspection_reports(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @item_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_report_items');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalations'
    AND constraint_name = 'fk_insp_escalation_report_item');
SET @sql := IF(@item_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_escalations ADD CONSTRAINT fk_insp_escalation_report_item FOREIGN KEY (inspection_report_item_id) REFERENCES inspection_report_items(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_escalations'
    AND constraint_name = 'fk_insp_escalation_assignee');
SET @sql := IF(@user_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_escalations ADD CONSTRAINT fk_insp_escalation_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
