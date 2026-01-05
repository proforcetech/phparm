-- Migration: Create workorders workflow tables
-- This migration adds the workorder entity to support the estimate -> workorder -> invoice workflow

-- Create workorders table
CREATE TABLE IF NOT EXISTS workorders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    estimate_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    assigned_technician_id INT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    estimated_completion DATE NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    call_out_fee DECIMAL(12,2) DEFAULT 0,
    mileage_total DECIMAL(12,2) DEFAULT 0,
    discounts DECIMAL(12,2) DEFAULT 0,
    shop_fee DECIMAL(12,2) DEFAULT 0,
    hazmat_disposal_fee DECIMAL(12,2) DEFAULT 0,
    grand_total DECIMAL(12,2) DEFAULT 0,
    internal_notes TEXT NULL,
    customer_notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_workorder_estimate (estimate_id),
    INDEX idx_workorder_customer (customer_id),
    INDEX idx_workorder_vehicle (vehicle_id),
    INDEX idx_workorder_status (status),
    INDEX idx_workorder_technician (assigned_technician_id),
    CONSTRAINT fk_workorder_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_workorder_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_workorder_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id),
    CONSTRAINT fk_workorder_technician FOREIGN KEY (assigned_technician_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorder_jobs table (links to estimate_jobs for traceability)
CREATE TABLE IF NOT EXISTS workorder_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NOT NULL,
    service_type_id INT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    notes TEXT NULL,
    reference VARCHAR(120) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    assigned_technician_id INT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_workorder_job_workorder (workorder_id),
    INDEX idx_workorder_job_estimate_job (estimate_job_id),
    INDEX idx_workorder_job_status (status),
    CONSTRAINT fk_workorder_job_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE CASCADE,
    CONSTRAINT fk_workorder_job_estimate_job FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs (id),
    CONSTRAINT fk_workorder_job_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id),
    CONSTRAINT fk_workorder_job_technician FOREIGN KEY (assigned_technician_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorder_items table (links to estimate_items for traceability)
CREATE TABLE IF NOT EXISTS workorder_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    estimate_item_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    list_price DECIMAL(12,2) NULL,
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    INDEX idx_workorder_item_job (workorder_job_id),
    INDEX idx_workorder_item_estimate_item (estimate_item_id),
    CONSTRAINT fk_workorder_item_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_workorder_item_estimate_item FOREIGN KEY (estimate_item_id) REFERENCES estimate_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorder_status_history for audit trail
CREATE TABLE IF NOT EXISTS workorder_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    changed_by INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workorder_status_history_workorder (workorder_id),
    CONSTRAINT fk_workorder_status_history_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE CASCADE,
    CONSTRAINT fk_workorder_status_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add sub-estimate support to estimates table
SET @has_parent_estimate_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'parent_estimate_id'
);
SET @parent_estimate_id_sql := IF(@has_parent_estimate_id = 0,
    'ALTER TABLE estimates ADD COLUMN parent_estimate_id INT UNSIGNED NULL AFTER parent_id',
    'SELECT 1');
PREPARE parent_estimate_id_stmt FROM @parent_estimate_id_sql;
EXECUTE parent_estimate_id_stmt;
DEALLOCATE PREPARE parent_estimate_id_stmt;

SET @has_workorder_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'workorder_id'
);
SET @workorder_id_sql := IF(@has_workorder_id = 0,
    'ALTER TABLE estimates ADD COLUMN workorder_id INT UNSIGNED NULL AFTER parent_estimate_id',
    'SELECT 1');
PREPARE workorder_id_stmt FROM @workorder_id_sql;
EXECUTE workorder_id_stmt;
DEALLOCATE PREPARE workorder_id_stmt;

SET @has_estimate_type := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'estimate_type'
);
SET @estimate_type_sql := IF(@has_estimate_type = 0,
    'ALTER TABLE estimates ADD COLUMN estimate_type VARCHAR(20) NOT NULL DEFAULT ''standard'' AFTER status',
    'SELECT 1');
PREPARE estimate_type_stmt FROM @estimate_type_sql;
EXECUTE estimate_type_stmt;
DEALLOCATE PREPARE estimate_type_stmt;

-- Add foreign keys for sub-estimate support (after estimates table is modified)
SET @has_fk_estimate_parent := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND constraint_name = 'fk_estimate_parent_estimate'
);
SET @fk_estimate_parent_sql := IF(@has_fk_estimate_parent = 0,
    'ALTER TABLE estimates ADD CONSTRAINT fk_estimate_parent_estimate FOREIGN KEY (parent_estimate_id) REFERENCES estimates (id)',
    'SELECT 1');
PREPARE fk_estimate_parent_stmt FROM @fk_estimate_parent_sql;
EXECUTE fk_estimate_parent_stmt;
DEALLOCATE PREPARE fk_estimate_parent_stmt;

SET @has_fk_estimate_workorder := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND constraint_name = 'fk_estimate_workorder'
);
SET @fk_estimate_workorder_sql := IF(@has_fk_estimate_workorder = 0,
    'ALTER TABLE estimates ADD CONSTRAINT fk_estimate_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id)',
    'SELECT 1');
PREPARE fk_estimate_workorder_stmt FROM @fk_estimate_workorder_sql;
EXECUTE fk_estimate_workorder_stmt;
DEALLOCATE PREPARE fk_estimate_workorder_stmt;

-- Add workorder_id to invoices for linking
SET @has_invoice_workorder_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'workorder_id'
);
SET @invoice_workorder_id_sql := IF(@has_invoice_workorder_id = 0,
    'ALTER TABLE invoices ADD COLUMN workorder_id INT UNSIGNED NULL AFTER estimate_id',
    'SELECT 1');
PREPARE invoice_workorder_id_stmt FROM @invoice_workorder_id_sql;
EXECUTE invoice_workorder_id_stmt;
DEALLOCATE PREPARE invoice_workorder_id_stmt;

SET @has_fk_invoice_workorder := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND constraint_name = 'fk_invoice_workorder'
);
SET @fk_invoice_workorder_sql := IF(@has_fk_invoice_workorder = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoice_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id)',
    'SELECT 1');
PREPARE fk_invoice_workorder_stmt FROM @fk_invoice_workorder_sql;
EXECUTE fk_invoice_workorder_stmt;
DEALLOCATE PREPARE fk_invoice_workorder_stmt;

-- Update time_entries to optionally link to workorder_jobs
SET @has_time_entry_workorder_job_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'workorder_job_id'
);
SET @time_entry_workorder_job_id_sql := IF(@has_time_entry_workorder_job_id = 0,
    'ALTER TABLE time_entries ADD COLUMN workorder_job_id INT UNSIGNED NULL AFTER estimate_job_id',
    'SELECT 1');
PREPARE time_entry_workorder_job_id_stmt FROM @time_entry_workorder_job_id_sql;
EXECUTE time_entry_workorder_job_id_stmt;
DEALLOCATE PREPARE time_entry_workorder_job_id_stmt;

SET @has_fk_time_entry_workorder_job := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND constraint_name = 'fk_time_entry_workorder_job'
);
SET @fk_time_entry_workorder_job_sql := IF(@has_fk_time_entry_workorder_job = 0,
    'ALTER TABLE time_entries ADD CONSTRAINT fk_time_entry_workorder_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id)',
    'SELECT 1');
PREPARE fk_time_entry_workorder_job_stmt FROM @fk_time_entry_workorder_job_sql;
EXECUTE fk_time_entry_workorder_job_stmt;
DEALLOCATE PREPARE fk_time_entry_workorder_job_stmt;
