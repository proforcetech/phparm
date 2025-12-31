-- Migration: 049_inventory_catalog_and_pull_requests.sql
-- Description: Add catalog/non-tracked items support and pull request system for workorders

-- Add is_tracked field to inventory_items to support catalog-only items
ALTER TABLE inventory_items
ADD COLUMN is_tracked TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether stock quantity is tracked (0 = catalog only)';

-- Create inventory pull requests table for workorder parts management
CREATE TABLE IF NOT EXISTS inventory_pull_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    workorder_job_id INT UNSIGNED NULL COMMENT 'Optional link to specific job',

    -- Item reference (either from inventory or manual entry)
    inventory_item_id INT UNSIGNED NULL COMMENT 'Link to inventory item if from catalog',

    -- Item details (copied from inventory or manually entered)
    sku VARCHAR(120) NULL,
    description VARCHAR(255) NOT NULL,

    -- Quantities
    quantity_requested INT UNSIGNED NOT NULL DEFAULT 1,
    quantity_fulfilled INT UNSIGNED NOT NULL DEFAULT 0,

    -- Pricing
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Status and type
    status ENUM('pending', 'pulled', 'ordered', 'received', 'cancelled') NOT NULL DEFAULT 'pending',
    request_type ENUM('pull', 'order') NOT NULL DEFAULT 'pull' COMMENT 'pull = from stock, order = needs ordering',

    -- Tracking
    notes TEXT NULL,
    vendor VARCHAR(160) NULL COMMENT 'Vendor for ordering',
    order_reference VARCHAR(120) NULL COMMENT 'PO number or order reference',

    -- Audit fields
    requested_by INT UNSIGNED NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fulfilled_by INT UNSIGNED NULL,
    fulfilled_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    CONSTRAINT fk_pull_request_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE,
    CONSTRAINT fk_pull_request_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs(id) ON DELETE SET NULL,
    CONSTRAINT fk_pull_request_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_pull_request_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pull_request_fulfilled_by FOREIGN KEY (fulfilled_by) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_pull_request_workorder (workorder_id),
    INDEX idx_pull_request_status (status),
    INDEX idx_pull_request_type (request_type),
    INDEX idx_pull_request_inventory (inventory_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index on is_tracked for filtering catalog vs tracked items
CREATE INDEX idx_inventory_is_tracked ON inventory_items(is_tracked);
