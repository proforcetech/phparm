-- =============================================================================
-- Migration 172 — Procurement / full PO lifecycle (Phase 18 / S5)
--
-- Background: prior to this migration, "vendors" were minimal name-only rows
-- inside the generic `inventory_lookups` table (used as a parts-supplier
-- dropdown). That's not enough for real procurement — we need contact info,
-- payment terms, 1099 eligibility, default currency, status. So we add a
-- proper `vendors` table for procurement purposes alongside the existing
-- inventory lookups (left untouched for backward compatibility).
--
-- Four tables:
--   vendors                       — first-class procurement vendor
--   purchase_orders               — PO header
--   purchase_order_lines          — line items
--   purchase_order_receipts       — receiving event (partial OK)
--   purchase_order_receipt_lines  — per-line received qty
--
-- State machine (purchase_orders.status):
--   draft → sent → partial → received → closed
--   draft → cancelled
--   sent  → cancelled
--   partial → cancelled (only if no qty received yet on any line)
--
-- kind:
--   internal          — bought for internal use (consumables, fleet parts)
--   customer_billable — passed through to a customer with markup/margin
-- =============================================================================

CREATE TABLE IF NOT EXISTS vendors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(60) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    primary_contact_name VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    website VARCHAR(255) NULL,
    street VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(60) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(2) NULL,
    -- Net-N terms (where N is days). Purely advisory; AP system does the
    -- actual due-date math when invoices come in.
    payment_terms VARCHAR(20) NULL,
    currency CHAR(3) NULL DEFAULT 'USD',
    -- 1099 reporting
    tax_id VARCHAR(40) NULL,
    requires_1099 TINYINT(1) NOT NULL DEFAULT 0,
    -- consignment relationship: stock lives at our site but vendor still
    -- owns it until we sell/use it. PO `is_consigned` line items decrement
    -- inventory at consumption time, not receipt time.
    is_consignment_partner TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendors_code (code),
    INDEX idx_vendors_status (status),
    INDEX idx_vendors_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- purchase_orders — header
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(40) NOT NULL,
    vendor_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    -- internal | customer_billable
    kind VARCHAR(20) NOT NULL DEFAULT 'internal',
    -- when kind = customer_billable, these route the cost back to the customer
    customer_id INT UNSIGNED NULL,
    site_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    -- markup applied to line cost when customer_billable to derive sell price
    markup_pct DECIMAL(7,4) NULL,
    -- consignment override: when true, receipt does NOT increment inventory
    -- (stock stays vendor-owned until consumed)
    is_consigned TINYINT(1) NOT NULL DEFAULT 0,
    subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
    tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
    shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
    total_cents INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    ordered_at DATETIME NULL,
    expected_at DATE NULL,
    received_at DATETIME NULL,
    closed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_po_number (po_number),
    INDEX idx_po_vendor (vendor_id),
    INDEX idx_po_status (status, created_at),
    INDEX idx_po_workorder (workorder_id),
    INDEX idx_po_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: purchase_orders.vendor_id -> vendors(id) RESTRICT (don't let an
--     orphaned PO accidentally exist if someone deletes a vendor)
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND constraint_name = 'fk_po_vendor');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_orders.created_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND constraint_name = 'fk_po_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_orders.customer_id -> customers(id) SET NULL
SET @cust_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'customers');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND constraint_name = 'fk_po_customer');
SET @sql := IF(@cust_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_orders.workorder_id -> workorders(id) SET NULL
SET @wo_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND constraint_name = 'fk_po_workorder');
SET @sql := IF(@wo_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_orders.site_id -> sites(id) SET NULL
SET @sites_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sites');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND constraint_name = 'fk_po_site');
SET @sql := IF(@sites_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- purchase_order_lines — line items
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    sku VARCHAR(120) NULL,
    -- optional link back to inventory_items so receipt can update stock
    inventory_item_id INT UNSIGNED NULL,
    -- optional link to a site_asset (e.g. ordering a replacement compressor
    -- earmarked for asset #1234)
    site_asset_id INT UNSIGNED NULL,
    quantity_ordered DECIMAL(12,3) NOT NULL DEFAULT 0,
    quantity_received DECIMAL(12,3) NOT NULL DEFAULT 0,
    unit_cost_cents INT UNSIGNED NOT NULL DEFAULT 0,
    tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
    line_total_cents INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    -- pending | partial | received | cancelled (mirror of header status, per line)
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_po_line_po (purchase_order_id, line_number),
    INDEX idx_po_line_inventory (inventory_item_id),
    INDEX idx_po_line_asset (site_asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: purchase_order_lines.purchase_order_id -> purchase_orders(id) CASCADE
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines'
    AND constraint_name = 'fk_po_line_po');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_lines ADD CONSTRAINT fk_po_line_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_order_lines.inventory_item_id -> inventory_items(id) SET NULL
SET @inv_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines'
    AND constraint_name = 'fk_po_line_inventory');
SET @sql := IF(@inv_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_order_lines ADD CONSTRAINT fk_po_line_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_order_lines.site_asset_id -> site_assets(id) SET NULL
SET @assets_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'site_assets');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines'
    AND constraint_name = 'fk_po_line_asset');
SET @sql := IF(@assets_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_order_lines ADD CONSTRAINT fk_po_line_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- purchase_order_receipts + purchase_order_receipt_lines
-- One receipt event per shipment so partial receiving leaves a forensic trail.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_by_user_id INT UNSIGNED NULL,
    packing_slip_ref VARCHAR(120) NULL,
    notes TEXT NULL,
    INDEX idx_po_receipt_po (purchase_order_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_receipts'
    AND constraint_name = 'fk_po_receipt_po');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_receipts ADD CONSTRAINT fk_po_receipt_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_receipts'
    AND constraint_name = 'fk_po_receipt_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_order_receipts ADD CONSTRAINT fk_po_receipt_user FOREIGN KEY (received_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS purchase_order_receipt_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    purchase_order_line_id BIGINT UNSIGNED NOT NULL,
    quantity_received DECIMAL(12,3) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    INDEX idx_po_receipt_line_receipt (receipt_id),
    INDEX idx_po_receipt_line_po_line (purchase_order_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_receipt_lines'
    AND constraint_name = 'fk_po_rl_receipt');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT fk_po_rl_receipt FOREIGN KEY (receipt_id) REFERENCES purchase_order_receipts(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_receipt_lines'
    AND constraint_name = 'fk_po_rl_po_line');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT fk_po_rl_po_line FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
