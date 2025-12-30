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
ALTER TABLE estimates
    ADD COLUMN parent_estimate_id INT UNSIGNED NULL AFTER parent_id,
    ADD COLUMN workorder_id INT UNSIGNED NULL AFTER parent_estimate_id,
    ADD COLUMN estimate_type VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER status;

-- Add foreign keys for sub-estimate support (after estimates table is modified)
ALTER TABLE estimates
    ADD CONSTRAINT fk_estimate_parent_estimate FOREIGN KEY (parent_estimate_id) REFERENCES estimates (id),
    ADD CONSTRAINT fk_estimate_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id);

-- Add workorder_id to invoices for linking
ALTER TABLE invoices
    ADD COLUMN workorder_id INT UNSIGNED NULL AFTER estimate_id;

ALTER TABLE invoices
    ADD CONSTRAINT fk_invoice_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id);

-- Update time_entries to optionally link to workorder_jobs
ALTER TABLE time_entries
    ADD COLUMN workorder_job_id INT UNSIGNED NULL AFTER estimate_job_id;

ALTER TABLE time_entries
    ADD CONSTRAINT fk_time_entry_workorder_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id);
