-- Payroll runs, entries, and exports for gross pay processing

CREATE TABLE IF NOT EXISTS payroll_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_label VARCHAR(120) NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_runs_period (period_start, period_end),
    INDEX idx_payroll_runs_status (status),
    INDEX idx_payroll_runs_created_by (created_by),
    INDEX idx_payroll_runs_approved_by (approved_by),
    CONSTRAINT fk_payroll_runs_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_payroll_runs_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    pay_type VARCHAR(40) NOT NULL,
    gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    calculation_details JSON NULL,
    source_snapshot JSON NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_entries_run (payroll_run_id),
    INDEX idx_payroll_entries_employee (employee_id),
    INDEX idx_payroll_entries_user (user_id),
    INDEX idx_payroll_entries_pay_type (pay_type),
    CONSTRAINT fk_payroll_entries_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_entries_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_payroll_entries_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_payroll_entries_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id INT UNSIGNED NOT NULL,
    provider VARCHAR(80) NOT NULL,
    format VARCHAR(40) NOT NULL DEFAULT 'csv',
    status VARCHAR(40) NOT NULL DEFAULT 'generated',
    payload MEDIUMTEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_exports_run (payroll_run_id),
    INDEX idx_payroll_exports_provider (provider),
    INDEX idx_payroll_exports_status (status),
    CONSTRAINT fk_payroll_exports_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_exports_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
