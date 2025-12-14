CREATE TABLE IF NOT EXISTS availability_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
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

ALTER TABLE appointments
    MODIFY customer_id INT NULL,
    MODIFY vehicle_id INT NULL,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER notes,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
