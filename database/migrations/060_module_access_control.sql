-- Migration: Module Access Control System
-- Adds module enablement settings and user groups for granular access control

-- Module settings at shop/tenant level
CREATE TABLE IF NOT EXISTS module_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config JSON NULL COMMENT 'Module-specific configuration options',
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_module_key (module_key),
    INDEX idx_enabled (enabled),
    CONSTRAINT fk_module_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User groups for granular permissions beyond roles
CREATE TABLE IF NOT EXISTS user_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description TEXT NULL,
    permissions JSON NOT NULL DEFAULT '[]' COMMENT 'Additional permissions granted to group members',
    disabled_modules JSON NOT NULL DEFAULT '[]' COMMENT 'Modules disabled for this group',
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-assign new users to this group',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_group_name (name),
    INDEX idx_is_default (is_default),
    CONSTRAINT fk_user_groups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: users belong to groups
CREATE TABLE IF NOT EXISTS user_group_members (
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id),
    INDEX idx_group_id (group_id),
    CONSTRAINT fk_ugm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugm_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugm_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log for module changes
CREATE TABLE IF NOT EXISTS module_access_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_type ENUM('module_toggle', 'group_create', 'group_update', 'group_delete', 'member_add', 'member_remove') NOT NULL,
    module_key VARCHAR(50) NULL,
    group_id INT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    performed_by INT UNSIGNED NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_type (action_type),
    INDEX idx_module_key (module_key),
    INDEX idx_group_id (group_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default module settings (all enabled by default)
INSERT IGNORE INTO module_settings (module_key, enabled, config) VALUES
    ('core', 1, '{"description": "Core shop operations - cannot be disabled"}'),
    ('estimates', 1, '{"description": "Estimates and quotes"}'),
    ('workorders', 1, '{"description": "Work orders and job tracking"}'),
    ('invoicing', 1, '{"description": "Invoicing and payments"}'),
    ('appointments', 1, '{"description": "Appointment scheduling"}'),
    ('inventory', 1, '{"description": "Parts and inventory management"}'),
    ('towing', 1, '{"description": "Towing, roadside assistance, and dispatch"}'),
    ('cms', 1, '{"description": "Website content management"}'),
    ('impound', 0, '{"description": "Impound and vehicle storage"}'),
    ('inspections', 1, '{"description": "Vehicle inspections"}'),
    ('warranty', 1, '{"description": "Warranty claims management"}'),
    ('time_tracking', 1, '{"description": "Technician time tracking"}'),
    ('messaging', 1, '{"description": "Customer messaging and notifications"}'),
    ('reminders', 1, '{"description": "Reminder campaigns"}'),
    ('customer_portal', 1, '{"description": "Customer self-service portal"}'),
    ('reports', 1, '{"description": "Reports and analytics"}');

-- Insert default user group
INSERT IGNORE INTO user_groups (name, description, permissions, disabled_modules, is_default) VALUES
    ('All Staff', 'Default group for all staff members', '[]', '[]', 1);
