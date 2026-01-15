-- Store decoded VIN data for intake reporting

SET @has_impound_vin := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vin'
);

SET @impound_vin_sql := IF(
    @has_impound_vin = 0,
    'ALTER TABLE impound_cases ADD COLUMN vin VARCHAR(30) NULL AFTER customer_vehicle_id',
    'SELECT 1'
);

PREPARE impound_vin_stmt FROM @impound_vin_sql;
EXECUTE impound_vin_stmt;
DEALLOCATE PREPARE impound_vin_stmt;

SET @has_impound_vehicle_year := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vehicle_year'
);

SET @impound_vehicle_year_sql := IF(
    @has_impound_vehicle_year = 0,
    'ALTER TABLE impound_cases ADD COLUMN vehicle_year INT NULL AFTER vin',
    'SELECT 1'
);

PREPARE impound_vehicle_year_stmt FROM @impound_vehicle_year_sql;
EXECUTE impound_vehicle_year_stmt;
DEALLOCATE PREPARE impound_vehicle_year_stmt;

SET @has_impound_vehicle_make := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vehicle_make'
);

SET @impound_vehicle_make_sql := IF(
    @has_impound_vehicle_make = 0,
    'ALTER TABLE impound_cases ADD COLUMN vehicle_make VARCHAR(80) NULL AFTER vehicle_year',
    'SELECT 1'
);

PREPARE impound_vehicle_make_stmt FROM @impound_vehicle_make_sql;
EXECUTE impound_vehicle_make_stmt;
DEALLOCATE PREPARE impound_vehicle_make_stmt;

SET @has_impound_vehicle_model := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vehicle_model'
);

SET @impound_vehicle_model_sql := IF(
    @has_impound_vehicle_model = 0,
    'ALTER TABLE impound_cases ADD COLUMN vehicle_model VARCHAR(80) NULL AFTER vehicle_make',
    'SELECT 1'
);

PREPARE impound_vehicle_model_stmt FROM @impound_vehicle_model_sql;
EXECUTE impound_vehicle_model_stmt;
DEALLOCATE PREPARE impound_vehicle_model_stmt;

SET @has_impound_vehicle_trim := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vehicle_trim'
);

SET @impound_vehicle_trim_sql := IF(
    @has_impound_vehicle_trim = 0,
    'ALTER TABLE impound_cases ADD COLUMN vehicle_trim VARCHAR(80) NULL AFTER vehicle_model',
    'SELECT 1'
);

PREPARE impound_vehicle_trim_stmt FROM @impound_vehicle_trim_sql;
EXECUTE impound_vehicle_trim_stmt;
DEALLOCATE PREPARE impound_vehicle_trim_stmt;

SET @has_impound_vehicle_weight := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vehicle_weight_class'
);

SET @impound_vehicle_weight_sql := IF(
    @has_impound_vehicle_weight = 0,
    'ALTER TABLE impound_cases ADD COLUMN vehicle_weight_class VARCHAR(80) NULL AFTER vehicle_trim',
    'SELECT 1'
);

PREPARE impound_vehicle_weight_stmt FROM @impound_vehicle_weight_sql;
EXECUTE impound_vehicle_weight_stmt;
DEALLOCATE PREPARE impound_vehicle_weight_stmt;

SET @has_impound_vin_decoded := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vin_decoded'
);

SET @impound_vin_decoded_sql := IF(
    @has_impound_vin_decoded = 0,
    'ALTER TABLE impound_cases ADD COLUMN vin_decoded JSON NULL AFTER vehicle_weight_class',
    'SELECT 1'
);

PREPARE impound_vin_decoded_stmt FROM @impound_vin_decoded_sql;
EXECUTE impound_vin_decoded_stmt;
DEALLOCATE PREPARE impound_vin_decoded_stmt;

SET @has_impound_vin_decoded_at := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vin_decoded_at'
);

SET @impound_vin_decoded_at_sql := IF(
    @has_impound_vin_decoded_at = 0,
    'ALTER TABLE impound_cases ADD COLUMN vin_decoded_at DATETIME NULL AFTER vin_decoded',
    'SELECT 1'
);

PREPARE impound_vin_decoded_at_stmt FROM @impound_vin_decoded_at_sql;
EXECUTE impound_vin_decoded_at_stmt;
DEALLOCATE PREPARE impound_vin_decoded_at_stmt;

SET @has_impound_vin_overrides := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'impound_cases'
      AND column_name = 'vin_overrides'
);

SET @impound_vin_overrides_sql := IF(
    @has_impound_vin_overrides = 0,
    'ALTER TABLE impound_cases ADD COLUMN vin_overrides JSON NULL AFTER vin_decoded_at',
    'SELECT 1'
);

PREPARE impound_vin_overrides_stmt FROM @impound_vin_overrides_sql;
EXECUTE impound_vin_overrides_stmt;
DEALLOCATE PREPARE impound_vin_overrides_stmt;

CREATE TABLE IF NOT EXISTS job_vehicle_intakes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    workorder_job_id INT UNSIGNED NOT NULL,
    vin VARCHAR(30) NULL,
    vehicle_year INT NULL,
    vehicle_make VARCHAR(80) NULL,
    vehicle_model VARCHAR(80) NULL,
    vehicle_trim VARCHAR(80) NULL,
    vehicle_weight_class VARCHAR(80) NULL,
    vin_decoded JSON NULL,
    vin_overrides JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_job_vehicle_intakes_job (workorder_job_id),
    INDEX idx_job_vehicle_intakes_workorder (workorder_id),
    CONSTRAINT fk_job_vehicle_intakes_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_vehicle_intakes_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
