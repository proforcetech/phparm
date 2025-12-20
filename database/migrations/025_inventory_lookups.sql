CREATE TABLE IF NOT EXISTS inventory_lookups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_parts_supplier TINYINT(1) DEFAULT 0,
    INDEX idx_inventory_lookups_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
