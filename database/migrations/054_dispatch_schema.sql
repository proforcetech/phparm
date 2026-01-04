-- Dispatch schema tables for driver profiles, equipment, shifts, and requirements

CREATE TABLE IF NOT EXISTS driver_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    availability_status VARCHAR(40) NOT NULL DEFAULT 'available',
    certifications JSON NULL,
    base_latitude DECIMAL(10,6) NULL,
    base_longitude DECIMAL(10,6) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_driver_profiles_user (user_id),
    INDEX idx_driver_profiles_availability (availability_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS truck_equipment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    equipment_class VARCHAR(60) NOT NULL,
    capacity DECIMAL(10,2) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_truck_equipment_driver (driver_profile_id),
    INDEX idx_truck_equipment_class (equipment_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    shift_start DATETIME NOT NULL,
    shift_end DATETIME NOT NULL,
    minutes_worked INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_driver_shifts_driver (driver_profile_id),
    INDEX idx_driver_shifts_window (shift_start, shift_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_driver_profiles_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_profiles' AND constraint_name = 'fk_driver_profiles_user'
);
SET @has_driver_profiles_user_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'driver_profiles'
      AND c.column_name = 'user_id'
);
SET @fk_driver_profiles_user_sql := IF(@has_fk_driver_profiles_user = 0 AND @has_driver_profiles_user_match = 1,
    'ALTER TABLE driver_profiles ADD CONSTRAINT fk_driver_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_profiles_user_stmt FROM @fk_driver_profiles_user_sql;
EXECUTE fk_driver_profiles_user_stmt;
DEALLOCATE PREPARE fk_driver_profiles_user_stmt;

SET @has_fk_truck_equipment_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'truck_equipment' AND constraint_name = 'fk_truck_equipment_driver'
);
SET @has_truck_equipment_driver_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'driver_profiles'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'truck_equipment'
      AND c.column_name = 'driver_profile_id'
);
SET @fk_truck_equipment_driver_sql := IF(@has_fk_truck_equipment_driver = 0 AND @has_truck_equipment_driver_match = 1,
    'ALTER TABLE truck_equipment ADD CONSTRAINT fk_truck_equipment_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_truck_equipment_driver_stmt FROM @fk_truck_equipment_driver_sql;
EXECUTE fk_truck_equipment_driver_stmt;
DEALLOCATE PREPARE fk_truck_equipment_driver_stmt;

SET @has_fk_driver_shifts_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_shifts' AND constraint_name = 'fk_driver_shifts_driver'
);
SET @has_driver_shifts_driver_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'driver_profiles'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'driver_shifts'
      AND c.column_name = 'driver_profile_id'
);
SET @fk_driver_shifts_driver_sql := IF(@has_fk_driver_shifts_driver = 0 AND @has_driver_shifts_driver_match = 1,
    'ALTER TABLE driver_shifts ADD CONSTRAINT fk_driver_shifts_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_shifts_driver_stmt FROM @fk_driver_shifts_driver_sql;
EXECUTE fk_driver_shifts_driver_stmt;
DEALLOCATE PREPARE fk_driver_shifts_driver_stmt;

CREATE TABLE IF NOT EXISTS dispatch_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispatch_reference VARCHAR(120) NULL,
    scheduled_start DATETIME NULL,
    estimated_duration_hours DECIMAL(6,2) NULL,
    required_capacity DECIMAL(10,2) NULL,
    required_equipment_class VARCHAR(60) NULL,
    required_certifications JSON NULL,
    pickup_latitude DECIMAL(10,6) NULL,
    pickup_longitude DECIMAL(10,6) NULL,
    dropoff_latitude DECIMAL(10,6) NULL,
    dropoff_longitude DECIMAL(10,6) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dispatch_requirements_reference (dispatch_reference),
    INDEX idx_dispatch_requirements_schedule (scheduled_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
