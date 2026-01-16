-- Migration: 090_inventory_transfers.sql
-- Description: Inventory transfer requests and items

CREATE TABLE IF NOT EXISTS inventory_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    source_location VARCHAR(160) NULL,
    destination_location VARCHAR(160) NULL,
    notes TEXT NULL,
    requested_by INT UNSIGNED NOT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_by INT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    cancelled_by INT UNSIGNED NULL,
    cancelled_at TIMESTAMP NULL,
    completed_by INT UNSIGNED NULL,
    completed_at TIMESTAMP NULL,
    INDEX idx_inventory_transfers_status (status),
    INDEX idx_inventory_transfers_requested_by (requested_by),
    INDEX idx_inventory_transfers_source (source_location),
    INDEX idx_inventory_transfers_destination (destination_location),
    CONSTRAINT fk_inventory_transfers_requested_by FOREIGN KEY (requested_by)
        REFERENCES users (id),
    CONSTRAINT fk_inventory_transfers_approved_by FOREIGN KEY (approved_by)
        REFERENCES users (id),
    CONSTRAINT fk_inventory_transfers_rejected_by FOREIGN KEY (rejected_by)
        REFERENCES users (id),
    CONSTRAINT fk_inventory_transfers_cancelled_by FOREIGN KEY (cancelled_by)
        REFERENCES users (id),
    CONSTRAINT fk_inventory_transfers_completed_by FOREIGN KEY (completed_by)
        REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_transfer_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT UNSIGNED NOT NULL,
    source_inventory_item_id INT UNSIGNED NOT NULL,
    destination_inventory_item_id INT UNSIGNED NOT NULL,
    quantity_requested INT NOT NULL DEFAULT 0,
    quantity_transferred INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_transfer_items_transfer (transfer_id),
    INDEX idx_inventory_transfer_items_source (source_inventory_item_id),
    INDEX idx_inventory_transfer_items_destination (destination_inventory_item_id),
    CONSTRAINT fk_inventory_transfer_items_transfer FOREIGN KEY (transfer_id)
        REFERENCES inventory_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_transfer_items_source FOREIGN KEY (source_inventory_item_id)
        REFERENCES inventory_items (id),
    CONSTRAINT fk_inventory_transfer_items_destination FOREIGN KEY (destination_inventory_item_id)
        REFERENCES inventory_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
