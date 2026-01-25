-- Dispatch Load Balancing Parameters
-- Migration: 095_dispatch_load_balancing.sql
-- Adds configurable load balancing parameters to the dispatch system

-- Create driver_profiles table if it doesn't exist (dependency)
CREATE TABLE IF NOT EXISTS driver_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    availability_status VARCHAR(40) NOT NULL DEFAULT 'available',
    certifications JSON NULL,
    base_latitude DECIMAL(10,6) NULL,
    base_longitude DECIMAL(10,6) NULL,
    active_job_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_offer_position INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_driver_profiles_user (user_id),
    INDEX idx_driver_profiles_availability (availability_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create dispatch_requirements table if it doesn't exist (dependency)
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
    job_category VARCHAR(60) NULL,
    equipment_requirements JSON NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dispatch_requirements_reference (dispatch_reference),
    INDEX idx_dispatch_requirements_schedule (scheduled_start),
    INDEX idx_dispatch_req_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add priority column if dispatch_requirements exists but doesn't have priority
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispatch_requirements' AND column_name = 'priority');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE dispatch_requirements ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT ''normal''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Track daily offer counts for fair distribution
CREATE TABLE IF NOT EXISTS driver_offer_tracking (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    tracking_date DATE NOT NULL,
    offers_received INT UNSIGNED NOT NULL DEFAULT 0,
    offers_accepted INT UNSIGNED NOT NULL DEFAULT 0,
    offers_declined INT UNSIGNED NOT NULL DEFAULT 0,
    offers_expired INT UNSIGNED NOT NULL DEFAULT 0,
    last_offer_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_driver_date (driver_profile_id, tracking_date),
    INDEX idx_tracking_date (tracking_date),
    INDEX idx_driver_offers (driver_profile_id, offers_received)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add active job count to driver_profiles if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'driver_profiles' AND column_name = 'active_job_count');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE driver_profiles ADD COLUMN active_job_count INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last offer position to driver_profiles if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'driver_profiles' AND column_name = 'last_offer_position');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE driver_profiles ADD COLUMN last_offer_position INT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Rotation state for round-robin strategy
CREATE TABLE IF NOT EXISTS dispatch_rotation_state (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rotation_key VARCHAR(60) NOT NULL DEFAULT 'default',
    last_driver_profile_id INT UNSIGNED NULL,
    last_rotated_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_rotation_key (rotation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default rotation state if not exists
INSERT IGNORE INTO dispatch_rotation_state (rotation_key) VALUES ('default');

-- Create waterfall_dispatch_sequences table if it doesn't exist (dependency)
CREATE TABLE IF NOT EXISTS waterfall_dispatch_sequences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence_reference VARCHAR(60) NOT NULL,
    dispatch_requirement_id INT UNSIGNED NULL,
    job_reference VARCHAR(120) NOT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'workorder',
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    offer_timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    max_offers INT UNSIGNED NOT NULL DEFAULT 10,
    current_position INT UNSIGNED NOT NULL DEFAULT 0,
    driver_queue JSON NULL,
    initiated_by INT UNSIGNED NULL,
    accepted_by_driver_id INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    completion_reason VARCHAR(60) NULL,
    completion_notes TEXT NULL,
    job_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    effective_timeout_seconds INT UNSIGNED NULL,
    effective_max_offers INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_sequence_reference (sequence_reference),
    INDEX idx_waterfall_job (job_reference, job_type),
    INDEX idx_waterfall_status (status),
    INDEX idx_waterfall_priority (job_priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add priority columns to waterfall_dispatch_sequences if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'waterfall_dispatch_sequences' AND column_name = 'job_priority');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE waterfall_dispatch_sequences ADD COLUMN job_priority VARCHAR(20) NOT NULL DEFAULT ''normal''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'waterfall_dispatch_sequences' AND column_name = 'effective_timeout_seconds');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE waterfall_dispatch_sequences ADD COLUMN effective_timeout_seconds INT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'waterfall_dispatch_sequences' AND column_name = 'effective_max_offers');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE waterfall_dispatch_sequences ADD COLUMN effective_max_offers INT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
