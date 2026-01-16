CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    leave_type VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    requested_hours DECIMAL(6,2) NULL,
    approved_hours DECIMAL(6,2) NULL,
    paid_hours DECIMAL(6,2) NULL,
    reason TEXT NULL,
    created_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leave_employee (employee_id),
    INDEX idx_leave_status (status),
    INDEX idx_leave_range (start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

SET @has_fk_leave_employee := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_employee'
);
SET @has_leave_employee_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'employees'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'leave_requests'
      AND c.column_name = 'employee_id'
);
SET @fk_leave_employee_sql := IF(@has_fk_leave_employee = 0 AND @has_leave_employee_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_leave_employee_stmt FROM @fk_leave_employee_sql;
EXECUTE fk_leave_employee_stmt;
DEALLOCATE PREPARE fk_leave_employee_stmt;

SET @has_fk_leave_created_by := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_created_by'
);
SET @has_leave_created_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'leave_requests'
      AND c.column_name = 'created_by'
);
SET @fk_leave_created_sql := IF(@has_fk_leave_created_by = 0 AND @has_leave_created_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_leave_created_stmt FROM @fk_leave_created_sql;
EXECUTE fk_leave_created_stmt;
DEALLOCATE PREPARE fk_leave_created_stmt;

SET @has_fk_leave_approved_by := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_approved_by'
);
SET @has_leave_approved_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'leave_requests'
      AND c.column_name = 'approved_by'
);
SET @fk_leave_approved_sql := IF(@has_fk_leave_approved_by = 0 AND @has_leave_approved_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_leave_approved_stmt FROM @fk_leave_approved_sql;
EXECUTE fk_leave_approved_stmt;
DEALLOCATE PREPARE fk_leave_approved_stmt;

SET @has_fk_leave_balance_employee := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_balances' AND constraint_name = 'fk_leave_balances_employee'
);
SET @has_leave_balance_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'employees'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'leave_balances'
      AND c.column_name = 'employee_id'
);
SET @fk_leave_balance_sql := IF(@has_fk_leave_balance_employee = 0 AND @has_leave_balance_match = 1,
    'ALTER TABLE leave_balances ADD CONSTRAINT fk_leave_balances_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_leave_balance_stmt FROM @fk_leave_balance_sql;
EXECUTE fk_leave_balance_stmt;
DEALLOCATE PREPARE fk_leave_balance_stmt;
