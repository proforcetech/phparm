CREATE TABLE IF NOT EXISTS availability_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NULL,
    holiday_date DATE NULL,
    label VARCHAR(160) NULL,
    opens_at TIME NULL,
    closes_at TIME NULL,
    slot_minutes INT NOT NULL DEFAULT 30,
    buffer_minutes INT NOT NULL DEFAULT 0,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_availability_day (day_of_week),
    INDEX idx_availability_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modify existing columns
ALTER TABLE appointments
    MODIFY customer_id INT UNSIGNED NULL,
    MODIFY vehicle_id INT UNSIGNED NULL;

-- Check and add created_at column
SET @has_created_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'created_at'
);
SET @created_at_sql := IF(@has_created_at = 0,
    'ALTER TABLE appointments ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER notes', 'SELECT 1');
PREPARE created_at_stmt FROM @created_at_sql;
EXECUTE created_at_stmt;
DEALLOCATE PREPARE created_at_stmt;

-- Check and add updated_at column
SET @has_updated_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'updated_at'
);
SET @updated_at_sql := IF(@has_updated_at = 0,
    'ALTER TABLE appointments ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'SELECT 1');
PREPARE updated_at_stmt FROM @updated_at_sql;
EXECUTE updated_at_stmt;
DEALLOCATE PREPARE updated_at_stmt;
