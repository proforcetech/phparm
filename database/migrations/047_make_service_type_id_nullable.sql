-- Make service_type_id nullable in estimate_jobs
-- Service type is optional when creating jobs, so the column should allow NULL values

-- First, check if we need to drop the foreign key constraint
SET @constraint_name := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE table_schema = DATABASE()
      AND table_name = 'estimate_jobs'
      AND column_name = 'service_type_id'
      AND CONSTRAINT_NAME != 'PRIMARY'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @drop_fk_sql := IF(@constraint_name IS NOT NULL,
    CONCAT('ALTER TABLE estimate_jobs DROP FOREIGN KEY ', @constraint_name),
    'SELECT 1');

PREPARE drop_fk_stmt FROM @drop_fk_sql;
EXECUTE drop_fk_stmt;
DEALLOCATE PREPARE drop_fk_stmt;

-- Modify the column to be nullable
ALTER TABLE estimate_jobs
    MODIFY COLUMN service_type_id INT UNSIGNED NULL;

-- Re-add the foreign key constraint if it existed
SET @add_fk_sql := IF(@constraint_name IS NOT NULL,
    'ALTER TABLE estimate_jobs ADD CONSTRAINT fk_estimate_jobs_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)',
    'SELECT 1');

PREPARE add_fk_stmt FROM @add_fk_sql;
EXECUTE add_fk_stmt;
DEALLOCATE PREPARE add_fk_stmt;

-- Drop the old index if it exists (we'll recreate it)
SET @has_service_type_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'estimate_jobs'
      AND index_name = 'idx_estimate_jobs_service_type'
);

SET @drop_idx_sql := IF(@has_service_type_idx > 0,
    'DROP INDEX idx_estimate_jobs_service_type ON estimate_jobs',
    'SELECT 1');

PREPARE drop_idx_stmt FROM @drop_idx_sql;
EXECUTE drop_idx_stmt;
DEALLOCATE PREPARE drop_idx_stmt;

-- Create index for service_type_id (allows NULL values)
CREATE INDEX idx_estimate_jobs_service_type ON estimate_jobs(service_type_id);
