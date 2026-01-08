-- Migration: Parts Cart & PartsTech Integration
-- Enables technicians to build parts carts that sync with PartsTech for ordering

-- Parts Cart for workorders
CREATE TABLE IF NOT EXISTS parts_carts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    status ENUM('draft', 'pending_approval', 'approved', 'ordered', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
    total_estimated DECIMAL(10,2) DEFAULT 0,
    total_actual DECIMAL(10,2) DEFAULT 0,
    partstech_quote_id VARCHAR(100) NULL COMMENT 'PartsTech quote reference',
    partstech_order_id VARCHAR(100) NULL COMMENT 'PartsTech order reference',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    ordered_at DATETIME NULL,
    received_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pc_workorder (workorder_id),
    INDEX idx_pc_status (status),
    INDEX idx_pc_partstech_quote (partstech_quote_id),
    INDEX idx_pc_partstech_order (partstech_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parts Cart Items
CREATE TABLE IF NOT EXISTS parts_cart_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id INT UNSIGNED NOT NULL,
    workorder_job_id INT UNSIGNED NULL COMMENT 'Optional link to specific job',
    inventory_item_id INT UNSIGNED NULL COMMENT 'Link to local inventory if exists',
    sku VARCHAR(100) NULL,
    part_number VARCHAR(100) NULL,
    description VARCHAR(255) NOT NULL,
    brand VARCHAR(100) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_cost DECIMAL(10,2) NULL COMMENT 'Cost from supplier',
    unit_price DECIMAL(10,2) NULL COMMENT 'Price to charge customer',
    line_total DECIMAL(10,2) GENERATED ALWAYS AS (quantity * COALESCE(unit_price, unit_cost, 0)) STORED,
    partstech_part_id VARCHAR(100) NULL COMMENT 'PartsTech part identifier',
    partstech_availability VARCHAR(50) NULL COMMENT 'in_stock, available, special_order, etc.',
    partstech_supplier VARCHAR(100) NULL COMMENT 'Supplier name from PartsTech',
    partstech_eta VARCHAR(50) NULL COMMENT 'Estimated delivery time',
    source ENUM('manual', 'inventory', 'partstech', 'other') NOT NULL DEFAULT 'manual',
    status ENUM('pending', 'ordered', 'backordered', 'received', 'cancelled') NOT NULL DEFAULT 'pending',
    received_quantity INT DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cart_id) REFERENCES parts_carts(id) ON DELETE CASCADE,
    INDEX idx_pci_cart (cart_id),
    INDEX idx_pci_job (workorder_job_id),
    INDEX idx_pci_inventory (inventory_item_id),
    INDEX idx_pci_partstech_part (partstech_part_id),
    INDEX idx_pci_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PartsTech Integration Settings
INSERT INTO settings (`key`, `group`, `type`, `value`, description, created_at, updated_at)
VALUES
    ('partstech_enabled', 'integrations', 'boolean', 'false', 'Enable PartsTech integration for parts ordering', NOW(), NOW()),
    ('partstech_api_key', 'integrations', 'string', '', 'PartsTech API key', NOW(), NOW()),
    ('partstech_shop_id', 'integrations', 'string', '', 'PartsTech shop/account identifier', NOW(), NOW()),
    ('partstech_default_markup', 'integrations', 'number', '30', 'Default markup percentage on parts cost', NOW(), NOW()),
    ('parts_cart_requires_approval', 'workflow', 'boolean', 'true', 'Require manager approval before ordering parts', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Add partstech fields to inventory_items for caching
ALTER TABLE inventory_items
    ADD COLUMN partstech_part_id VARCHAR(100) NULL COMMENT 'Cached PartsTech part ID',
    ADD COLUMN partstech_last_sync DATETIME NULL COMMENT 'Last sync with PartsTech';

-- Create index for PartsTech lookups
CREATE INDEX idx_inv_partstech ON inventory_items(partstech_part_id);
