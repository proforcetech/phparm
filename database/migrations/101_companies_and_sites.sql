-- Phase 1.1 of docs/expansion-plan.md: introduce the company/site/contact
-- hierarchy required for multi-site field-service customers.
--
--   companies          — top-level customer organization (can have many sites)
--   sites              — physical/logical service locations under a company
--   site_contacts      — per-site human contacts (site-scoped permissions)
--   billing_contacts   — per-company billing contacts (billing-scoped)
--
-- Existing `customers` keeps its shape; a nullable `company_id` FK is added
-- so legacy rows coexist untouched. A later migration may promote customers
-- into companies, but THIS migration is non-destructive.

-- 1. companies
CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    legal_name VARCHAR(191) NULL,
    external_reference VARCHAR(120) NULL,
    company_type VARCHAR(40) NOT NULL DEFAULT 'commercial',
    tax_id VARCHAR(80) NULL,
    tax_exempt TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    primary_phone VARCHAR(40) NULL,
    primary_email VARCHAR(160) NULL,
    website VARCHAR(191) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_companies_division (division_id),
    INDEX idx_companies_status (status),
    INDEX idx_companies_name (name),
    UNIQUE KEY uq_companies_external_ref (external_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. sites
CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    division_id INT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(80) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    street VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(120) NULL,
    latitude DECIMAL(10,6) NULL,
    longitude DECIMAL(10,6) NULL,
    timezone VARCHAR(64) NULL,
    phone VARCHAR(40) NULL,
    -- Site 1.2 operational fields land in migration 102; keep columns minimal here.
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sites_company (company_id),
    INDEX idx_sites_division (division_id),
    INDEX idx_sites_status (status),
    UNIQUE KEY uq_sites_company_code (company_id, code),
    CONSTRAINT fk_sites_company FOREIGN KEY (company_id)
        REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. site_contacts — humans attached to a specific site
CREATE TABLE IF NOT EXISTS site_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    title VARCHAR(120) NULL,
    email VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    mobile_phone VARCHAR(40) NULL,
    role VARCHAR(80) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Phase 1.3 permission scope: which site data this contact may see via portal.
    -- Kept as JSON so scopes can grow without schema churn.
    permission_scope JSON NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_site_contacts_site (site_id),
    INDEX idx_site_contacts_user (user_id),
    INDEX idx_site_contacts_email (email),
    CONSTRAINT fk_site_contacts_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. billing_contacts — per-company finance contacts
CREATE TABLE IF NOT EXISTS billing_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    title VARCHAR(120) NULL,
    email VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    ap_email VARCHAR(160) NULL,
    ap_phone VARCHAR(40) NULL,
    permission_scope JSON NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_billing_contacts_company (company_id),
    INDEX idx_billing_contacts_user (user_id),
    INDEX idx_billing_contacts_email (email),
    CONSTRAINT fk_billing_contacts_company FOREIGN KEY (company_id)
        REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. nullable customers.company_id — legacy shim
--    Existing customer rows stay valid with company_id IS NULL until explicitly
--    promoted by the CustomerLinkageService (Phase 1.5). This is the key
--    property that keeps the migration non-destructive.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'company_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE customers ADD COLUMN company_id INT UNSIGNED NULL AFTER id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND index_name = 'idx_customers_company');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE customers ADD INDEX idx_customers_company (company_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional: FK can be added here. Some MySQL installs reject FK on large tables
-- without an inline lock window — so we only add it if no conflicting state
-- exists. It is safe to skip and add in a later migration.
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'customers'
      AND constraint_name = 'fk_customers_company');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE customers ADD CONSTRAINT fk_customers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. sites.customer_id — optional legacy linkage so existing workorders/invoices
--    referencing a customer can be mapped to a primary site on demand. Not a
--    hard requirement; helps Phase 1.5 resolver land cleanly.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sites' AND column_name = 'legacy_customer_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sites ADD COLUMN legacy_customer_id INT UNSIGNED NULL AFTER company_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'idx_sites_legacy_customer');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE sites ADD INDEX idx_sites_legacy_customer (legacy_customer_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
