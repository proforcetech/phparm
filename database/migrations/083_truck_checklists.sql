-- Migration: Truck Checklists (Pre-trip/Post-trip)
-- Adds checklist templates, entries, and driver shift requirements.

CREATE TABLE IF NOT EXISTS truck_checklist_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    checklist_type ENUM('pre_trip', 'post_trip') NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tct_type (checklist_type),
    INDEX idx_tct_default (is_default),
    INDEX idx_tct_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS truck_checklist_template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    required TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES truck_checklist_templates(id) ON DELETE CASCADE,
    INDEX idx_tcti_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS truck_checklist_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    driver_shift_id INT UNSIGNED NULL,
    template_id INT UNSIGNED NOT NULL,
    checklist_type ENUM('pre_trip', 'post_trip') NOT NULL,
    status ENUM('completed') NOT NULL DEFAULT 'completed',
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tce_driver (driver_profile_id),
    INDEX idx_tce_shift (driver_shift_id),
    INDEX idx_tce_type (checklist_type),
    INDEX idx_tce_completed_at (completed_at),
    FOREIGN KEY (template_id) REFERENCES truck_checklist_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign keys separately (allows graceful handling if tables don't exist)
SET @has_driver_profiles = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'driver_profiles');
SET @has_driver_shifts = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'driver_shifts');

ALTER TABLE truck_checklist_entries
    ADD CONSTRAINT fk_tce_driver_profile FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles(id) ON DELETE CASCADE;

ALTER TABLE truck_checklist_entries
    ADD CONSTRAINT fk_tce_driver_shift FOREIGN KEY (driver_shift_id) REFERENCES driver_shifts(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS truck_checklist_entry_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id INT UNSIGNED NOT NULL,
    template_item_id INT UNSIGNED NOT NULL,
    response ENUM('pass', 'fail', 'na') NULL,
    notes TEXT NULL,
    checked_by INT UNSIGNED NULL,
    checked_at DATETIME NULL,
    FOREIGN KEY (entry_id) REFERENCES truck_checklist_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (template_item_id) REFERENCES truck_checklist_template_items(id) ON DELETE CASCADE,
    INDEX idx_tcei_entry (entry_id),
    INDEX idx_tcei_response (response)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE driver_shifts
    ADD COLUMN pre_trip_checklist_id INT UNSIGNED NULL,
    ADD COLUMN post_trip_checklist_id INT UNSIGNED NULL,
    ADD INDEX idx_driver_shifts_pre_trip (pre_trip_checklist_id),
    ADD INDEX idx_driver_shifts_post_trip (post_trip_checklist_id),
    ADD CONSTRAINT fk_driver_shifts_pre_trip FOREIGN KEY (pre_trip_checklist_id) REFERENCES truck_checklist_entries(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_driver_shifts_post_trip FOREIGN KEY (post_trip_checklist_id) REFERENCES truck_checklist_entries(id) ON DELETE SET NULL;

-- Default templates
INSERT INTO truck_checklist_templates (name, description, checklist_type, is_default, active, created_at, updated_at)
VALUES
    ('Standard Pre-Trip Checklist', 'Default pre-trip checklist for tow trucks', 'pre_trip', 1, 1, NOW(), NOW()),
    ('Standard Post-Trip Checklist', 'Default post-trip checklist for tow trucks', 'post_trip', 1, 1, NOW(), NOW());

SET @pre_trip_template_id = (SELECT id FROM truck_checklist_templates WHERE checklist_type = 'pre_trip' AND is_default = 1 LIMIT 1);
SET @post_trip_template_id = (SELECT id FROM truck_checklist_templates WHERE checklist_type = 'post_trip' AND is_default = 1 LIMIT 1);

INSERT INTO truck_checklist_template_items (template_id, label, description, required, display_order) VALUES
(@pre_trip_template_id, 'Check tires (tread/pressure)', 'Inspect all tires for proper inflation and tread wear', 1, 1),
(@pre_trip_template_id, 'Lights & signals operational', 'Headlights, brake lights, turn signals, hazard lights', 1, 2),
(@pre_trip_template_id, 'Hydraulic system check', 'Verify hydraulics are functioning and leak-free', 1, 3),
(@pre_trip_template_id, 'Winch & cables inspected', 'Inspect winch operation and cable condition', 1, 4),
(@pre_trip_template_id, 'Safety equipment onboard', 'Cones, flares, fire extinguisher, PPE', 1, 5),
(@pre_trip_template_id, 'Fuel level sufficient', 'Verify fuel level for scheduled shift', 1, 6);

INSERT INTO truck_checklist_template_items (template_id, label, description, required, display_order) VALUES
(@post_trip_template_id, 'Vehicle damage noted', 'Record any new damage or issues', 1, 1),
(@post_trip_template_id, 'Equipment cleaned & secured', 'Secure chains, straps, and tools', 1, 2),
(@post_trip_template_id, 'Fuel level recorded', 'Record remaining fuel level', 1, 3),
(@post_trip_template_id, 'Odometer recorded', 'Log ending mileage', 1, 4),
(@post_trip_template_id, 'Issues reported to dispatch', 'Report any incidents or maintenance needs', 1, 5);
