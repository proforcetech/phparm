/* 1. Create the Requests Table */
CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    type VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reason TEXT NULL,
    reviewer_id INT UNSIGNED NULL,
    reviewer_notes TEXT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leave_requests_user (user_id),
    INDEX idx_leave_requests_status (status),
    INDEX idx_leave_requests_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* 2. Create the Balances Table */
CREATE TABLE IF NOT EXISTS leave_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    leave_type VARCHAR(40) NOT NULL,
    balance_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    as_of_date DATE NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leave_balance (employee_id, leave_type),
    INDEX idx_leave_balance_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* --------------------------------------------------------- */
/* 3. Conditional Foreign Keys for leave_requests (user_id)  */
/* --------------------------------------------------------- */

/* Check if FK exists */
SET @has_fk_leave_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_user'
);

/* Check if column and parent table exist */
SET @has_user_col_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.tables t ON t.table_schema = c.table_schema
    WHERE c.table_schema = DATABASE() 
      AND c.table_name = 'leave_requests' 
      AND c.column_name = 'user_id'
      AND t.table_name = 'users'
);

/* Add Constraint if valid */
SET @sql_fk_user := IF(@has_fk_leave_user = 0 AND @has_user_col_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    'SELECT "FK leave_requests_user already exists or invalid" as status');

PREPARE stmt_fk_user FROM @sql_fk_user;
EXECUTE stmt_fk_user;
DEALLOCATE PREPARE stmt_fk_user;

/* ------------------------------------------------------------ */
/* 4. Conditional Foreign Keys for leave_requests (reviewer_id) */
/* ------------------------------------------------------------ */

SET @has_fk_leave_reviewer := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_reviewer'
);

SET @has_reviewer_col_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.tables t ON t.table_schema = c.table_schema
    WHERE c.table_schema = DATABASE() 
      AND c.table_name = 'leave_requests' 
      AND c.column_name = 'reviewer_id'
      AND t.table_name = 'users'
);

SET @sql_fk_reviewer := IF(@has_fk_leave_reviewer = 0 AND @has_reviewer_col_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT "FK leave_requests_reviewer already exists or invalid" as status');

PREPARE stmt_fk_reviewer FROM @sql_fk_reviewer;
EXECUTE stmt_fk_reviewer;
DEALLOCATE PREPARE stmt_fk_reviewer;

/* ------------------------------------------------------------ */
/* 5. Conditional Foreign Keys for leave_balances (employee_id) */
/* ------------------------------------------------------------ */

SET @has_fk_bal_employee := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_balances' AND constraint_name = 'fk_leave_balances_employee'
);

/* Note: Checks if 'employees' table exists, otherwise assumes 'users' or skips */
SET @has_bal_col_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.tables t ON t.table_schema = c.table_schema
    WHERE c.table_schema = DATABASE() 
      AND c.table_name = 'leave_balances' 
      AND c.column_name = 'employee_id'
      AND t.table_name = 'employees'
);

SET @sql_fk_bal := IF(@has_fk_bal_employee = 0 AND @has_bal_col_match = 1,
    'ALTER TABLE leave_balances ADD CONSTRAINT fk_leave_balances_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE',
    'SELECT "FK leave_balances_employee already exists or target missing" as status');

PREPARE stmt_fk_bal FROM @sql_fk_bal;
EXECUTE stmt_fk_bal;
DEALLOCATE PREPARE stmt_fk_bal;
