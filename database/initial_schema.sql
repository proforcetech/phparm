-- Core users and access
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY unique_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    email_verified TINYINT(1) DEFAULT 0,
    customer_id INT NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    business_name VARCHAR(160) NULL,
    email VARCHAR(160) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    street VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(120) NULL,
    is_commercial TINYINT(1) DEFAULT 0,
    tax_exempt TINYINT(1) DEFAULT 0,
    notes TEXT NULL,
    external_reference VARCHAR(120) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year SMALLINT NOT NULL,
    make VARCHAR(120) NOT NULL,
    model VARCHAR(120) NOT NULL,
    engine VARCHAR(120) NOT NULL,
    transmission VARCHAR(120) NOT NULL,
    drive VARCHAR(20) NOT NULL,
    trim VARCHAR(120) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY vehicle_unique (year, make, model, engine, transmission, drive, trim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_master_id INT NULL,
    year SMALLINT NOT NULL,
    make VARCHAR(120) NOT NULL,
    model VARCHAR(120) NOT NULL,
    engine VARCHAR(120) NOT NULL,
    transmission VARCHAR(120) NOT NULL,
    drive VARCHAR(20) NOT NULL,
    trim VARCHAR(120) NULL,
    vin VARCHAR(30) NULL,
    license_plate VARCHAR(30) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_customer_vehicle_customer (customer_id),
    CONSTRAINT fk_customer_vehicle_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    alias VARCHAR(120) NOT NULL,
    color VARCHAR(120) NOT NULL,
    icon VARCHAR(120) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_service_types_name (name),
    UNIQUE KEY uniq_service_types_alias (alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(120) NULL,
    category VARCHAR(120) NULL,
    stock_quantity INT DEFAULT 0,
    low_stock_threshold INT DEFAULT 0,
    reorder_quantity INT DEFAULT 0,
    cost DECIMAL(12,2) DEFAULT 0,
    sale_price DECIMAL(12,2) DEFAULT 0,
    markup DECIMAL(6,2) NULL,
    location VARCHAR(160) NULL,
    vendor VARCHAR(160) NULL,
    notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estimates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    status VARCHAR(40) NOT NULL,
    technician_id INT NULL,
    expiration_date DATE NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    call_out_fee DECIMAL(12,2) DEFAULT 0,
    mileage_total DECIMAL(12,2) DEFAULT 0,
    discounts DECIMAL(12,2) DEFAULT 0,
    grand_total DECIMAL(12,2) DEFAULT 0,
    internal_notes TEXT NULL,
    customer_notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_estimate_customer (customer_id),
    INDEX idx_estimate_vehicle (vehicle_id),
    CONSTRAINT fk_estimate_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_estimate_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estimate_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT NOT NULL,
    service_type_id INT NOT NULL,
    title VARCHAR(160) NOT NULL,
    notes TEXT NULL,
    reference VARCHAR(120) NULL,
    customer_status VARCHAR(40) DEFAULT 'pending',
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    INDEX idx_estimate_job_estimate (estimate_id),
    INDEX idx_estimate_jobs_service_type (service_type_id),
    CONSTRAINT fk_estimate_job_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_estimate_jobs_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estimate_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estimate_job_id INT NOT NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    INDEX idx_estimate_item_job (estimate_job_id),
    CONSTRAINT fk_estimate_item_job FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    vehicle_id INT NULL,
    estimate_id INT NULL,
    service_type_id INT NULL,
    status VARCHAR(40) NOT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    amount_paid DECIMAL(12,2) DEFAULT 0,
    balance_due DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_invoice_customer (customer_id),
    INDEX idx_invoices_service_type (service_type_id),
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_invoice_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id),
    CONSTRAINT fk_invoice_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_invoices_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    INDEX idx_invoice_item_invoice (invoice_id),
    CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    gateway VARCHAR(40) NOT NULL,
    transaction_id VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(40) NOT NULL,
    paid_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_payment_invoice (invoice_id),
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    estimate_id INT NULL,
    technician_id INT NULL,
    status VARCHAR(40) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    scheduled_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    notes TEXT NULL,
    INDEX idx_appointment_customer (customer_id),
    CONSTRAINT fk_appointment_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_appointment_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE warranty_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    invoice_id INT NULL,
    vehicle_id INT NULL,
    subject VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_warranty_customer (customer_id),
    CONSTRAINT fk_warranty_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminder_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    frequency VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL,
    service_type_filter VARCHAR(160) NULL,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bundles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    service_type_id INT NULL,
    default_job_title VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_bundle_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bundle_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bundle_id INT NOT NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    taxable TINYINT(1) DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_bundle_item_bundle (bundle_id),
    CONSTRAINT fk_bundle_item_bundle FOREIGN KEY (bundle_id) REFERENCES bundles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    estimate_job_id INT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_minutes DECIMAL(10,2) NULL,
    start_latitude DECIMAL(10,6) NULL,
    start_longitude DECIMAL(10,6) NULL,
    start_accuracy DECIMAL(10,2) NULL,
    start_altitude DECIMAL(10,2) NULL,
    start_speed DECIMAL(10,2) NULL,
    start_heading DECIMAL(10,2) NULL,
    start_recorded_at DATETIME NULL,
    start_source VARCHAR(60) NULL,
    start_error TEXT NULL,
    end_latitude DECIMAL(10,6) NULL,
    end_longitude DECIMAL(10,6) NULL,
    end_accuracy DECIMAL(10,2) NULL,
    end_altitude DECIMAL(10,2) NULL,
    end_speed DECIMAL(10,2) NULL,
    end_heading DECIMAL(10,2) NULL,
    end_recorded_at DATETIME NULL,
    end_source VARCHAR(60) NULL,
    end_error TEXT NULL,
    manual_override TINYINT(1) DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE time_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    time_entry_id INT NOT NULL,
    actor_id INT NOT NULL,
    reason TEXT NOT NULL,
    previous_started_at DATETIME NULL,
    previous_ended_at DATETIME NULL,
    previous_duration_minutes DECIMAL(10,2) NULL,
    previous_estimate_job_id INT NULL,
    previous_notes TEXT NULL,
    previous_manual_override TINYINT(1) NULL,
    new_started_at DATETIME NULL,
    new_ended_at DATETIME NULL,
    new_duration_minutes DECIMAL(10,2) NULL,
    new_estimate_job_id INT NULL,
    new_notes TEXT NULL,
    new_manual_override TINYINT(1) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_time_adjustment_entry FOREIGN KEY (time_entry_id) REFERENCES time_entries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    type VARCHAR(20) NOT NULL,
    credit_limit DECIMAL(12,2) DEFAULT 0,
    balance DECIMAL(12,2) DEFAULT 0,
    net_days INT DEFAULT 0,
    apr DECIMAL(5,2) DEFAULT 0,
    late_fee DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(20) NOT NULL,
    CONSTRAINT fk_credit_account_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL,
    category VARCHAR(120) NOT NULL,
    reference VARCHAR(120) NOT NULL,
    purchase_order VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    entry_date DATE NOT NULL,
    vendor VARCHAR(160) NULL,
    description TEXT NULL,
    attachment_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_s_

CREATE TABLE `cms_components` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `type` ENUM('header', 'footer', 'navigation', 'sidebar', 'widget', 'custom') NOT NULL DEFAULT 'custom',
    `description` TEXT NULL,
    `content` LONGTEXT NOT NULL,
    `css` TEXT NULL,
    `javascript` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `cache_ttl` INT UNSIGNED DEFAULT 3600 COMMENT 'Cache time-to-live in seconds',
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
