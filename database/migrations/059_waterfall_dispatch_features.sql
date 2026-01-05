-- Waterfall dispatching, geofencing, certification tracking, and reliability enhancements

-- Add waterfall dispatching columns to driver_job_offers
ALTER TABLE driver_job_offers
    ADD COLUMN waterfall_sequence_id VARCHAR(120) NULL AFTER job_type,
    ADD COLUMN waterfall_position INT UNSIGNED NULL AFTER waterfall_sequence_id,
    ADD COLUMN rejection_reason VARCHAR(100) NULL AFTER declined_at,
    ADD COLUMN rejection_notes TEXT NULL AFTER rejection_reason,
    ADD COLUMN estimated_eta_minutes INT UNSIGNED NULL AFTER rejection_notes,
    ADD COLUMN estimated_distance_km DECIMAL(10,2) NULL AFTER estimated_eta_minutes,
    ADD COLUMN traffic_factor DECIMAL(4,2) NULL AFTER estimated_distance_km,
    ADD COLUMN idempotency_key VARCHAR(120) NULL AFTER traffic_factor,
    ADD INDEX idx_driver_job_offers_waterfall (waterfall_sequence_id, waterfall_position),
    ADD INDEX idx_driver_job_offers_expires (expires_at, status),
    ADD UNIQUE INDEX uniq_driver_job_offers_idempotency (idempotency_key);

-- Waterfall dispatch sequences table for tracking job offer cascades
CREATE TABLE IF NOT EXISTS waterfall_dispatch_sequences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence_reference VARCHAR(120) NOT NULL,
    dispatch_requirement_id INT UNSIGNED NULL,
    job_reference VARCHAR(120) NOT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'workorder',
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    offer_timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    max_offers INT UNSIGNED NOT NULL DEFAULT 10,
    current_position INT UNSIGNED NOT NULL DEFAULT 0,
    driver_queue JSON NOT NULL,
    initiated_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    completion_reason VARCHAR(100) NULL,
    assigned_driver_profile_id INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_waterfall_sequence_ref (sequence_reference),
    INDEX idx_waterfall_sequences_status (status),
    INDEX idx_waterfall_sequences_job (job_reference, job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver location tracking for real-time geofencing
CREATE TABLE IF NOT EXISTS driver_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10,6) NOT NULL,
    longitude DECIMAL(10,6) NOT NULL,
    accuracy DECIMAL(8,2) NULL,
    altitude DECIMAL(10,2) NULL,
    speed DECIMAL(8,2) NULL,
    heading DECIMAL(6,2) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(40) NULL DEFAULT 'gps',
    INDEX idx_driver_locations_driver (driver_profile_id),
    INDEX idx_driver_locations_time (recorded_at),
    INDEX idx_driver_locations_driver_time (driver_profile_id, recorded_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geofence definitions for automatic state transitions
CREATE TABLE IF NOT EXISTS geofences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    geofence_type VARCHAR(40) NOT NULL DEFAULT 'job_site',
    reference_type VARCHAR(40) NULL,
    reference_id INT UNSIGNED NULL,
    latitude DECIMAL(10,6) NOT NULL,
    longitude DECIMAL(10,6) NOT NULL,
    radius_meters INT UNSIGNED NOT NULL DEFAULT 200,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    trigger_on_enter TINYINT(1) NOT NULL DEFAULT 1,
    trigger_on_exit TINYINT(1) NOT NULL DEFAULT 0,
    enter_action VARCHAR(100) NULL,
    exit_action VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geofences_reference (reference_type, reference_id),
    INDEX idx_geofences_type (geofence_type),
    INDEX idx_geofences_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geofence events log
CREATE TABLE IF NOT EXISTS geofence_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    geofence_id INT UNSIGNED NOT NULL,
    driver_profile_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    latitude DECIMAL(10,6) NOT NULL,
    longitude DECIMAL(10,6) NOT NULL,
    distance_meters DECIMAL(10,2) NULL,
    action_taken VARCHAR(100) NULL,
    action_result JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geofence_events_geofence (geofence_id),
    INDEX idx_geofence_events_driver (driver_profile_id),
    INDEX idx_geofence_events_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver idle alerts tracking
CREATE TABLE IF NOT EXISTS driver_idle_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    job_reference VARCHAR(120) NULL,
    alert_type VARCHAR(40) NOT NULL DEFAULT 'stationary',
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_location_latitude DECIMAL(10,6) NULL,
    last_location_longitude DECIMAL(10,6) NULL,
    stationary_minutes INT UNSIGNED NULL,
    acknowledged_by INT UNSIGNED NULL,
    acknowledged_at DATETIME NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_driver_idle_alerts_driver (driver_profile_id),
    INDEX idx_driver_idle_alerts_status (status),
    INDEX idx_driver_idle_alerts_job (job_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extended driver certifications with expiry tracking
CREATE TABLE IF NOT EXISTS driver_certifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    certification_code VARCHAR(60) NOT NULL,
    certification_name VARCHAR(120) NOT NULL,
    issuing_authority VARCHAR(120) NULL,
    certificate_number VARCHAR(120) NULL,
    issued_date DATE NULL,
    expiry_date DATE NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    document_url VARCHAR(500) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_driver_certification (driver_profile_id, certification_code),
    INDEX idx_driver_certifications_driver (driver_profile_id),
    INDEX idx_driver_certifications_expiry (expiry_date),
    INDEX idx_driver_certifications_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipment compatibility matrix for hard filtering
CREATE TABLE IF NOT EXISTS equipment_job_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_category VARCHAR(60) NOT NULL,
    equipment_class VARCHAR(60) NOT NULL,
    is_compatible TINYINT(1) NOT NULL DEFAULT 1,
    min_capacity DECIMAL(10,2) NULL,
    required_certifications JSON NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_equipment_job_requirement (job_category, equipment_class),
    INDEX idx_equipment_requirements_category (job_category),
    INDEX idx_equipment_requirements_class (equipment_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver performance metrics for recommendation justification
CREATE TABLE IF NOT EXISTS driver_performance_metrics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_accepted INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_declined INT UNSIGNED NOT NULL DEFAULT 0,
    total_distance_km DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    avg_customer_rating DECIMAL(3,2) NULL,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    on_time_arrivals INT UNSIGNED NOT NULL DEFAULT 0,
    late_arrivals INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_driver_metrics_date (driver_profile_id, metric_date),
    INDEX idx_driver_metrics_driver (driver_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job density data for heatmaps
CREATE TABLE IF NOT EXISTS job_density_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    snapshot_hour TINYINT UNSIGNED NULL,
    grid_lat DECIMAL(8,4) NOT NULL,
    grid_lng DECIMAL(8,4) NOT NULL,
    job_count INT UNSIGNED NOT NULL DEFAULT 0,
    avg_response_time_minutes INT UNSIGNED NULL,
    job_type VARCHAR(40) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_density_date (snapshot_date),
    INDEX idx_job_density_location (grid_lat, grid_lng),
    INDEX idx_job_density_type (job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dispatch audit log for idempotency and tracking
CREATE TABLE IF NOT EXISTS dispatch_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(120) NULL,
    event_type VARCHAR(60) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id INT UNSIGNED NULL,
    job_reference VARCHAR(120) NULL,
    driver_profile_id INT UNSIGNED NULL,
    actor_id INT UNSIGNED NULL,
    event_data JSON NULL,
    result_status VARCHAR(40) NULL,
    result_message TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_dispatch_audit_idempotency (idempotency_key),
    INDEX idx_dispatch_audit_event (event_type),
    INDEX idx_dispatch_audit_entity (entity_type, entity_id),
    INDEX idx_dispatch_audit_job (job_reference),
    INDEX idx_dispatch_audit_driver (driver_profile_id),
    INDEX idx_dispatch_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rejection reasons lookup table
CREATE TABLE IF NOT EXISTS offer_rejection_reasons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    category VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_rejection_reason_code (code),
    INDEX idx_rejection_reasons_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default rejection reasons
INSERT INTO offer_rejection_reasons (code, display_name, category, display_order) VALUES
    ('too_far', 'Too far away', 'distance', 1),
    ('heavy_traffic', 'Heavy traffic on route', 'distance', 2),
    ('equipment_issue', 'Equipment issue or unavailable', 'equipment', 3),
    ('wrong_equipment', 'Job requires different equipment', 'equipment', 4),
    ('shift_ending', 'Shift ending soon', 'availability', 5),
    ('already_assigned', 'Already on another job', 'availability', 6),
    ('personal_emergency', 'Personal emergency', 'availability', 7),
    ('vehicle_breakdown', 'Vehicle breakdown', 'equipment', 8),
    ('certification_expired', 'Required certification expired', 'qualification', 9),
    ('unfamiliar_area', 'Unfamiliar with area', 'distance', 10),
    ('weather_conditions', 'Unsafe weather conditions', 'safety', 11),
    ('other', 'Other reason', 'other', 99)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Insert default equipment job requirements
INSERT INTO equipment_job_requirements (job_category, equipment_class, is_compatible, min_capacity, required_certifications) VALUES
    ('flatbed_tow', 'flatbed', 1, NULL, NULL),
    ('flatbed_tow', 'light_tow', 0, NULL, NULL),
    ('flatbed_tow', 'heavy_duty', 1, NULL, NULL),
    ('light_tow', 'light_tow', 1, NULL, NULL),
    ('light_tow', 'flatbed', 1, NULL, NULL),
    ('light_tow', 'heavy_duty', 1, NULL, NULL),
    ('heavy_tow', 'heavy_duty', 1, 10000, NULL),
    ('heavy_tow', 'flatbed', 0, NULL, NULL),
    ('heavy_tow', 'light_tow', 0, NULL, NULL),
    ('motorcycle_tow', 'flatbed', 1, NULL, '["MOTORCYCLE"]'),
    ('motorcycle_tow', 'light_tow', 0, NULL, NULL),
    ('tire_change', 'light_tow', 1, NULL, NULL),
    ('tire_change', 'flatbed', 1, NULL, NULL),
    ('tire_change', 'service_vehicle', 1, NULL, NULL),
    ('jump_start', 'light_tow', 1, NULL, NULL),
    ('jump_start', 'flatbed', 1, NULL, NULL),
    ('jump_start', 'service_vehicle', 1, NULL, NULL),
    ('fuel_delivery', 'light_tow', 1, NULL, NULL),
    ('fuel_delivery', 'service_vehicle', 1, NULL, NULL),
    ('lockout', 'light_tow', 1, NULL, '["LOCKSMITH"]'),
    ('lockout', 'service_vehicle', 1, NULL, '["LOCKSMITH"]'),
    ('winch_out', 'light_tow', 1, NULL, NULL),
    ('winch_out', 'heavy_duty', 1, NULL, NULL),
    ('hazmat_recovery', 'heavy_duty', 1, NULL, '["HAZMAT"]')
ON DUPLICATE KEY UPDATE is_compatible = VALUES(is_compatible);

-- Add FK constraints with safe checks
SET @has_fk_waterfall_sequences_initiator := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'waterfall_dispatch_sequences' AND constraint_name = 'fk_waterfall_sequences_initiator'
);
SET @fk_waterfall_sequences_initiator_sql := IF(@has_fk_waterfall_sequences_initiator = 0,
    'ALTER TABLE waterfall_dispatch_sequences ADD CONSTRAINT fk_waterfall_sequences_initiator FOREIGN KEY (initiated_by) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_waterfall_sequences_initiator_stmt FROM @fk_waterfall_sequences_initiator_sql;
EXECUTE fk_waterfall_sequences_initiator_stmt;
DEALLOCATE PREPARE fk_waterfall_sequences_initiator_stmt;

SET @has_fk_driver_locations_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_locations' AND constraint_name = 'fk_driver_locations_driver'
);
SET @fk_driver_locations_driver_sql := IF(@has_fk_driver_locations_driver = 0,
    'ALTER TABLE driver_locations ADD CONSTRAINT fk_driver_locations_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_locations_driver_stmt FROM @fk_driver_locations_driver_sql;
EXECUTE fk_driver_locations_driver_stmt;
DEALLOCATE PREPARE fk_driver_locations_driver_stmt;

SET @has_fk_geofence_events_geofence := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'geofence_events' AND constraint_name = 'fk_geofence_events_geofence'
);
SET @fk_geofence_events_geofence_sql := IF(@has_fk_geofence_events_geofence = 0,
    'ALTER TABLE geofence_events ADD CONSTRAINT fk_geofence_events_geofence FOREIGN KEY (geofence_id) REFERENCES geofences (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_geofence_events_geofence_stmt FROM @fk_geofence_events_geofence_sql;
EXECUTE fk_geofence_events_geofence_stmt;
DEALLOCATE PREPARE fk_geofence_events_geofence_stmt;

SET @has_fk_geofence_events_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'geofence_events' AND constraint_name = 'fk_geofence_events_driver'
);
SET @fk_geofence_events_driver_sql := IF(@has_fk_geofence_events_driver = 0,
    'ALTER TABLE geofence_events ADD CONSTRAINT fk_geofence_events_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_geofence_events_driver_stmt FROM @fk_geofence_events_driver_sql;
EXECUTE fk_geofence_events_driver_stmt;
DEALLOCATE PREPARE fk_geofence_events_driver_stmt;

SET @has_fk_driver_certifications_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_certifications' AND constraint_name = 'fk_driver_certifications_driver'
);
SET @fk_driver_certifications_driver_sql := IF(@has_fk_driver_certifications_driver = 0,
    'ALTER TABLE driver_certifications ADD CONSTRAINT fk_driver_certifications_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_certifications_driver_stmt FROM @fk_driver_certifications_driver_sql;
EXECUTE fk_driver_certifications_driver_stmt;
DEALLOCATE PREPARE fk_driver_certifications_driver_stmt;

SET @has_fk_driver_idle_alerts_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_idle_alerts' AND constraint_name = 'fk_driver_idle_alerts_driver'
);
SET @fk_driver_idle_alerts_driver_sql := IF(@has_fk_driver_idle_alerts_driver = 0,
    'ALTER TABLE driver_idle_alerts ADD CONSTRAINT fk_driver_idle_alerts_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_idle_alerts_driver_stmt FROM @fk_driver_idle_alerts_driver_sql;
EXECUTE fk_driver_idle_alerts_driver_stmt;
DEALLOCATE PREPARE fk_driver_idle_alerts_driver_stmt;

SET @has_fk_driver_metrics_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_performance_metrics' AND constraint_name = 'fk_driver_metrics_driver'
);
SET @fk_driver_metrics_driver_sql := IF(@has_fk_driver_metrics_driver = 0,
    'ALTER TABLE driver_performance_metrics ADD CONSTRAINT fk_driver_metrics_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_metrics_driver_stmt FROM @fk_driver_metrics_driver_sql;
EXECUTE fk_driver_metrics_driver_stmt;
DEALLOCATE PREPARE fk_driver_metrics_driver_stmt;
