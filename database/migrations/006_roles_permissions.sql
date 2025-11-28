-- Role permissions mapping
ALTER TABLE roles ADD UNIQUE KEY unique_role_name (name);

CREATE TABLE role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY role_permission_unique (role, permission),
    INDEX idx_role_permissions_role (role)
);

INSERT INTO roles (name, description) VALUES
    ('admin', 'Full control across all modules'),
    ('manager', 'Manage shop operations and reporting'),
    ('technician', 'Work estimates, inspections, and time entries'),
    ('customer', 'Customer portal access')
ON DUPLICATE KEY UPDATE description = VALUES(description);
