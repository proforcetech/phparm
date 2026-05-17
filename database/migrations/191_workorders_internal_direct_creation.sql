-- Direct workorder creation now supports two no-estimate paths:
--   1) contracted B2B customer workorders
--   2) staff-internal company workorders with no customer record
--
-- The first direct-WO migration made workorders.estimate_id nullable, but
-- workorders.customer_id and workorder_jobs.estimate_job_id still reflected
-- the estimate-origin workflow. Internal work has no customer, and inline
-- direct jobs have no source estimate_job row, so both FKs must be nullable.

-- workorders.customer_id -> nullable + ON DELETE SET NULL
SET @wo_customer_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'customer_id'
      AND CONSTRAINT_NAME != 'PRIMARY'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @drop_wo_customer_fk_sql := IF(@wo_customer_fk IS NOT NULL,
    CONCAT('ALTER TABLE workorders DROP FOREIGN KEY ', @wo_customer_fk),
    'SELECT 1');
PREPARE drop_wo_customer_fk_stmt FROM @drop_wo_customer_fk_sql;
EXECUTE drop_wo_customer_fk_stmt;
DEALLOCATE PREPARE drop_wo_customer_fk_stmt;

SET @wo_customer_nullable := (
    SELECT IS_NULLABLE
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'customer_id'
);

SET @modify_wo_customer_sql := IF(@wo_customer_nullable = 'NO',
    'ALTER TABLE workorders MODIFY COLUMN customer_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE modify_wo_customer_stmt FROM @modify_wo_customer_sql;
EXECUTE modify_wo_customer_stmt;
DEALLOCATE PREPARE modify_wo_customer_stmt;

SET @has_wo_customer_fk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND constraint_name = 'fk_workorder_customer'
      AND constraint_type = 'FOREIGN KEY'
);

SET @add_wo_customer_fk_sql := IF(@has_wo_customer_fk = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorder_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE add_wo_customer_fk_stmt FROM @add_wo_customer_fk_sql;
EXECUTE add_wo_customer_fk_stmt;
DEALLOCATE PREPARE add_wo_customer_fk_stmt;

-- workorder_jobs.estimate_job_id -> nullable + ON DELETE SET NULL
SET @wo_job_estimate_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_jobs'
      AND column_name = 'estimate_job_id'
      AND CONSTRAINT_NAME != 'PRIMARY'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @drop_wo_job_estimate_fk_sql := IF(@wo_job_estimate_fk IS NOT NULL,
    CONCAT('ALTER TABLE workorder_jobs DROP FOREIGN KEY ', @wo_job_estimate_fk),
    'SELECT 1');
PREPARE drop_wo_job_estimate_fk_stmt FROM @drop_wo_job_estimate_fk_sql;
EXECUTE drop_wo_job_estimate_fk_stmt;
DEALLOCATE PREPARE drop_wo_job_estimate_fk_stmt;

SET @wo_job_estimate_nullable := (
    SELECT IS_NULLABLE
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_jobs'
      AND column_name = 'estimate_job_id'
);

SET @modify_wo_job_estimate_sql := IF(@wo_job_estimate_nullable = 'NO',
    'ALTER TABLE workorder_jobs MODIFY COLUMN estimate_job_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE modify_wo_job_estimate_stmt FROM @modify_wo_job_estimate_sql;
EXECUTE modify_wo_job_estimate_stmt;
DEALLOCATE PREPARE modify_wo_job_estimate_stmt;

SET @has_wo_job_estimate_fk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_jobs'
      AND constraint_name = 'fk_workorder_job_estimate_job'
      AND constraint_type = 'FOREIGN KEY'
);

SET @add_wo_job_estimate_fk_sql := IF(@has_wo_job_estimate_fk = 0,
    'ALTER TABLE workorder_jobs ADD CONSTRAINT fk_workorder_job_estimate_job FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE add_wo_job_estimate_fk_stmt FROM @add_wo_job_estimate_fk_sql;
EXECUTE add_wo_job_estimate_fk_stmt;
DEALLOCATE PREPARE add_wo_job_estimate_fk_stmt;
