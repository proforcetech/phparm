-- Core users and access
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL
);

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
);

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
);

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
);

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
);

CREATE TABLE service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0
);

CREATE TABLE inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(120) NULL,
    category VARCHAR(120) NULL,
    stock_quantity INT DEFAULT 0,
    low_stock_threshold INT DEFAULT 0,
    cost DECIMAL(12,2) DEFAULT 0,
    sale_price DECIMAL(12,2) DEFAULT 0,
    markup DECIMAL(6,2) NULL,
    location VARCHAR(160) NULL,
    notes TEXT NULL
);

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
);

CREATE TABLE estimate_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT NOT NULL,
    title VARCHAR(160) NOT NULL,
    notes TEXT NULL,
    reference VARCHAR(120) NULL,
    customer_status VARCHAR(40) DEFAULT 'pending',
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    INDEX idx_estimate_job_estimate (estimate_id),
    CONSTRAINT fk_estimate_job_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
);

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
);

CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    vehicle_id INT NULL,
    estimate_id INT NULL,
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
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_invoice_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id),
    CONSTRAINT fk_invoice_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
);

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
);

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
);

CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    estimate_id INT NULL,
    technician_id INT NULL,
    status VARCHAR(40) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    notes TEXT NULL,
    INDEX idx_appointment_customer (customer_id),
    CONSTRAINT fk_appointment_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_appointment_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id)
);

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
);

CREATE TABLE reminder_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    frequency VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL,
    service_type_filter VARCHAR(160) NULL,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL
);

CREATE TABLE bundles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    service_type_id INT NULL,
    default_job_title VARCHAR(160) NOT NULL,
    CONSTRAINT fk_bundle_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
);

CREATE TABLE bundle_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bundle_id INT NOT NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    taxable TINYINT(1) DEFAULT 1,
    INDEX idx_bundle_item_bundle (bundle_id),
    CONSTRAINT fk_bundle_item_bundle FOREIGN KEY (bundle_id) REFERENCES bundles (id)
);

CREATE TABLE time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    estimate_job_id INT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_minutes DECIMAL(10,2) NULL,
    start_latitude DECIMAL(10,6) NULL,
    start_longitude DECIMAL(10,6) NULL,
    end_latitude DECIMAL(10,6) NULL,
    end_longitude DECIMAL(10,6) NULL,
    manual_override TINYINT(1) DEFAULT 0,
    notes TEXT NULL
);

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
);

CREATE TABLE financial_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL,
    category VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    entry_date DATE NOT NULL,
    vendor VARCHAR(160) NULL,
    description TEXT NULL,
    attachment_path VARCHAR(255) NULL
);

CREATE TABLE inspection_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE inspection_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    display_order INT DEFAULT 0,
    CONSTRAINT fk_inspection_section_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id)
);

CREATE TABLE inspection_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    input_type VARCHAR(40) NOT NULL,
    default_value VARCHAR(160) NULL,
    display_order INT DEFAULT 0,
    CONSTRAINT fk_inspection_item_section FOREIGN KEY (section_id) REFERENCES inspection_sections (id)
);
