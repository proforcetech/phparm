-- Employee profile data linked to users (1:1).

CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    hire_date DATE NULL,
    emergency_contact TEXT NULL,
    pay_structure VARCHAR(40) NULL,
    skills JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_employees_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_employees_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'employees' AND constraint_name = 'fk_employees_user'
);
SET @has_employees_user_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'employees'
      AND c.column_name = 'user_id'
);
SET @fk_employees_user_sql := IF(@has_fk_employees_user = 0 AND @has_employees_user_match = 1,
    'ALTER TABLE employees ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_employees_user_stmt FROM @fk_employees_user_sql;
EXECUTE fk_employees_user_stmt;
DEALLOCATE PREPARE fk_employees_user_stmt;
