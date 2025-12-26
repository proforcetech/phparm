-- Add SKU/Part number fields to estimate_items, invoice_items, and workorder_items
-- Add manufacturer part number to inventory
-- Create inventory vehicle compatibility table

-- Add SKU to estimate_items
ALTER TABLE estimate_items
ADD COLUMN sku VARCHAR(120) NULL AFTER type,
ADD COLUMN inventory_item_id INT UNSIGNED NULL AFTER sku,
ADD INDEX idx_estimate_item_sku (sku),
ADD INDEX idx_estimate_item_inventory (inventory_item_id);

-- Add SKU to invoice_items
ALTER TABLE invoice_items
ADD COLUMN sku VARCHAR(120) NULL AFTER type,
ADD COLUMN inventory_item_id INT UNSIGNED NULL AFTER sku,
ADD INDEX idx_invoice_item_sku (sku),
ADD INDEX idx_invoice_item_inventory (inventory_item_id);

-- Add SKU to workorder_items
ALTER TABLE workorder_items
ADD COLUMN sku VARCHAR(120) NULL AFTER type,
ADD COLUMN inventory_item_id INT UNSIGNED NULL AFTER sku,
ADD INDEX idx_workorder_item_sku (sku),
ADD INDEX idx_workorder_item_inventory (inventory_item_id);

-- Add manufacturer part number to inventory_items
ALTER TABLE inventory_items
ADD COLUMN manufacturer_part_number VARCHAR(120) NULL AFTER sku,
ADD INDEX idx_inventory_manufacturer_pn (manufacturer_part_number);

-- Create inventory vehicle compatibility table
CREATE TABLE IF NOT EXISTS inventory_vehicle_compatibility (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    vehicle_master_id INT UNSIGNED NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_inventory_vehicle (inventory_item_id, vehicle_master_id),
    INDEX idx_ivc_inventory (inventory_item_id),
    INDEX idx_ivc_vehicle (vehicle_master_id),
    CONSTRAINT fk_ivc_inventory FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_ivc_vehicle FOREIGN KEY (vehicle_master_id)
        REFERENCES vehicle_master (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
