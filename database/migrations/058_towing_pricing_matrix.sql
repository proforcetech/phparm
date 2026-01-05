-- Towing Pricing Matrix Tables
-- Provides configurable pricing for roadside assistance and towing services
-- based on service class (light, medium, heavy, motorcycle) and service type

-- Service Classes (e.g., light duty, medium duty, heavy duty, motorcycle)
CREATE TABLE IF NOT EXISTS towing_service_classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    weight_min INT UNSIGNED NULL COMMENT 'Minimum vehicle weight in lbs',
    weight_max INT UNSIGNED NULL COMMENT 'Maximum vehicle weight in lbs',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_towing_service_class_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service Types (e.g., tow, winch, jump start, tire change, fuel delivery, lockout)
CREATE TABLE IF NOT EXISTS towing_service_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NOT NULL COMMENT 'Short code for the service type',
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_towing_service_type_name (name),
    UNIQUE KEY uk_towing_service_type_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pricing Matrix - links service class + service type to fees
CREATE TABLE IF NOT EXISTS towing_price_matrix (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_class_id INT UNSIGNED NOT NULL,
    service_type_id INT UNSIGNED NOT NULL,
    rollout_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat fee to dispatch/respond',
    hookup_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fee for hooking up vehicle',
    onhook_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fee for loading vehicle onto truck',
    mileage_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-mile rate (loaded)',
    deadhead_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-mile rate (unloaded/return)',
    accident_cleanup_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Additional fee for accident scenes',
    winch_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fee for winching operations',
    storage_daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Daily storage rate',
    after_hours_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Multiplier for after-hours service',
    minimum_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minimum total charge for service',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL COMMENT 'Internal notes about this pricing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_towing_price_matrix (service_class_id, service_type_id),
    CONSTRAINT fk_towing_price_matrix_class FOREIGN KEY (service_class_id)
        REFERENCES towing_service_classes (id) ON DELETE CASCADE,
    CONSTRAINT fk_towing_price_matrix_type FOREIGN KEY (service_type_id)
        REFERENCES towing_service_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default service classes
INSERT INTO towing_service_classes (name, description, weight_min, weight_max, sort_order) VALUES
    ('Light Duty', 'Standard passenger vehicles, motorcycles, small trucks', NULL, 10000, 1),
    ('Medium Duty', 'Box trucks, RVs, small buses, large pickup trucks', 10001, 26000, 2),
    ('Heavy Duty', 'Semi-trucks, large buses, heavy equipment', 26001, NULL, 3),
    ('Motorcycle', 'Motorcycles and scooters', NULL, 1500, 4)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Seed default service types
INSERT INTO towing_service_types (name, code, description, sort_order) VALUES
    ('Standard Tow', 'TOW', 'Standard towing service', 1),
    ('Winch Out', 'WINCH', 'Vehicle recovery using winch', 2),
    ('Jump Start', 'JUMP', 'Battery jump start service', 3),
    ('Tire Change', 'TIRE', 'Flat tire replacement/change', 4),
    ('Fuel Delivery', 'FUEL', 'Emergency fuel delivery', 5),
    ('Lockout', 'LOCK', 'Vehicle lockout assistance', 6),
    ('Accident Recovery', 'ACCIDENT', 'Accident scene recovery and cleanup', 7),
    ('Flatbed Tow', 'FLATBED', 'Flatbed towing for specialty vehicles', 8)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Create indexes for performance
CREATE INDEX idx_towing_price_matrix_class ON towing_price_matrix (service_class_id);
CREATE INDEX idx_towing_price_matrix_type ON towing_price_matrix (service_type_id);
CREATE INDEX idx_towing_price_matrix_active ON towing_price_matrix (is_active);
