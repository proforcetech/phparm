-- Migration: Inspection-to-Estimate Bridge
-- Adds failure threshold configuration and recommended services for inspection items

-- Add failure configuration to inspection items
ALTER TABLE inspection_items
    ADD COLUMN IF NOT EXISTS fail_threshold VARCHAR(100) NULL COMMENT 'Threshold that indicates failure: "no" for boolean, numeric value for scales',
    ADD COLUMN IF NOT EXISTS recommended_service_type_id INT UNSIGNED NULL COMMENT 'Default service type to suggest when item fails',
    ADD COLUMN IF NOT EXISTS estimated_labor_hours DECIMAL(5,2) NULL COMMENT 'Default labor hours for failed item repair',
    ADD COLUMN IF NOT EXISTS estimated_parts_cost DECIMAL(10,2) NULL COMMENT 'Estimated parts cost for failed item repair';

-- Track which inspection items have been converted to estimates
CREATE TABLE IF NOT EXISTS inspection_estimate_conversions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_report_id INT UNSIGNED NOT NULL,
    inspection_item_id INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NOT NULL,
    converted_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_iec_report (inspection_report_id),
    INDEX idx_iec_estimate (estimate_id),
    UNIQUE KEY uk_inspection_item_estimate (inspection_report_id, inspection_item_id, estimate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store inspection-specific recommendations for estimate creation
CREATE TABLE IF NOT EXISTS inspection_recommendations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_report_id INT UNSIGNED NOT NULL,
    report_item_id INT UNSIGNED NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    recommended_action TEXT NULL,
    estimated_cost DECIMAL(10,2) NULL,
    status ENUM('pending', 'added_to_estimate', 'declined', 'deferred') NOT NULL DEFAULT 'pending',
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ir_report (inspection_report_id),
    INDEX idx_ir_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
