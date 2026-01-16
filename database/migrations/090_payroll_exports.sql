-- Payroll export history tracking for external providers.

CREATE TABLE IF NOT EXISTS payroll_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    total_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_exports_provider (provider),
    INDEX idx_payroll_exports_created_at (created_at),
    INDEX idx_payroll_exports_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_payroll_exports_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'payroll_exports' AND constraint_name = 'fk_payroll_exports_user'
);
SET @has_payroll_exports_user_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'payroll_exports'
      AND c.column_name = 'created_by'
);
SET @fk_payroll_exports_user_sql := IF(@has_fk_payroll_exports_user = 0 AND @has_payroll_exports_user_match = 1,
    'ALTER TABLE payroll_exports ADD CONSTRAINT fk_payroll_exports_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_payroll_exports_user_stmt FROM @fk_payroll_exports_user_sql;
EXECUTE fk_payroll_exports_user_stmt;
DEALLOCATE PREPARE fk_payroll_exports_user_stmt;
