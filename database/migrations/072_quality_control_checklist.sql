-- Migration: Quality Control (QC) Checklist
-- Implements QC checklists that must be completed before transitioning from repair complete to invoicing

-- QC Templates (reusable checklist definitions)
CREATE TABLE IF NOT EXISTS qc_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    service_type_id INT UNSIGNED NULL COMMENT 'Optional: link to specific service type',
    is_default TINYINT(1) DEFAULT 0 COMMENT 'If true, applies to all workorders without specific template',
    active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_qct_service_type (service_type_id),
    INDEX idx_qct_default (is_default),
    INDEX idx_qct_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC Template Items (checklist items within a template)
CREATE TABLE IF NOT EXISTS qc_template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) NULL COMMENT 'e.g., Safety, Cleanliness, Functionality, Documentation',
    required TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES qc_templates(id) ON DELETE CASCADE,
    INDEX idx_qcti_template (template_id),
    INDEX idx_qcti_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC Checks (completed QC for a workorder)
CREATE TABLE IF NOT EXISTS qc_checks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'in_progress', 'passed', 'failed', 'passed_with_notes') NOT NULL DEFAULT 'pending',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    overall_notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_workorder_template (workorder_id, template_id),
    INDEX idx_qcc_workorder (workorder_id),
    INDEX idx_qcc_status (status),
    INDEX idx_qcc_completed_by (completed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC Check Items (individual item responses)
CREATE TABLE IF NOT EXISTS qc_check_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qc_check_id INT UNSIGNED NOT NULL,
    template_item_id INT UNSIGNED NOT NULL,
    passed TINYINT(1) NULL COMMENT 'NULL = not checked, 0 = failed, 1 = passed',
    notes TEXT NULL,
    checked_by INT UNSIGNED NULL,
    checked_at DATETIME NULL,
    FOREIGN KEY (qc_check_id) REFERENCES qc_checks(id) ON DELETE CASCADE,
    FOREIGN KEY (template_item_id) REFERENCES qc_template_items(id) ON DELETE CASCADE,
    INDEX idx_qcci_check (qc_check_id),
    INDEX idx_qcci_passed (passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings for QC enforcement
INSERT INTO settings (`key`, `group`, `type`, `value`, description, created_at, updated_at)
VALUES
    ('qc_enabled', 'workflow', 'boolean', 'true', 'Enable QC checklist requirement before invoicing', NOW(), NOW()),
    ('qc_auto_assign_template', 'workflow', 'boolean', 'true', 'Automatically assign default QC template to workorders', NOW(), NOW()),
    ('qc_required_for_invoice', 'workflow', 'boolean', 'true', 'Require QC pass before converting workorder to invoice', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Insert a default QC template with common items
INSERT INTO qc_templates (name, description, is_default, active, created_at, updated_at)
VALUES ('Standard QC Checklist', 'Default quality control checklist for all repairs', 1, 1, NOW(), NOW());

SET @default_template_id = LAST_INSERT_ID();

INSERT INTO qc_template_items (template_id, label, description, category, required, display_order) VALUES
(@default_template_id, 'All repairs completed per work order', 'Verify all work items marked as complete', 'Completion', 1, 1),
(@default_template_id, 'Parts properly installed', 'Check that all new parts are correctly installed', 'Functionality', 1, 2),
(@default_template_id, 'No fluid leaks', 'Inspect for oil, coolant, brake fluid, or other leaks', 'Safety', 1, 3),
(@default_template_id, 'Test drive completed (if applicable)', 'Verify vehicle operation during road test', 'Functionality', 0, 4),
(@default_template_id, 'Warning lights cleared', 'Confirm no dashboard warning lights are on', 'Functionality', 1, 5),
(@default_template_id, 'Vehicle exterior cleaned', 'Vehicle washed or wiped down before return', 'Cleanliness', 0, 6),
(@default_template_id, 'Interior clean and free of debris', 'Floor mats and seats clean, no tools left behind', 'Cleanliness', 1, 7),
(@default_template_id, 'Old parts available for customer (if requested)', 'Collect replaced parts for customer inspection', 'Documentation', 0, 8),
(@default_template_id, 'Work order documentation complete', 'All notes, times, and parts recorded accurately', 'Documentation', 1, 9),
(@default_template_id, 'Customer communication notes added', 'Any verbal agreements or instructions documented', 'Documentation', 0, 10);
