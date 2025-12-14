-- Custom roles table with JSON-based permissions
-- This supersedes the old role_permissions table with a more flexible approach
-- System roles (admin, manager, technician, customer) are pre-populated and protected

CREATE TABLE IF NOT EXISTS custom_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    description TEXT NULL,
    permissions JSON NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_is_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert existing system roles for backwards compatibility
INSERT INTO custom_roles (name, label, description, permissions, is_system) VALUES
('admin', 'Admin', 'Full control across all modules', JSON_ARRAY('*'), 1),
('manager', 'Manager', 'Manage shop operations, estimates, invoices, schedules, inventory', JSON_ARRAY('users.view', 'users.create', 'users.update', 'users.delete', 'users.invite', 'customers.*', 'vehicles.*', 'estimates.*', 'invoices.*', 'payments.*', 'appointments.*', 'inventory.*', 'inspections.*', 'warranty.*', 'reminders.*', 'bundles.*', 'time.*', 'credit.*', 'reports.view', 'settings.view', 'notifications.view', 'service_types.*', 'cms.*'), 1),
('technician', 'Technician', 'Work estimates, inspections, jobs, and time tracking', JSON_ARRAY('customers.view', 'vehicles.view', 'estimates.view', 'estimates.create', 'estimates.update', 'inspections.*', 'time.*', 'appointments.view', 'service_types.view', 'cms.pages.view', 'cms.pages.create', 'cms.pages.update', 'cms.pages.delete', 'cms.menus.view', 'cms.menus.create', 'cms.menus.update', 'cms.menus.delete', 'cms.media.view', 'cms.media.create', 'cms.media.update', 'cms.media.delete', 'cms.components.view', 'cms.components.create', 'cms.components.update', 'cms.components.delete', 'cms.dashboard.view', 'cms.templates.view'), 1),
('customer', 'Customer', 'Customer portal scoped to their profile and documents', JSON_ARRAY('portal.profile', 'portal.vehicles', 'portal.estimates', 'portal.invoices', 'portal.warranty', 'portal.reminders'), 1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    permissions = VALUES(permissions);
