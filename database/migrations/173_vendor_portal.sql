-- =============================================================================
-- Migration 173 — Vendor self-service portal (Phase 18 / C1)
--
-- Vendors are external parts/equipment suppliers from the procurement vendors
-- table (migration 172). We don't want to provision JWT staff accounts for
-- every supplier — most need only a narrow window into the POs we send them.
-- Procurement issues a long-lived bearer token (random 32-byte secret, stored
-- hashed) which the vendor uses from a dedicated public-facing portal page.
--
-- That single token authenticates them to:
--   - see only their own (open + recent) purchase orders
--   - acknowledge a sent PO
--   - mark line shipments / set tracking number+carrier
--   - upload tracking documents, packing slips, vendor invoices
--
-- A token is bound to ONE vendor and is opaque to the rest of the staff JWT
-- stack. Revoke at any time without impacting anyone else.
--
-- Two tables:
--   1) vendor_portal_tokens — issued tokens (hashed) per vendor
--   2) purchase_order_documents — uploaded tracking / packing-slip / invoice
--      blobs keyed to a single purchase_orders row (and optionally a line)
-- =============================================================================

CREATE TABLE IF NOT EXISTS vendor_portal_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(120) NULL,
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendor_portal_token_hash (token_hash),
    INDEX idx_vendor_portal_token_vendor (vendor_id),
    INDEX idx_vendor_portal_token_active (vendor_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: vendor_portal_tokens.vendor_id -> vendors(id) CASCADE
SET @vendors_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'vendors');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'vendor_portal_tokens'
    AND constraint_name = 'fk_vendor_portal_token_vendor');
SET @sql := IF(@vendors_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE vendor_portal_tokens ADD CONSTRAINT fk_vendor_portal_token_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: vendor_portal_tokens.created_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'vendor_portal_tokens'
    AND constraint_name = 'fk_vendor_portal_token_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE vendor_portal_tokens ADD CONSTRAINT fk_vendor_portal_token_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- purchase_order_documents — uploaded tracking / packing slip / invoice
-- -----------------------------------------------------------------------------
-- kind:
--   tracking      — shipping label / tracking screenshot
--   packing_slip  — vendor's packing slip
--   invoice       — vendor invoice document
--   other         — anything else (uncategorized)
--
-- Optional purchase_order_line_id ties a tracking entry to a specific line
-- (e.g., partial shipment of one SKU), nullable because a packing slip or
-- invoice usually applies to the whole shipment / order.
CREATE TABLE IF NOT EXISTS purchase_order_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    purchase_order_line_id BIGINT UNSIGNED NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'tracking',
    original_name VARCHAR(255) NULL,
    stored_path VARCHAR(512) NULL,
    mime_type VARCHAR(120) NULL,
    size_bytes INT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    tracking_number VARCHAR(120) NULL,
    carrier VARCHAR(80) NULL,
    notes TEXT NULL,
    uploaded_via_token_id BIGINT UNSIGNED NULL,
    uploaded_by_user_id INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_po_doc_po (purchase_order_id, deleted_at),
    INDEX idx_po_doc_line (purchase_order_line_id),
    INDEX idx_po_doc_kind (kind),
    INDEX idx_po_doc_tracking (tracking_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: purchase_order_documents.purchase_order_id -> purchase_orders(id) CASCADE
SET @po_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_documents'
    AND constraint_name = 'fk_po_doc_po');
SET @sql := IF(@po_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_order_documents ADD CONSTRAINT fk_po_doc_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_order_documents.purchase_order_line_id -> purchase_order_lines(id) SET NULL
SET @line_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_documents'
    AND constraint_name = 'fk_po_doc_line');
SET @sql := IF(@line_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_order_documents ADD CONSTRAINT fk_po_doc_line FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_order_documents.uploaded_via_token_id -> vendor_portal_tokens(id) SET NULL
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_documents'
    AND constraint_name = 'fk_po_doc_token');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_documents ADD CONSTRAINT fk_po_doc_token FOREIGN KEY (uploaded_via_token_id) REFERENCES vendor_portal_tokens(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: purchase_order_documents.uploaded_by_user_id -> users(id) SET NULL
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_documents'
    AND constraint_name = 'fk_po_doc_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE purchase_order_documents ADD CONSTRAINT fk_po_doc_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- Add vendor-portal acknowledgement / shipment tracking columns to
-- purchase_orders + purchase_order_lines so the portal can record state
-- without needing a side-table.
-- -----------------------------------------------------------------------------

-- purchase_orders.vendor_acknowledged_at — vendor acknowledged the PO
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND column_name = 'vendor_acknowledged_at');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_orders ADD COLUMN vendor_acknowledged_at DATETIME NULL AFTER expected_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchase_orders.vendor_acknowledged_via_token_id — which token did it
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
    AND column_name = 'vendor_acknowledged_via_token_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_orders ADD COLUMN vendor_acknowledged_via_token_id BIGINT UNSIGNED NULL AFTER vendor_acknowledged_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchase_order_lines.vendor_shipped_at — vendor marked this line shipped
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines'
    AND column_name = 'vendor_shipped_at');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_order_lines ADD COLUMN vendor_shipped_at DATETIME NULL AFTER quantity_received',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchase_order_lines.vendor_tracking_number
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines'
    AND column_name = 'vendor_tracking_number');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_order_lines ADD COLUMN vendor_tracking_number VARCHAR(120) NULL AFTER vendor_shipped_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchase_order_lines.vendor_carrier
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'purchase_order_lines'
    AND column_name = 'vendor_carrier');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_order_lines ADD COLUMN vendor_carrier VARCHAR(80) NULL AFTER vendor_tracking_number',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
