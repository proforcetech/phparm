-- Core users and access
CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY unique_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY role_permission_unique (role, permission),
    INDEX idx_role_permissions_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    email_verified TINYINT(1) DEFAULT 0,
    customer_id INT UNSIGNED NULL,
    remember_token VARCHAR(100) NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_type ENUM('none', 'totp', 'sms', 'email') NOT NULL DEFAULT 'none',
    two_factor_secret VARCHAR(128) NULL,
    two_factor_recovery_codes TEXT NULL,
    two_factor_setup_pending TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indicates whether user needs to complete 2FA setup',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    last_activity_at TIMESTAMP NULL DEFAULT NULL,
   INDEX idx_users_two_factor_setup_pending (two_factor_setup_pending),
   INDEX idx_users_last_activity (last_activity_at),
   INDEX idx_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    billing_street VARCHAR(255) NULL,
    billing_city VARCHAR(120) NULL,
    billing_state VARCHAR(120) NULL,
    billing_postal_code VARCHAR(20) NULL,
    billing_country VARCHAR(120) NULL,
    is_commercial TINYINT(1) DEFAULT 0,
    tax_exempt TINYINT(1) DEFAULT 0,
    notes TEXT NULL,
    external_reference VARCHAR(120) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_master (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_master_id INT UNSIGNED NULL,
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
    mileage_in INT UNSIGNED NULL COMMENT 'Mileage when vehicle arrives',
    mileage_out INT UNSIGNED NULL COMMENT 'Mileage when vehicle leaves',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_customer_vehicle_customer (customer_id),
    CONSTRAINT fk_customer_vehicle_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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



-- Estimates, Workorders, Invoices

CREATE TABLE estimates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    parent_estimate_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    is_mobile TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL,
    estimate_type VARCHAR(20) NOT NULL DEFAULT 'standard',
    technician_id INT UNSIGNED NULL,
    expiration_date DATE NULL,
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
    rejection_reason TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_estimate_customer (customer_id),
    INDEX idx_estimate_vehicle (vehicle_id),
    INDEX idx_estimates_parent_id (parent_id),
    INDEX idx_estimates_workorder_id (workorder_id),
    CONSTRAINT fk_estimate_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_estimate_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id),
    CONSTRAINT fk_estimate_parent_estimate FOREIGN KEY (parent_estimate_id) REFERENCES estimates (id)
    -- Note: fk_estimate_workorder added via ALTER TABLE after workorders table is created
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estimate_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    service_type_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    notes TEXT NULL,
    reference VARCHAR(120) NULL,
    customer_status VARCHAR(40) DEFAULT 'pending',
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    INDEX idx_estimate_job_estimate (estimate_id),
    INDEX idx_estimate_jobs_service_type (service_type_id),
    INDEX idx_estimate_jobs_display_order (estimate_id, display_order),
    CONSTRAINT fk_estimate_job_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_estimate_jobs_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estimate_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_job_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    sku VARCHAR(120) NULL,
    inventory_item_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    list_price DECIMAL(12,2) DEFAULT 0, 
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    INDEX idx_estimate_item_job (estimate_job_id),
    INDEX idx_estimate_item_sku (sku),
    INDEX idx_estimate_item_inventory (inventory_item_id),
    CONSTRAINT fk_estimate_item_job FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    is_mobile TINYINT(1) NOT NULL DEFAULT 0,
    estimate_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    service_type_id INT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    split_billing TINYINT(1) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    amount_paid DECIMAL(12,2) DEFAULT 0,
    balance_due DECIMAL(12,2) DEFAULT 0,
    shop_fee DECIMAL(12,2) DEFAULT 0,
    hazmat_disposal_fee DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    public_token VARCHAR(64) NULL,
    public_token_expires_at DATETIME NULL,
    UNIQUE KEY idx_invoice_public_token (public_token),
    INDEX idx_invoice_customer (customer_id),
    INDEX idx_invoices_service_type (service_type_id),
    INDEX idx_invoice_workorder (workorder_id),
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_invoice_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id),
    CONSTRAINT fk_invoice_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_invoices_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
    -- Note: fk_invoice_workorder added via ALTER TABLE after workorders table is created
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    sku VARCHAR(120) NULL,
    inventory_item_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    list_price DECIMAL(12,2) DEFAULT 0, 
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    core_return_id INT UNSIGNED NULL,
    core_price DECIMAL(10, 2) NULL,
    INDEX idx_invoice_item_invoice (invoice_id),
    INDEX idx_invoice_item_sku (sku),
    INDEX idx_invoice_item_inventory (inventory_item_id),
    INDEX idx_invoice_item_core (core_return_id),
    CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    gateway VARCHAR(40) NOT NULL,
    method VARCHAR(40) NULL,
    transaction_id VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference VARCHAR(40) NULL,
    status VARCHAR(40) NOT NULL,
    metadata JSON NULL,
    paid_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_payment_invoice (invoice_id),
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_payer_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    payer_role ENUM('primary', 'secondary') NOT NULL,
    payer_name VARCHAR(160) NULL,
    allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_invoice_payer_allocations_invoice (invoice_id),
    INDEX idx_invoice_payer_allocations_role (payer_role),
    CONSTRAINT fk_invoice_payer_allocations_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appointments System

CREATE TABLE appointments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NULL,
    technician_id INT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    notes TEXT NULL,
    reminder_sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appointments_reminder (status, start_time, reminder_sent_at), 
    INDEX idx_appointment_customer (customer_id),
    CONSTRAINT fk_appointment_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_appointment_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE availability_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NULL,
    holiday_date DATE NULL,
    label VARCHAR(160) NULL,
    opens_at TIME NULL,
    closes_at TIME NULL,
    slot_minutes INT NOT NULL DEFAULT 30,
    buffer_minutes INT NOT NULL DEFAULT 0,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_availability_day (day_of_week),
    INDEX idx_availability_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warranty Claim System

CREATE TABLE warranty_claims (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NULL,
    subject VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(40) NOT NULL,
    financial_impact DECIMAL(12,2) NOT NULL DEFAULT 0,
    credit_received_amount DECIMAL(12,2) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_warranty_customer (customer_id),
    CONSTRAINT fk_warranty_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE warranty_claim_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id INT UNSIGNED NOT NULL,
    actor_type VARCHAR(40) NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_warranty_message_claim (claim_id),
    CONSTRAINT fk_warranty_message_claim FOREIGN KEY (claim_id) REFERENCES warranty_claims (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Job Bundles

CREATE TABLE bundles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    internal_notes TEXT NULL,
    discount_type VARCHAR(20) NULL,
    discount_value DECIMAL(12,2) NULL,
    service_type_id INT UNSIGNED NULL,
    default_job_title VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_bundle_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bundle_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bundle_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    list_price DECIMAL(12,2) DEFAULT 0, 
    taxable TINYINT(1) DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_bundle_item_bundle (bundle_id),
    CONSTRAINT fk_bundle_item_bundle FOREIGN KEY (bundle_id) REFERENCES bundles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time tracking

CREATE TABLE time_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    technician_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NULL,
    workorder_job_id INT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_minutes DECIMAL(10,2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    en_route_at DATETIME NULL,
    on_site_at DATETIME NULL,
    wrap_up_at DATETIME NULL,
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
    updated_at TIMESTAMP NULL,
    INDEX idx_time_entry_workorder_job (workorder_job_id)
    -- Note: fk_time_entry_workorder_job added via ALTER TABLE after workorder_jobs table is created
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE time_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    time_entry_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    previous_status VARCHAR(20) NULL,
    previous_started_at DATETIME NULL,
    previous_ended_at DATETIME NULL,
    previous_en_route_at DATETIME NULL,
    previous_on_site_at DATETIME NULL,
    previous_wrap_up_at DATETIME NULL,
    previous_duration_minutes DECIMAL(10,2) NULL,
    previous_estimate_job_id INT UNSIGNED NULL,
    previous_task_id INT UNSIGNED NULL,
    previous_task_name VARCHAR(160) NULL,
    previous_flat_rate_minutes DECIMAL(10,2) NULL,
    previous_notes TEXT NULL,
    previous_manual_override TINYINT(1) NULL,
    new_status VARCHAR(20) NULL,
    new_started_at DATETIME NULL,
    new_ended_at DATETIME NULL,
    new_en_route_at DATETIME NULL,
    new_on_site_at DATETIME NULL,
    new_wrap_up_at DATETIME NULL,
    new_duration_minutes DECIMAL(10,2) NULL,
    new_estimate_job_id INT UNSIGNED NULL,
    new_task_id INT UNSIGNED NULL,
    new_task_name VARCHAR(160) NULL,
    new_flat_rate_minutes DECIMAL(10,2) NULL,
    new_notes TEXT NULL,
    new_manual_override TINYINT(1) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_time_adjustment_entry FOREIGN KEY (time_entry_id) REFERENCES time_entries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer Credit Accounts

CREATE TABLE credit_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    credit_limit DECIMAL(12,2) DEFAULT 0,
    balance DECIMAL(12,2) DEFAULT 0,
    available_credit DECIMAL(12,2) DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    net_days INT DEFAULT 0,
    apr DECIMAL(5,2) DEFAULT 0,
    late_fee DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(20) NOT NULL,
    CONSTRAINT fk_credit_account_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_account_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    transaction_type VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    description TEXT NULL,
    created_by INT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_credit_transactions_account (credit_account_id),
    INDEX idx_credit_transactions_customer (customer_id),
    INDEX idx_credit_transactions_occurred (occurred_at),
    INDEX idx_credit_transactions_reference (reference_type, reference_id),
    CONSTRAINT fk_credit_transactions_account FOREIGN KEY (credit_account_id) REFERENCES credit_accounts (id),
    CONSTRAINT fk_credit_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_account_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    reference_number VARCHAR(100) NULL,
    notes TEXT NULL,
    processed_by INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_credit_payments_account (credit_account_id),
    INDEX idx_credit_payments_customer (customer_id),
    INDEX idx_credit_payments_payment_date (payment_date),
    INDEX idx_credit_payments_status (status),
    CONSTRAINT fk_credit_payments_account FOREIGN KEY (credit_account_id) REFERENCES credit_accounts (id),
    CONSTRAINT fk_credit_payments_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_payment_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_account_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    reminder_type VARCHAR(20) NOT NULL,
    days_before_due INT UNSIGNED NULL,
    days_past_due INT UNSIGNED NULL,
    sent_at DATETIME NOT NULL,
    sent_via VARCHAR(20) NOT NULL,
    message TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_credit_reminders_account (credit_account_id),
    INDEX idx_credit_reminders_customer (customer_id),
    INDEX idx_credit_reminders_sent (sent_at),
    INDEX idx_credit_reminders_type (reminder_type),
    CONSTRAINT fk_credit_reminders_account FOREIGN KEY (credit_account_id) REFERENCES credit_accounts (id),
    CONSTRAINT fk_credit_reminders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchases, Expenses and Financials

CREATE TABLE financial_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL,
    category VARCHAR(120) NOT NULL,
    reference VARCHAR(120) NOT NULL,
    purchase_order VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    entry_date DATE NOT NULL,
    vendor VARCHAR(160) NULL,
    description TEXT NULL,
    attachment_path VARCHAR(255) NULL,
    idempotency_key VARCHAR(120) NULL,
    UNIQUE KEY uniq_financial_entries_idempotency (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_drawer_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opened_by INT UNSIGNED NOT NULL,
    closed_by INT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    start_float DECIMAL(12,2) NOT NULL DEFAULT 0,
    end_float DECIMAL(12,2) NULL,
    cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
    expected_cash DECIMAL(12,2) NULL,
    over_short DECIMAL(12,2) NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cash_drawer_status (status),
    INDEX idx_cash_drawer_started (started_at),
    INDEX idx_cash_drawer_opened_by (opened_by),
    INDEX idx_cash_drawer_closed_by (closed_by),
    CONSTRAINT fk_cash_drawer_opened_by FOREIGN KEY (opened_by) REFERENCES users (id),
    CONSTRAINT fk_cash_drawer_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicle Inspections

CREATE TABLE inspection_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    display_order INT DEFAULT 0,
    CONSTRAINT fk_inspection_section_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    input_type VARCHAR(40) NOT NULL,
    default_value VARCHAR(160) NULL,
    options JSON NULL COMMENT 'JSON configuration: {min, max, step} for number_scale, {choices: [...]} for select_scale',
    display_order INT DEFAULT 0,
    fail_threshold VARCHAR(100) NULL COMMENT 'Threshold that indicates failure: "no" for boolean, numeric value for scales',
    recommended_service_type_id INT UNSIGNED NULL COMMENT 'Default service type to suggest when item fails',
    estimated_labor_hours DECIMAL(5,2) NULL COMMENT 'Default labor hours for failed item repair',
    estimated_parts_cost DECIMAL(10,2) NULL COMMENT 'Estimated parts cost for failed item repair',
    CONSTRAINT fk_inspection_item_section FOREIGN KEY (section_id) REFERENCES inspection_sections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inspection reports tables with media and signatures
CREATE TABLE inspection_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    estimate_id INT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    summary TEXT NULL,
    pdf_path VARCHAR(255) NULL,
    completed_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inspection_report_customer (customer_id),
    INDEX idx_inspection_report_template (template_id)
);

CREATE TABLE inspection_report_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    template_item_id INT UNSIGNED NOT NULL,
    label VARCHAR(160) NOT NULL,
    response TEXT NOT NULL,
    note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection_item_report (report_id)
);

CREATE TABLE inspection_report_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    signature_data LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection_signature_report (report_id)
);

CREATE TABLE inspection_report_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    type ENUM('image', 'video') NOT NULL,
    path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(160) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    client_token VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection_media_report (report_id),
    UNIQUE INDEX idx_inspection_media_client_token (report_id, client_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System Settings

CREATE TABLE settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(160) NOT NULL UNIQUE,
    `group` VARCHAR(80) NOT NULL,
    `type` VARCHAR(20) NOT NULL,
    `value` TEXT NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_settings_group (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- audit logs

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(100) NULL,
    actor_id INT UNSIGNED NULL,
    context JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_actor (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications

CREATE TABLE notification_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(50) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    template VARCHAR(150) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(50) NULL,
    meta JSON NULL,
    error_massage TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel (channel),
    INDEX idx_recipient (recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset tokens
CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(160) NOT NULL,
    token VARCHAR(120) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY unique_token (token),
    INDEX idx_password_reset_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification tokens
CREATE TABLE email_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(120) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY unique_email_verification_token (token),
    INDEX idx_email_verifications_user (user_id),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminder Campaigns and Preferences

CREATE TABLE reminder_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    channel VARCHAR(20) NOT NULL,
    frequency VARCHAR(40) NOT NULL,
    frequency_unit VARCHAR(20) NOT NULL DEFAULT 'day',
    frequency_interval INT NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL,
    email_subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    sms_body TEXT NULL,
    service_type_filter VARCHAR(160) NULL,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminder preferences for customers

CREATE TABLE reminder_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    email VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    preferred_channel ENUM('mail', 'sms', 'both', 'none') NOT NULL DEFAULT 'both',
    lead_days SMALLINT NOT NULL DEFAULT 3,
    preferred_hour TINYINT NOT NULL DEFAULT 9,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    source VARCHAR(120) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY reminder_preferences_customer_unique (customer_id),
    UNIQUE KEY reminder_preferences_email_unique (email),
    INDEX reminder_preferences_channel_idx (preferred_channel),
    INDEX reminder_preferences_active_idx (is_active),
    CONSTRAINT fk_reminder_preferences_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log reminders

CREATE TABLE reminder_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    preference_id INT UNSIGNED NULL, -- Ensure this matches parent
    customer_id INT UNSIGNED NOT NULL,
    channel VARCHAR(20) NOT NULL,
    status VARCHAR(40) NOT NULL,
    scheduled_for DATETIME NULL,
    sent_at DATETIME NULL,
    body TEXT NULL,
    error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reminder_logs_campaign (campaign_id),
    INDEX idx_reminder_logs_preference (preference_id),
    INDEX idx_reminder_logs_customer (customer_id),
    CONSTRAINT fk_reminder_logs_campaign FOREIGN KEY (campaign_id) REFERENCES reminder_campaigns (id),
    CONSTRAINT fk_reminder_logs_preference FOREIGN KEY (preference_id) REFERENCES reminder_preferences (id),
    CONSTRAINT fk_reminder_logs_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    checkout_url TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_session_invoice (invoice_id),
    INDEX idx_payment_session_session (session_id),
    UNIQUE KEY unique_invoice_provider (invoice_id, provider),
    CONSTRAINT fk_payment_session_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    payment_reference VARCHAR(255) NOT NULL,
    refund_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_invoice (invoice_id),
    INDEX idx_refund_payment (payment_reference),
    INDEX idx_refund_id (refund_id),
    CONSTRAINT fk_refund_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CMS content core tables

CREATE TABLE cms_menus (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    status ENUM('draft', 'pending', 'published', 'archived') NOT NULL DEFAULT 'draft',
    description TEXT NULL,
    items JSON NULL,
    meta_title VARCHAR(255) NULL,
    meta_description TEXT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_menus_status (status),
    INDEX idx_cms_menus_published_at (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    url VARCHAR(500) NOT NULL,
    mime_type VARCHAR(150) NULL,
    size_bytes INT UNSIGNED NULL,
    title VARCHAR(255) NULL,
    alt_text VARCHAR(255) NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_media_status (status),
    INDEX idx_cms_media_published_at (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    structure LONGTEXT NULL COMMENT 'Template structure/layout definition',
    default_css TEXT NULL COMMENT 'Default CSS styles for this template',
    default_js TEXT NULL COMMENT 'Default JavaScript for this template',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_templates_slug (slug),
    INDEX idx_cms_templates_is_active (is_active),
    INDEX idx_cms_templates_created_by (created_by),
    INDEX idx_cms_templates_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_components (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL DEFAULT 'custom',
    description TEXT NULL,
    content LONGTEXT NULL,
    css LONGTEXT NULL,
    javascript LONGTEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    cache_ttl INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_components_slug (slug),
    INDEX idx_cms_components_type (type),
    INDEX idx_cms_components_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Display name of the category',
    slug VARCHAR(255) NOT NULL UNIQUE COMMENT 'URL-friendly identifier',
    parent_id INT UNSIGNED NULL,
    description TEXT NULL COMMENT 'Category description',
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Display order (lower numbers first)',
    meta_title VARCHAR(255) NULL COMMENT 'SEO meta title',
    meta_description TEXT NULL COMMENT 'SEO meta description',
    meta_keywords VARCHAR(255) NULL COMMENT 'SEO meta keywords',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_categories_status (status),
    INDEX idx_cms_categories_sort_order (sort_order),
    INDEX idx_cms_categories_parent_id (parent_id),
    INDEX idx_cms_categories_slug (slug),
    CONSTRAINT fk_cms_categories_parent FOREIGN KEY (parent_id) REFERENCES cms_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: When a category is deleted, pages are set to NULL (no category)
-- This prevents data loss and allows pages to continue functioning at base URLs

CREATE TABLE cms_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    preview_token VARCHAR(64) NULL,
    category_id INT UNSIGNED NULL,
    template_id INT UNSIGNED NULL,
    header_component_id INT UNSIGNED NULL,
    footer_component_id INT UNSIGNED NULL,
    custom_css TEXT NULL,
    custom_js TEXT NULL,
    status ENUM('draft', 'pending', 'published', 'archived') NOT NULL DEFAULT 'draft',
    meta_title VARCHAR(255) NULL,
    meta_description TEXT NULL,
    meta_keywords VARCHAR(255) NULL,
    summary TEXT NULL,
    content LONGTEXT NULL,
    publish_start_at DATETIME NULL,
    publish_end_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_pages_status (status),
    INDEX idx_cms_pages_published_at (published_at),
    INDEX idx_cms_pages_preview_token (preview_token),
    INDEX idx_cms_pages_template_id (template_id),
    INDEX idx_cms_pages_header_component (header_component_id),
    INDEX idx_cms_pages_footer_component (footer_component_id),
    INDEX idx_cms_pages_category_id (category_id),
    CONSTRAINT fk_cms_pages_header_component FOREIGN KEY (header_component_id) REFERENCES cms_components(id) ON DELETE SET NULL,
    CONSTRAINT fk_cms_pages_footer_component FOREIGN KEY (footer_component_id) REFERENCES cms_components(id) ON DELETE SET NULL,
    CONSTRAINT fk_cms_pages_category FOREIGN KEY (category_id) REFERENCES cms_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_cms_pages_template FOREIGN KEY (template_id) REFERENCES cms_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CMS settings table for configuration
CREATE TABLE cms_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CMS cache table for page/component caching
CREATE TABLE cms_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NULL COMMENT 'Cache type: page, component, template, etc.',
    cache_value LONGTEXT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cms_cache_key (cache_key),
    INDEX idx_cms_cache_type (type),
    INDEX idx_cms_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom roles table with JSON-based permissions
-- This supersedes the old role_permissions table with a more flexible approach
-- System roles (admin, manager, technician, customer) are pre-populated and protected

CREATE TABLE custom_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    description TEXT NULL,
    permissions JSON NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_is_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create public estimate requests table
-- Stores estimate requests submitted through public-facing form

CREATE TABLE estimate_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Contact Information
    name VARCHAR(160) NOT NULL,
    email VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NOT NULL,

    -- Customer Address
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50) NOT NULL,
    zip VARCHAR(20) NOT NULL,

    -- Service Address
    service_address_same_as_customer TINYINT(1) DEFAULT 1,
    service_address VARCHAR(255) NULL,
    service_city VARCHAR(100) NULL,
    service_state VARCHAR(50) NULL,
    service_zip VARCHAR(20) NULL,

    -- Vehicle Information
    vehicle_year SMALLINT NULL,
    vehicle_make VARCHAR(120) NULL,
    vehicle_model VARCHAR(120) NULL,
    vin VARCHAR(30) NULL,
    license_plate VARCHAR(30) NULL,

    -- Service Request
    service_type_id INT UNSIGNED NULL, -- Fixed typo
    service_type_name VARCHAR(120) NULL COMMENT 'Stored in case service type is deleted',
    description TEXT NULL,

    -- Status and Processing
    status ENUM('pending', 'contacted', 'estimated', 'declined', 'converted') NOT NULL DEFAULT 'pending',
    estimate_id INT UNSIGNED NULL COMMENT 'Link to created estimate', -- Fixed: added UNSIGNED
    customer_id INT UNSIGNED NULL COMMENT 'Link if customer is created/matched', -- Recommended for consistency
    vehicle_id INT UNSIGNED NULL COMMENT 'Link if vehicle is created', -- Recommended for consistency

    -- Metadata
    source VARCHAR(50) DEFAULT 'website' COMMENT 'Form submission source',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,

    -- Staff Notes
    internal_notes TEXT NULL,
    contacted_at DATETIME NULL,
    contacted_by INT UNSIGNED NULL, -- Fixed: added UNSIGNED

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_estimate_request_status (status),
    INDEX idx_estimate_request_created (created_at),
    INDEX idx_estimate_request_email (email),
    INDEX idx_estimate_request_estimate (estimate_id),
    
    -- Constraints will now work because types match
    CONSTRAINT fk_est_req_service_type FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_est_req_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE SET NULL,
    CONSTRAINT fk_est_req_contacted_by FOREIGN KEY (contacted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for storing photos uploaded with estimate requests
CREATE TABLE estimate_request_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_estimate_request_media_request (request_id),
    FOREIGN KEY (request_id) REFERENCES estimate_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 404 logging and redirect management

-- Table for tracking 404 errors
CREATE TABLE not_found_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uri VARCHAR(512) NOT NULL COMMENT 'Requested URI that resulted in 404',
    referrer VARCHAR(512) NULL COMMENT 'HTTP Referer header',
    user_agent VARCHAR(512) NULL COMMENT 'User-Agent string',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP address',
    first_seen DATETIME NOT NULL COMMENT 'First time this URI was requested',
    last_seen DATETIME NOT NULL COMMENT 'Most recent request time',
    hits INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Total number of 404 hits',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_uri (uri(255)),
    INDEX idx_last_seen (last_seen),
    INDEX idx_hits (hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks 404 errors for monitoring and redirect creation';

-- Table for managing redirects
CREATE TABLE redirects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_path VARCHAR(512) NOT NULL COMMENT 'Original path to redirect from',
    destination_path VARCHAR(512) NOT NULL COMMENT 'Target path to redirect to',
    redirect_type ENUM('301', '302', '307', '308') NOT NULL DEFAULT '301' COMMENT 'HTTP redirect status code',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether redirect is enabled',
    match_type ENUM('exact', 'prefix', 'regex') NOT NULL DEFAULT 'exact' COMMENT 'How to match source path',
    description VARCHAR(255) NULL COMMENT 'Optional note about this redirect',
    hits INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times this redirect has been used',
    created_by INT UNSIGNED NULL COMMENT 'User ID who created this redirect',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_source (source_path(255)),
    INDEX idx_source_path (source_path(255)),
    INDEX idx_is_active (is_active),
    INDEX idx_match_type (match_type),

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='URL redirect rules for SEO and fixing broken links';

-- Financial Categories for Financial Reports

CREATE TABLE financial_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(20) NOT NULL,
    INDEX idx_financial_categories_type (type),
    INDEX idx_financial_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorders workflow tables
-- adds the workorder entity to support the estimate -> workorder -> invoice workflow

-- Create workorders table
CREATE TABLE workorders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    estimate_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NULL,
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
    goa_fee DECIMAL(12,2) DEFAULT 0,
    goa_billing_party VARCHAR(40) NULL,
    grand_total DECIMAL(12,2) DEFAULT 0,
    internal_notes TEXT NULL,
    customer_notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_workorder_estimate (estimate_id),
    INDEX idx_workorder_customer (customer_id),
    INDEX idx_workorder_vehicle (vehicle_id),
    INDEX idx_workorder_status (status),
    INDEX idx_workorder_branch (branch_id),
    INDEX idx_workorder_technician (assigned_technician_id),
    CONSTRAINT fk_workorder_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_workorder_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_workorder_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id),
    CONSTRAINT fk_workorder_technician FOREIGN KEY (assigned_technician_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add deferred foreign keys to workorders (couldn't be added during table creation)
ALTER TABLE estimates ADD CONSTRAINT fk_estimate_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id);
ALTER TABLE invoices ADD CONSTRAINT fk_invoice_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id);

-- Create workorder_jobs table (links to estimate_jobs for traceability)
CREATE TABLE workorder_jobs (
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

-- Add deferred foreign key from time_entries to workorder_jobs
ALTER TABLE time_entries ADD CONSTRAINT fk_time_entry_workorder_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id);

-- Create workorder_items table (links to estimate_items for traceability)
CREATE TABLE workorder_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    estimate_item_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    sku VARCHAR(120) NULL,
    inventory_item_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    list_price DECIMAL(12,2) NULL,
    taxable TINYINT(1) DEFAULT 1,
    line_total DECIMAL(12,2) DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    core_return_id INT UNSIGNED NULL,
    core_price DECIMAL(10, 2) NULL,
    INDEX idx_workorder_item_core (core_return_id),
    INDEX idx_workorder_item_job (workorder_job_id),
    INDEX idx_workorder_item_estimate_item (estimate_item_id),
    INDEX idx_workorder_item_sku (sku),
    INDEX idx_workorder_item_inventory (inventory_item_id),
    CONSTRAINT fk_workorder_item_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_workorder_item_estimate_item FOREIGN KEY (estimate_item_id) REFERENCES estimate_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorder_status_history for audit trail
CREATE TABLE workorder_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    changed_by INT UNSIGNED NULL,
    notes TEXT NULL,
    client_event_id VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workorder_status_history_workorder (workorder_id),
    UNIQUE INDEX idx_workorder_status_event (workorder_id, client_event_id),
    CONSTRAINT fk_workorder_status_history_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE CASCADE,
    CONSTRAINT fk_workorder_status_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create approval audit log for e-signing compliance
CREATE TABLE approval_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(60) NOT NULL,
    job_id INT UNSIGNED NULL,
    signer_name VARCHAR(160) NULL,
    signer_email VARCHAR(160) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    geo_location VARCHAR(255) NULL,
    signature_hash VARCHAR(64) NULL,
    document_hash VARCHAR(64) NULL,
    comment TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_approval_audit_entity (entity_type, entity_id),
    INDEX idx_approval_audit_action (action),
    INDEX idx_approval_audit_created (created_at),
    INDEX idx_approval_audit_signer (signer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_signatures table for e-signing legal compliance
CREATE TABLE estimate_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    signer_name VARCHAR(160) NOT NULL,
    signer_email VARCHAR(160) NULL,
    signature_data MEDIUMTEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    document_hash VARCHAR(64) NULL,
    legal_consent TINYINT(1) NOT NULL DEFAULT 0,
    consent_text TEXT NULL,
    comment TEXT NULL,
    signed_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_estimate_signature_estimate (estimate_id),
    CONSTRAINT fk_estimate_signature_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorder_signatures table for workorder completions
CREATE TABLE workorder_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    signature_type VARCHAR(40) NOT NULL DEFAULT 'completion',
    signer_name VARCHAR(160) NOT NULL,
    signer_email VARCHAR(160) NULL,
    signature_data MEDIUMTEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    document_hash VARCHAR(64) NULL,
    legal_consent TINYINT(1) NOT NULL DEFAULT 0,
    consent_text TEXT NULL,
    comment TEXT NULL,
    signed_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_workorder_signature_workorder (workorder_id),
    INDEX idx_workorder_signature_type (signature_type),
    CONSTRAINT fk_workorder_signature_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_job_rejection_reasons for detailed rejection tracking
CREATE TABLE estimate_job_rejections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NOT NULL,
    rejection_reason VARCHAR(120) NULL,
    rejection_details TEXT NULL,
    rejected_by_name VARCHAR(160) NULL,
    rejected_by_email VARCHAR(160) NULL,
    ip_address VARCHAR(45) NULL,
    rejected_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_job_rejection_estimate (estimate_id),
    INDEX idx_job_rejection_job (estimate_job_id),
    CONSTRAINT fk_job_rejection_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_job_rejection_job FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Create estimate_public_links table for shareable estimate links
CREATE TABLE estimate_public_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    short_code VARCHAR(20) NOT NULL,
    expires_at DATETIME NULL,
    last_accessed_at DATETIME NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uk_estimate_public_links_hash (token_hash),
    UNIQUE KEY uk_estimate_public_links_short (short_code),
    INDEX idx_estimate_public_links_estimate (estimate_id),
    CONSTRAINT fk_estimate_public_links_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_public_comments table for customer comments via public link
CREATE TABLE estimate_public_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_estimate_public_comments_estimate (estimate_id),
    CONSTRAINT fk_estimate_public_comments_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_job_feedback table for job-level customer feedback
CREATE TABLE estimate_job_feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    job_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_estimate_job_feedback_estimate (estimate_id),
    INDEX idx_estimate_job_feedback_job (job_id),
    CONSTRAINT fk_estimate_job_feedback_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_estimate_job_feedback_job FOREIGN KEY (job_id) REFERENCES estimate_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Create messaging tables for staff conversations
CREATE TABLE message_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_message_threads_created_at (created_at),
    CONSTRAINT fk_message_threads_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message_participant (thread_id, participant_id),
    INDEX idx_message_participants_thread (thread_id),
    INDEX idx_message_participants_participant (participant_id),
    CONSTRAINT fk_message_participants_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE,
    CONSTRAINT fk_message_participants_user FOREIGN KEY (participant_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_messages_thread (thread_id),
    INDEX idx_message_messages_created_at (created_at),
    CONSTRAINT fk_message_messages_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE,
    CONSTRAINT fk_message_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_reads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    last_read_message_id INT UNSIGNED NULL,
    last_read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_message_reads (thread_id, participant_id),
    INDEX idx_message_reads_thread (thread_id),
    INDEX idx_message_reads_participant (participant_id),
    INDEX idx_message_reads_created_at (created_at),
    CONSTRAINT fk_message_reads_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE,
    CONSTRAINT fk_message_reads_participant FOREIGN KEY (participant_id) REFERENCES users (id),
    CONSTRAINT fk_message_reads_message FOREIGN KEY (last_read_message_id) REFERENCES message_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track message threads tied to system notification scopes
CREATE TABLE message_notification_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope_type VARCHAR(60) NOT NULL,
    scope_id VARCHAR(120) NOT NULL,
    thread_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message_notification_scope (scope_type, scope_id),
    CONSTRAINT fk_message_notification_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner integrations tables for inbound dispatch requests

CREATE TABLE partner_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_accounts_key (partner_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partner_dispatch_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_account_id INT UNSIGNED NOT NULL,
    external_reference VARCHAR(120) NULL,
    dispatch_reference VARCHAR(120) NULL,
    protocol VARCHAR(40) NULL,
    accepted_at TIMESTAMP NULL,
    accepted_by INT UNSIGNED NULL,
    last_partner_status VARCHAR(60) NULL,
    last_synced_at TIMESTAMP NULL,
    sync_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    sync_error TEXT NULL,
    source VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'received',
    payload JSON NULL,
    raw_payload LONGTEXT NULL,
    error_message TEXT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_dispatch_partner (partner_account_id),
    INDEX idx_partner_dispatch_status (status),
    INDEX idx_partner_dispatch_created (created_at),
     CONSTRAINT fk_partner_dispatch_partner FOREIGN KEY (partner_account_id) REFERENCES partner_accounts (id),
    CONSTRAINT fk_partner_dispatch_accepted_by FOREIGN KEY (accepted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partner_request_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_dispatch_request_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    content LONGBLOB NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_attachment_request (partner_dispatch_request_id),
    CONSTRAINT fk_partner_attachment_request FOREIGN KEY (partner_dispatch_request_id) REFERENCES partner_dispatch_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dispatch schema tables for driver profiles, equipment, shifts, and requirements

CREATE TABLE driver_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    availability_status VARCHAR(40) NOT NULL DEFAULT 'available',
    certifications JSON NULL,
    base_latitude DECIMAL(10,6) NULL,
    base_longitude DECIMAL(10,6) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_driver_profiles_user (user_id),
    INDEX idx_driver_profiles_availability (availability_status),
 CONSTRAINT fk_driver_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE truck_equipment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    equipment_class VARCHAR(60) NOT NULL,
    capacity DECIMAL(10,2) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_truck_equipment_driver (driver_profile_id),
    INDEX idx_truck_equipment_class (equipment_class),
    CONSTRAINT fk_truck_equipment_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE driver_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    shift_start DATETIME NOT NULL,
    shift_end DATETIME NOT NULL,
    minutes_worked INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pre_trip_checklist_id INT UNSIGNED NULL,
    post_trip_checklist_id INT UNSIGNED NULL,
    INDEX idx_driver_shifts_driver (driver_profile_id),
    INDEX idx_driver_shifts_window (shift_start, shift_end),
    INDEX idx_driver_shifts_pre_trip (pre_trip_checklist_id),
    INDEX idx_driver_shifts_post_trip (post_trip_checklist_id),
    CONSTRAINT fk_driver_shifts_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE
    -- Note: fk_driver_shifts_pre_trip and fk_driver_shifts_post_trip added via ALTER TABLE after truck_checklist_entries
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispatch_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispatch_reference VARCHAR(120) NULL,
    job_category VARCHAR(60) NULL,
    scheduled_start DATETIME NULL,
    estimated_duration_hours DECIMAL(6,2) NULL,
    required_capacity DECIMAL(10,2) NULL,
    required_equipment_class VARCHAR(60) NULL,
    equipment_requirements JSON NULL,
    required_certifications JSON NULL,
    pickup_latitude DECIMAL(10,6) NULL,
    pickup_longitude DECIMAL(10,6) NULL,
    dropoff_latitude DECIMAL(10,6) NULL,
    dropoff_longitude DECIMAL(10,6) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dispatch_requirements_reference (dispatch_reference),
    INDEX idx_dispatch_requirements_schedule (scheduled_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Impound storage tables for cases, rates, fees, and lien notices

CREATE TABLE impound_cases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_number VARCHAR(60) NOT NULL,
    customer_id INT UNSIGNED NULL,
    vin VARCHAR(30) NULL,
    vehicle_year INT NULL, 
    vehicle_make VARCHAR(80) NULL,
    vehicle_model VARCHAR(80) NULL,
    vehicle_trim VARCHAR(80) NULL,
    vehicle_weight_class VARCHAR(80) NULL,
    vin_decoded JSON NULL,
    vin_decoded_at DATETIME NULL,
    vin_overrides JSON NULL,
    auction_status VARCHAR(40) NOT NULL DEFAULT 'in_storage', -- Removed 'ADD COLUMN'
    auction_status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    customer_vehicle_id INT UNSIGNED NULL,
    state_code CHAR(2) NOT NULL,
    impound_date DATETIME NOT NULL,
    intake_location VARCHAR(160) NULL,
    impound_reason VARCHAR(200) NULL,
    tow_agency VARCHAR(160) NULL,
    hold_release_contact VARCHAR(160) NULL,
    gate_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    after_hours_gate TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'open',
    status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,-- Core users and access
CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY unique_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY role_permission_unique (role, permission),
    INDEX idx_role_permissions_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    email_verified TINYINT(1) DEFAULT 0,
    customer_id INT UNSIGNED NULL,
    remember_token VARCHAR(100) NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_type ENUM('none', 'totp', 'sms', 'email') NOT NULL DEFAULT 'none',
    two_factor_secret VARCHAR(128) NULL,
    two_factor_recovery_codes TEXT NULL,
    two_factor_setup_pending TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    last_activity_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_users_two_factor_setup_pending (two_factor_setup_pending),
    INDEX idx_users_last_activity (last_activity_at),
    INDEX idx_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_master (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_master_id INT UNSIGNED NULL,
    year SMALLINT NOT NULL,
    make VARCHAR(120) NOT NULL,
    model VARCHAR(120) NOT NULL,
    vin VARCHAR(30) NULL,
    license_plate VARCHAR(30) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_customer_vehicle_customer (customer_id),
    CONSTRAINT fk_customer_vehicle_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    alias VARCHAR(120) NOT NULL,
    color VARCHAR(120) NOT NULL,
    icon VARCHAR(120) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    UNIQUE KEY uniq_service_types_name (name),
    UNIQUE KEY uniq_service_types_alias (alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Estimates and Invoices
CREATE TABLE estimates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL,
    grand_total DECIMAL(12,2) DEFAULT 0,
    workorder_id INT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    CONSTRAINT fk_estimate_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_estimate_vehicle FOREIGN KEY (vehicle_id) REFERENCES customer_vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL,
    total DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP NULL,
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_invoice_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Impound System
CREATE TABLE impound_cases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_number VARCHAR(60) NOT NULL,
    customer_id INT UNSIGNED NULL,
    vehicle_make VARCHAR(80) NULL,
    vehicle_model VARCHAR(80) NULL,
    auction_status VARCHAR(40) NOT NULL DEFAULT 'in_storage',
    status VARCHAR(40) NOT NULL DEFAULT 'open',
    impound_date DATETIME NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_impound_case_num (case_number),
    CONSTRAINT fk_impound_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inspections
CREATE TABLE inspection_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    input_type VARCHAR(40) NOT NULL,
    default_value VARCHAR(160) NULL,
    display_order INT DEFAULT 0,
    CONSTRAINT fk_inspection_item_section FOREIGN KEY (section_id) REFERENCES inspection_sections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messaging
CREATE TABLE message_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_thread_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_msg_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_msg_sender FOREIGN KEY (sender_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    CONSTRAINT fk_msg_attach_msg FOREIGN KEY (message_id) REFERENCES message_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    UNIQUE KEY uniq_impound_cases_case_number (case_number),
    INDEX idx_impound_cases_status (status),
    INDEX idx_impound_cases_state (state_code),
    INDEX idx_impound_cases_vehicle (customer_vehicle_id),
    CONSTRAINT fk_impound_cases_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_impound_cases_vehicle FOREIGN KEY (customer_vehicle_id) REFERENCES customer_vehicles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state_code CHAR(2) NOT NULL,
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    gate_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    after_hours_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    grace_period_hours INT UNSIGNED NOT NULL DEFAULT 0,
    max_billable_days INT UNSIGNED NULL,
    lien_notice_days INT UNSIGNED NULL,
    effective_date DATE NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_storage_rates_state (state_code),
    INDEX idx_storage_rates_status (status),
    CONSTRAINT fk_storage_fees_case FOREIGN KEY (impound_case_id) REFERENCES impound_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_fees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    impound_case_id INT UNSIGNED NOT NULL,
    fee_date DATE NOT NULL,
    fee_type VARCHAR(40) NOT NULL,
    description VARCHAR(200) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_storage_fees_case (impound_case_id),
    INDEX idx_storage_fees_date (fee_date),
    INDEX idx_storage_fees_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lien_notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    impound_case_id INT UNSIGNED NOT NULL,
    notice_type VARCHAR(60) NOT NULL,
    notice_date DATE NOT NULL,
    sent_date DATE NULL,
    due_date DATE NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    document_path VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lien_notices_case (impound_case_id),
    INDEX idx_lien_notices_status (status),
    CONSTRAINT fk_lien_notices_case FOREIGN KEY (impound_case_id) REFERENCES impound_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job evidence checkpoints, damage reports, and signatures

CREATE TABLE job_checkpoint_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    checkpoint_type VARCHAR(30) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_checkpoint_job (workorder_job_id),
    INDEX idx_job_checkpoint_type (checkpoint_type),
    CONSTRAINT fk_job_checkpoint_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_checkpoint_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_damage_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    diagram_points JSON NOT NULL,
    notes TEXT NULL,
    reported_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_damage_job (workorder_job_id),
    CONSTRAINT fk_job_damage_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_damage_reported_by FOREIGN KEY (reported_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    signature_type VARCHAR(40) NOT NULL DEFAULT 'authorization',
    signer_name VARCHAR(160) NOT NULL,
    signer_email VARCHAR(160) NULL,
    signature_data MEDIUMTEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    document_hash VARCHAR(64) NULL,
    legal_consent TINYINT(1) NOT NULL DEFAULT 0,
    consent_text TEXT NULL,
    comment TEXT NULL,
    signed_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_job_signature_job (workorder_job_id),
    INDEX idx_job_signature_type (signature_type),
    CONSTRAINT fk_job_signature_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create tracking links for workorder jobs

CREATE TABLE job_tracking_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    job_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NULL,
    last_position JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_job_tracking_links_token (token),
    INDEX idx_job_tracking_links_job (job_id),
    INDEX idx_job_tracking_links_expires (expires_at),
    CONSTRAINT fk_job_tracking_links_job FOREIGN KEY (job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add payment transactions, masked SMS sessions, driver push tokens, and job offers

CREATE TABLE payment_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(60) NULL,
    reference_type VARCHAR(40) NULL,
    reference_id INT UNSIGNED NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_payment_transactions_provider_external (provider, external_id),
    INDEX idx_payment_transactions_reference (reference_type, reference_id),
    INDEX idx_payment_transactions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE driver_push_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL,
    platform VARCHAR(40) NULL,
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_driver_push_tokens (driver_profile_id, token),
    INDEX idx_driver_push_tokens_driver (driver_profile_id),
    CONSTRAINT fk_driver_push_tokens_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE driver_job_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    job_reference VARCHAR(120) NOT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'workorder',
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    offer_payload JSON NULL,
    dropoff_latitude DECIMAL(10,6) NULL,
    dropoff_longitude DECIMAL(10,6) NULL,
    waterfall_sequence_id VARCHAR(120) NULL,
    waterfall_position INT UNSIGNED NULL,
    rejection_reason VARCHAR(100) NULL,
    rejection_notes TEXT NULL,
    estimated_eta_minutes INT UNSIGNED NULL,
    estimated_distance_km DECIMAL(10,2) NULL,
    traffic_factor DECIMAL(4,2) NULL,
    idempotency_key VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    accepted_at DATETIME NULL,
    declined_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_driver_job_offers_driver (driver_profile_id),
    INDEX idx_driver_job_offers_status (status),
    INDEX idx_driver_job_offers_reference (job_reference),
    INDEX idx_driver_job_offers_waterfall (waterfall_sequence_id, waterfall_position),
    INDEX idx_driver_job_offers_expires (expires_at, status),
    INDEX idx_driver_job_offers_dropoff (driver_profile_id, status, accepted_at),

    UNIQUE KEY uniq_driver_job_offers_idempotency (idempotency_key),

    CONSTRAINT fk_driver_job_offers_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_job_offers_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE masked_sms_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_reference VARCHAR(120) NOT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'workorder',
    driver_user_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    driver_phone VARCHAR(40) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL,
    masked_number VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_masked_sms_session (job_reference, job_type, driver_user_id, customer_id),
    INDEX idx_masked_sms_sessions_masked (masked_number),
   CONSTRAINT fk_masked_sms_driver_user FOREIGN KEY (driver_user_id) REFERENCES users (id),
   CONSTRAINT fk_masked_sms_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
   CONSTRAINT fk_masked_sms_messages_session FOREIGN KEY (session_id) REFERENCES masked_sms_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE masked_sms_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    direction VARCHAR(20) NOT NULL,
    sender_role VARCHAR(40) NULL,
    from_number VARCHAR(40) NOT NULL,
    to_number VARCHAR(40) NOT NULL,
    body TEXT NOT NULL,
    provider_message_id VARCHAR(120) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_masked_sms_messages_session (session_id),
    INDEX idx_masked_sms_messages_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Towing Pricing Matrix Tables
-- Provides configurable pricing for roadside assistance and towing services
-- based on service class (light, medium, heavy, motorcycle) and service type

-- Service Classes (e.g., light duty, medium duty, heavy duty, motorcycle)
CREATE TABLE towing_service_classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    weight_min INT UNSIGNED NULL COMMENT 'Minimum vehicle weight in lbs',
    weight_max INT UNSIGNED NULL COMMENT 'Maximum vehicle weight in lbs',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_towing_service_class_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service Types (e.g., tow, winch, jump start, tire change, fuel delivery, lockout)
CREATE TABLE towing_service_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NOT NULL COMMENT 'Short code for the service type',
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_towing_service_type_name (name),
    UNIQUE KEY uk_towing_service_type_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pricing Matrix - links service class + service type to fees
CREATE TABLE towing_price_matrix (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_class_id INT UNSIGNED NOT NULL,
    service_type_id INT UNSIGNED NOT NULL,
    rollout_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat fee to dispatch/respond',
    hookup_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fee for hooking up vehicle',
    onhook_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fee for loading vehicle onto truck',
    mileage_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-mile rate (loaded)',
    deadhead_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-mile rate (unloaded/return)',
    accident_cleanup_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Additional fee for accident scenes',
    winch_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fee for winching operations',
    storage_daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Daily storage rate',
    after_hours_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Multiplier for after-hours service',
    minimum_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minimum total charge for service',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL COMMENT 'Internal notes about this pricing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_towing_price_matrix (service_class_id, service_type_id),
    CONSTRAINT fk_towing_price_matrix_class FOREIGN KEY (service_class_id) REFERENCES towing_service_classes (id) ON DELETE CASCADE,
    CONSTRAINT fk_towing_price_matrix_type FOREIGN KEY (service_type_id) REFERENCES towing_service_types (id) ON DELETE CASCADE
    INDEX idx_towing_price_matrix_class (service_class_id),
    INDEX idx_towing_price_matrix_type (service_type_id),
    INDEX idx_towing_price_matrix_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Waterfall dispatch sequences table for tracking job offer cascades
CREATE TABLE waterfall_dispatch_sequences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence_reference VARCHAR(120) NOT NULL,
    dispatch_requirement_id INT UNSIGNED NULL,
    job_reference VARCHAR(120) NOT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'workorder',
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    offer_timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    max_offers INT UNSIGNED NOT NULL DEFAULT 10,
    current_position INT UNSIGNED NOT NULL DEFAULT 0,
    driver_queue JSON NOT NULL,
    initiated_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    completion_reason VARCHAR(100) NULL,
    assigned_driver_profile_id INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_waterfall_sequence_ref (sequence_reference),
    INDEX idx_waterfall_sequences_status (status),
    INDEX idx_waterfall_sequences_job (job_reference, job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver location tracking for real-time geofencing
CREATE TABLE driver_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10,6) NOT NULL,
    longitude DECIMAL(10,6) NOT NULL,
    accuracy DECIMAL(8,2) NULL,
    altitude DECIMAL(10,2) NULL,
    speed DECIMAL(8,2) NULL,
    heading DECIMAL(6,2) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(40) NULL DEFAULT 'gps',
    INDEX idx_driver_locations_driver (driver_profile_id),
    INDEX idx_driver_locations_time (recorded_at),
    INDEX idx_driver_locations_driver_time (driver_profile_id, recorded_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geofence definitions for automatic state transitions
CREATE TABLE geofences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    geofence_type VARCHAR(40) NOT NULL DEFAULT 'job_site',
    reference_type VARCHAR(40) NULL,
    reference_id INT UNSIGNED NULL,
    latitude DECIMAL(10,6) NOT NULL,
    longitude DECIMAL(10,6) NOT NULL,
    radius_meters INT UNSIGNED NOT NULL DEFAULT 200,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    trigger_on_enter TINYINT(1) NOT NULL DEFAULT 1,
    trigger_on_exit TINYINT(1) NOT NULL DEFAULT 0,
    enter_action VARCHAR(100) NULL,
    exit_action VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geofences_reference (reference_type, reference_id),
    INDEX idx_geofences_type (geofence_type),
    INDEX idx_geofences_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geofence events log
CREATE TABLE geofence_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    geofence_id INT UNSIGNED NOT NULL,
    driver_profile_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    latitude DECIMAL(10,6) NOT NULL,
    longitude DECIMAL(10,6) NOT NULL,
    distance_meters DECIMAL(10,2) NULL,
    action_taken VARCHAR(100) NULL,
    action_result JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geofence_events_geofence (geofence_id),
    INDEX idx_geofence_events_driver (driver_profile_id),
    INDEX idx_geofence_events_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver idle alerts tracking
CREATE TABLE driver_idle_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    job_reference VARCHAR(120) NULL,
    alert_type VARCHAR(40) NOT NULL DEFAULT 'stationary',
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_location_latitude DECIMAL(10,6) NULL,
    last_location_longitude DECIMAL(10,6) NULL,
    stationary_minutes INT UNSIGNED NULL,
    acknowledged_by INT UNSIGNED NULL,
    acknowledged_at DATETIME NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_driver_idle_alerts_driver (driver_profile_id),
    INDEX idx_driver_idle_alerts_status (status),
    INDEX idx_driver_idle_alerts_job (job_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extended driver certifications with expiry tracking
CREATE TABLE driver_certifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    certification_code VARCHAR(60) NOT NULL,
    certification_name VARCHAR(120) NOT NULL,
    issuing_authority VARCHAR(120) NULL,
    certificate_number VARCHAR(120) NULL,
    issued_date DATE NULL,
    expiry_date DATE NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    document_url VARCHAR(500) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_driver_certification (driver_profile_id, certification_code),
    INDEX idx_driver_certifications_driver (driver_profile_id),
    INDEX idx_driver_certifications_expiry (expiry_date),
    INDEX idx_driver_certifications_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipment compatibility matrix for hard filtering
CREATE TABLE equipment_job_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_category VARCHAR(60) NOT NULL,
    equipment_class VARCHAR(60) NOT NULL,
    is_compatible TINYINT(1) NOT NULL DEFAULT 1,
    min_capacity DECIMAL(10,2) NULL,
    required_certifications JSON NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_equipment_job_requirement (job_category, equipment_class),
    INDEX idx_equipment_requirements_category (job_category),
    INDEX idx_equipment_requirements_class (equipment_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver performance metrics for recommendation justification
CREATE TABLE driver_performance_metrics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_accepted INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_declined INT UNSIGNED NOT NULL DEFAULT 0,
    total_distance_km DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    avg_customer_rating DECIMAL(3,2) NULL,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    on_time_arrivals INT UNSIGNED NOT NULL DEFAULT 0,
    late_arrivals INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_driver_metrics_date (driver_profile_id, metric_date),
    INDEX idx_driver_metrics_driver (driver_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job density data for heatmaps
CREATE TABLE job_density_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    snapshot_hour TINYINT UNSIGNED NULL,
    grid_lat DECIMAL(8,4) NOT NULL,
    grid_lng DECIMAL(8,4) NOT NULL,
    job_count INT UNSIGNED NOT NULL DEFAULT 0,
    avg_response_time_minutes INT UNSIGNED NULL,
    job_type VARCHAR(40) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_density_date (snapshot_date),
    INDEX idx_job_density_location (grid_lat, grid_lng),
    INDEX idx_job_density_type (job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dispatch audit log for idempotency and tracking
CREATE TABLE dispatch_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(120) NULL,
    event_type VARCHAR(60) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id INT UNSIGNED NULL,
    job_reference VARCHAR(120) NULL,
    driver_profile_id INT UNSIGNED NULL,
    actor_id INT UNSIGNED NULL,
    event_data JSON NULL,
    result_status VARCHAR(40) NULL,
    result_message TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_dispatch_audit_idempotency (idempotency_key),
    INDEX idx_dispatch_audit_event (event_type),
    INDEX idx_dispatch_audit_entity (entity_type, entity_id),
    INDEX idx_dispatch_audit_job (job_reference),
    INDEX idx_dispatch_audit_driver (driver_profile_id),
    INDEX idx_dispatch_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rejection reasons lookup table
CREATE TABLE offer_rejection_reasons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    category VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_rejection_reason_code (code),
    INDEX idx_rejection_reasons_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adds module enablement settings and user groups for granular access control

-- Module settings at shop/tenant level
CREATE TABLE module_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config JSON NULL COMMENT 'Module-specific configuration options',
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_module_key (module_key),
    INDEX idx_enabled (enabled),
    CONSTRAINT fk_module_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User groups for granular permissions beyond roles
CREATE TABLE user_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description TEXT NULL,
    permissions JSON NOT NULL DEFAULT '[]' COMMENT 'Additional permissions granted to group members',
    disabled_modules JSON NOT NULL DEFAULT '[]' COMMENT 'Modules disabled for this group',
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-assign new users to this group',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_group_name (name),
    INDEX idx_is_default (is_default),
    CONSTRAINT fk_user_groups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: users belong to groups
CREATE TABLE user_group_members (
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id),
    INDEX idx_group_id (group_id),
    CONSTRAINT fk_ugm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugm_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugm_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log for module changes
CREATE TABLE module_access_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_type ENUM('module_toggle', 'group_create', 'group_update', 'group_delete', 'member_add', 'member_remove') NOT NULL,
    module_key VARCHAR(50) NULL,
    group_id INT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    performed_by INT UNSIGNED NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_type (action_type),
    INDEX idx_module_key (module_key),
    INDEX idx_group_id (group_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track which inspection items have been converted to estimates
CREATE TABLE inspection_estimate_conversions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_report_id INT UNSIGNED NOT NULL,
    inspection_item_id INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NOT NULL,
    converted_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_iec_report (inspection_report_id),
    INDEX idx_iec_estimate (estimate_id),
    UNIQUE KEY uk_inspection_item_estimate (inspection_report_id, inspection_item_id, estimate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store inspection-specific recommendations for estimate creation
CREATE TABLE inspection_recommendations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_report_id INT UNSIGNED NOT NULL,
    report_item_id INT UNSIGNED NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    recommended_action TEXT NULL,
    estimated_cost DECIMAL(10,2) NULL,
    status ENUM('pending', 'added_to_estimate', 'declined', 'deferred') NOT NULL DEFAULT 'pending',
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ir_report (inspection_report_id),
    INDEX idx_ir_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Workorder Status-Driven Notifications
-- Allows configurable notification rules when workorder status changes

-- 1. Create notification_templates table
CREATE TABLE notification_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NULL,
    channel VARCHAR(50) NOT NULL DEFAULT 'email',
    subject VARCHAR(255) NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nt_key (template_key),
    INDEX idx_nt_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create workorder_notification_rules table
CREATE TABLE workorder_notification_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_status VARCHAR(50) NOT NULL,
    from_status VARCHAR(50) NULL,
    recipient_type VARCHAR(50) NOT NULL,
    template_key VARCHAR(100) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wnr_status (to_status),
    INDEX idx_wnr_active (active),
    UNIQUE KEY uk_status_recipient (to_status, from_status, recipient_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quality Control (QC) Checklist
-- Implements QC checklists that must be completed before transitioning from repair complete to invoicing

-- QC Templates (reusable checklist definitions)
CREATE TABLE qc_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    service_type_id INT UNSIGNED NULL COMMENT 'Optional: link to specific service type',
    is_default TINYINT(1) DEFAULT 0 COMMENT 'If true, applies to all workorders without specific template',
    active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_qct_service_type (service_type_id),
    INDEX idx_qct_default (is_default),
    INDEX idx_qct_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC Template Items (checklist items within a template)
CREATE TABLE qc_template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) NULL COMMENT 'e.g., Safety, Cleanliness, Functionality, Documentation',
    required TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES qc_templates(id) ON DELETE CASCADE,
    INDEX idx_qcti_template (template_id),
    INDEX idx_qcti_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC Checks (completed QC for a workorder)
CREATE TABLE qc_checks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'in_progress', 'passed', 'failed', 'passed_with_notes') NOT NULL DEFAULT 'pending',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    overall_notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_workorder_template (workorder_id, template_id),
    INDEX idx_qcc_workorder (workorder_id),
    INDEX idx_qcc_status (status),
    INDEX idx_qcc_completed_by (completed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC Check Items (individual item responses)
CREATE TABLE qc_check_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qc_check_id INT UNSIGNED NOT NULL,
    template_item_id INT UNSIGNED NOT NULL,
    passed TINYINT(1) NULL COMMENT 'NULL = not checked, 0 = failed, 1 = passed',
    notes TEXT NULL,
    checked_by INT UNSIGNED NULL,
    checked_at DATETIME NULL,
    FOREIGN KEY (qc_check_id) REFERENCES qc_checks(id) ON DELETE CASCADE,
    FOREIGN KEY (template_item_id) REFERENCES qc_template_items(id) ON DELETE CASCADE,
    INDEX idx_qcci_check (qc_check_id),
    INDEX idx_qcci_passed (passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parts Cart & PartsTech Integration
-- Enables technicians to build parts carts that sync with PartsTech for ordering

-- Parts Cart for workorders
CREATE TABLE parts_carts (
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
CREATE TABLE parts_cart_items (
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

-- Create core_returns table to track individual core transactions
CREATE TABLE core_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Link to source transaction
    workorder_id INT UNSIGNED NULL,
    workorder_item_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    invoice_item_id INT UNSIGNED NULL,

    -- Part info
    inventory_item_id INT UNSIGNED NULL,
    part_description VARCHAR(255) NOT NULL,
    sku VARCHAR(120) NULL,

    -- Core amounts
    core_cost DECIMAL(10, 2) NOT NULL DEFAULT 0,
    core_price DECIMAL(10, 2) NOT NULL DEFAULT 0,

    -- Status tracking
    status ENUM('pending_from_customer', 'received_from_customer', 'pending_to_vendor', 'returned_to_vendor', 'credit_received', 'expired', 'waived') NOT NULL DEFAULT 'pending_from_customer',
    return_sellable TINYINT(1) NULL,
    warranty_follow_up_status VARCHAR(40) NULL,
    warranty_follow_up_reason TEXT NULL,

    -- Customer tracking
    customer_id INT UNSIGNED NULL,
    customer_core_due_date DATE NULL,
    customer_core_received_at TIMESTAMP NULL,
    customer_credited_at TIMESTAMP NULL,

    -- Vendor tracking
    vendor VARCHAR(255) NULL,
    vendor_core_due_date DATE NULL,
    vendor_return_sent_at TIMESTAMP NULL,
    vendor_credit_received_at TIMESTAMP NULL,
    vendor_credit_amount DECIMAL(10, 2) NULL,

    -- Tracking info
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_core_workorder (workorder_id),
    INDEX idx_core_invoice (invoice_id),
    INDEX idx_core_inventory (inventory_item_id),
    INDEX idx_core_customer (customer_id),
    INDEX idx_core_status (status),
    INDEX idx_core_customer_due (customer_core_due_date),
    INDEX idx_core_vendor_due (vendor_core_due_date),

    CONSTRAINT fk_core_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create core_return_history table for audit trail
CREATE TABLE core_return_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    core_return_id INT UNSIGNED NOT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_core_history_return (core_return_id),
    CONSTRAINT fk_core_history_return FOREIGN KEY (core_return_id)
        REFERENCES core_returns (id) ON DELETE CASCADE,
    CONSTRAINT fk_core_history_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create barcode scan log table for audit purposes
-- Fixed: Foreign keys defined INLINE to prevent "Duplicate key" errors on re-runs
CREATE TABLE barcode_scan_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barcode_value VARCHAR(255) NOT NULL,
    scan_type ENUM('inventory_lookup', 'workorder_add', 'invoice_add', 'stock_count', 'receive', 'other') NOT NULL,
    inventory_item_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    success BOOLEAN DEFAULT TRUE,
    error_message VARCHAR(255) NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_scan_barcode (barcode_value),
    INDEX idx_scan_item (inventory_item_id),
    INDEX idx_scan_user (user_id),
    INDEX idx_scan_date (scanned_at),

    -- Foreign Keys defined here are only created if the table is created
    CONSTRAINT fk_scan_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Granular Labor Clocking
-- Allows technicians to clock into specific tasks within a job for efficiency reporting

-- Labor tasks table - predefined tasks with flat-rate times
CREATE TABLE labor_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    flat_rate_minutes DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Standard/expected time for this task',
    labor_rate DECIMAL(10,2) NULL COMMENT 'Optional override labor rate per hour',
    service_type_id INT UNSIGNED NULL COMMENT 'Optional link to service type',
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_labor_tasks_service_type (service_type_id),
    INDEX idx_labor_tasks_active (is_active),
    CONSTRAINT fk_labor_tasks_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_attachments_message (message_id)
    CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES message_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_public_payment_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_public_payment_invoice (invoice_id),
    UNIQUE KEY uniq_invoice_public_payment_token (token_hash),
    CONSTRAINT fk_invoice_public_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_damage_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_damage_media_job (workorder_job_id),
    CONSTRAINT fk_job_damage_media_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_damage_media_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_label VARCHAR(191) NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_by INT UNSIGNED NULL,
    INDEX idx_user_sessions_user (user_id),
    INDEX idx_user_sessions_revoked (revoked_at),
    UNIQUE KEY uniq_user_sessions_session (session_id),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE auction_lots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    impound_case_id INT UNSIGNED NOT NULL,
    lot_number VARCHAR(60) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
    status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    auction_date DATE NULL,
    sale_price DECIMAL(10,2) NULL,
    buyer_name VARCHAR(160) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auction_lots_case (impound_case_id),
    INDEX idx_auction_lots_status (status),
    INDEX idx_auction_lots_date (auction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_payer_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    payer_role ENUM('primary', 'secondary') NOT NULL,
    payer_name VARCHAR(160) NULL,
    allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_payer_allocations_invoice (invoice_id),
    INDEX idx_invoice_payer_allocations_role (payer_role),
    CONSTRAINT fk_invoice_payer_allocations_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_vehicle_intakes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    workorder_job_id INT UNSIGNED NOT NULL,
    vin VARCHAR(30) NULL,
    vehicle_year INT NULL,
    vehicle_make VARCHAR(80) NULL,
    vehicle_model VARCHAR(80) NULL,
    vehicle_trim VARCHAR(80) NULL,
    vehicle_weight_class VARCHAR(80) NULL,
    vin_decoded JSON NULL,
    vin_overrides JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_job_vehicle_intakes_job (workorder_job_id),
    INDEX idx_job_vehicle_intakes_workorder (workorder_id),
    CONSTRAINT fk_job_vehicle_intakes_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_vehicle_intakes_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Truck Checklists (Pre-trip/Post-trip)
-- Adds checklist templates, entries, and driver shift requirements.

CREATE TABLE truck_checklist_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    checklist_type ENUM('pre_trip', 'post_trip') NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tct_type (checklist_type),
    INDEX idx_tct_default (is_default),
    INDEX idx_tct_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE truck_checklist_template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    required TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES truck_checklist_templates(id) ON DELETE CASCADE,
    INDEX idx_tcti_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE truck_checklist_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    driver_shift_id INT UNSIGNED NULL,
    template_id INT UNSIGNED NOT NULL,
    checklist_type ENUM('pre_trip', 'post_trip') NOT NULL,
    status ENUM('completed') NOT NULL DEFAULT 'completed',
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tce_driver (driver_profile_id),
    INDEX idx_tce_shift (driver_shift_id),
    INDEX idx_tce_type (checklist_type),
    INDEX idx_tce_completed_at (completed_at),
    INDEX idx_tce_template (template_id),
    CONSTRAINT fk_tce_template FOREIGN KEY (template_id) REFERENCES truck_checklist_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_tce_driver_profile FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_tce_driver_shift FOREIGN KEY (driver_shift_id) REFERENCES driver_shifts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add deferred foreign keys from driver_shifts to truck_checklist_entries
ALTER TABLE driver_shifts ADD CONSTRAINT fk_driver_shifts_pre_trip FOREIGN KEY (pre_trip_checklist_id) REFERENCES truck_checklist_entries(id) ON DELETE SET NULL;
ALTER TABLE driver_shifts ADD CONSTRAINT fk_driver_shifts_post_trip FOREIGN KEY (post_trip_checklist_id) REFERENCES truck_checklist_entries(id) ON DELETE SET NULL;

CREATE TABLE truck_checklist_entry_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id INT UNSIGNED NOT NULL,
    template_item_id INT UNSIGNED NOT NULL,
    response ENUM('pass', 'fail', 'na') NULL,
    notes TEXT NULL,
    checked_by INT UNSIGNED NULL,
    checked_at DATETIME NULL,
    FOREIGN KEY (entry_id) REFERENCES truck_checklist_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (template_item_id) REFERENCES truck_checklist_template_items(id) ON DELETE CASCADE,
    INDEX idx_tcei_entry (entry_id),
    INDEX idx_tcei_response (response)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Description: Add inventory transaction ledger table

CREATE TABLE inventory_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    quantity_before INT NOT NULL,
    quantity_after INT NOT NULL,
    quantity_change INT NOT NULL,
    source VARCHAR(60) NOT NULL,
    reference VARCHAR(120) NULL,
    reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_transactions_item (inventory_item_id),
    INDEX idx_inventory_transactions_source (source),
    INDEX idx_inventory_transactions_reference (reference),
    CONSTRAINT fk_inventory_transactions_item FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_transactions_user FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_reorder_point_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    previous_override INT NULL,
    new_override INT NULL,
    reason VARCHAR(255) NULL,
    changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_reorder_history_item (inventory_item_id),
    INDEX idx_inventory_reorder_history_user (changed_by),
    CONSTRAINT fk_inventory_reorder_history_item FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_reorder_history_user FOREIGN KEY (changed_by)
        REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory System

CREATE TABLE inventory_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    sku VARCHAR(120) NULL,
    manufacturer_part_number VARCHAR(120) NULL,
    upc VARCHAR(50) NULL,
    barcode VARCHAR(100) NULL,
    barcode_type ENUM('UPC-A', 'UPC-E', 'EAN-13', 'EAN-8', 'CODE-39', 'CODE-128', 'QR', 'custom') NULL,
    category VARCHAR(120) NULL,
    stock_quantity INT DEFAULT 0,
    low_stock_threshold INT DEFAULT 0,
    is_low_stock TINYINT(1) GENERATED ALWAYS AS (CASE WHEN COALESCE(is_tracked, 1) = 1 AND COALESCE(stock_quantity, 0) <= COALESCE(low_stock_threshold, 0) THEN 1 ELSE 0 END) STORED,
    reorder_quantity INT DEFAULT 0,
    reorder_point_override INT NULL,
    reorder_point_override_reason VARCHAR(255) NULL,
    reorder_point_override_updated_at TIMESTAMP NULL,
    reorder_point_override_updated_by INT UNSIGNED NULL,
    cost DECIMAL(12,2) DEFAULT 0,
    core_cost DECIMAL(10, 2) NULL, 
    core_price DECIMAL(10, 2) NULL,
    is_core_eligible BOOLEAN DEFAULT FALSE,
    sale_price DECIMAL(12,2) DEFAULT 0,
    list_price DECIMAL(12,2) DEFAULT 0, 
    markup DECIMAL(6,2) NULL,
    location VARCHAR(160) NULL,
    bin_location VARCHAR(160) NULL,
    vendor VARCHAR(160) NULL,
    notes TEXT NULL,
    partstech_part_id VARCHAR(100) NULL COMMENT 'Cached PartsTech part ID',
    partstech_last_sync DATETIME NULL COMMENT 'Last sync with PartsTech',
    INDEX idx_inv_partstech (partstech_part_id),
    INDEX idx_inventory_upc (upc),
    INDEX idx_inventory_barcode (barcode),
    INDEX idx_inventory_manufacturer_pn (manufacturer_part_number),
    INDEX idx_inventory_is_low_stock (is_low_stock),
    FULLTEXT INDEX idx_inventory_search (name, description),
    INDEX idx_inventory_reorder_override_user (reorder_point_override_updated_by),
    INDEX idx_inventory_sku_prefix (sku(20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_lookups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_parts_supplier TINYINT(1) DEFAULT 0,
    INDEX idx_inventory_lookups_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_reorder_point_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    previous_override INT NULL,
    new_override INT NULL,
    reason VARCHAR(255) NULL,
    changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_reorder_history_item (inventory_item_id),
    INDEX idx_inventory_reorder_history_user (changed_by),
    CONSTRAINT fk_inventory_reorder_history_item FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_reorder_history_user FOREIGN KEY (changed_by)
        REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create inventory vehicle compatibility table
CREATE TABLE inventory_vehicle_compatibility (
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


-- Create inventory pull requests table for workorder parts management
CREATE TABLE inventory_pull_requests (
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


CREATE TABLE inventory_reorder_point_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    previous_override INT NULL,
    new_override INT NULL,
    reason VARCHAR(255) NULL,
    changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_reorder_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_reorder_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Description: Add inventory stock order tracking for replenishment

CREATE TABLE inventory_stock_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NULL,
    sku VARCHAR(120) NULL,
    description VARCHAR(255) NOT NULL,
    quantity_ordered INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('backorder', 'on_order', 'received', 'cancelled') NOT NULL DEFAULT 'on_order',
    expected_arrival_date DATE NULL,
    notes TEXT NULL,
    vendor VARCHAR(160) NULL,
    order_reference VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_order_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_order_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_order_inventory (inventory_item_id),
    INDEX idx_stock_order_status (status),
    INDEX idx_stock_order_expected_arrival (expected_arrival_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_vault_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    document_type VARCHAR(40) NOT NULL,
    category VARCHAR(120) NULL,
    issuing_authority VARCHAR(160) NULL,
    document_number VARCHAR(120) NULL,
    issued_date DATE NULL,
    expiration_date DATE NULL,
    notes TEXT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_document_vault_type (document_type),
    INDEX idx_document_vault_expiration (expiration_date),
    INDEX idx_document_vault_uploaded_by (uploaded_by),
    CONSTRAINT fk_document_vault_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--- Insert Default Datasets

-- Seed initial customer messages from claim descriptions so history is preserved
INSERT INTO warranty_claim_messages (claim_id, actor_type, actor_id, message, created_at, updated_at)
SELECT id, 'customer', customer_id, description, created_at, updated_at FROM warranty_claims;

-- Insert default settings for core tracking
INSERT IGNORE INTO settings (`key`, `group`, type, value, description) VALUES
('inventory.core_tracking.enabled', 'inventory', 'boolean', 'true', 'Enable core return tracking'),
('inventory.core_tracking.customer_return_days', 'inventory', 'integer', '30', 'Days allowed for customer to return core'),
('inventory.core_tracking.vendor_return_days', 'inventory', 'integer', '45', 'Days allowed to return core to vendor'),
('inventory.core_tracking.alert_days_before_due', 'inventory', 'integer', '7', 'Days before due date to show alert');

-- Job tracking link notification template

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES (
    'job_tracking_link',
    'Job Tracking Link - Customer SMS',
    'sms',
    'Tracking Link',
    'Hi {customer_name}, track your service for workorder {workorder_number} here: {job_tracking_link}',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    channel = VALUES(channel),
    subject = VALUES(subject),
    body = VALUES(body),
    updated_at = NOW();

-- Partition driver_locations by month
-- Note: Uses RANGE with UNIX_TIMESTAMP for TIMESTAMP columns
-- Default templates
INSERT INTO truck_checklist_templates (name, description, checklist_type, is_default, active, created_at, updated_at)
VALUES
    ('Standard Pre-Trip Checklist', 'Default pre-trip checklist for tow trucks', 'pre_trip', 1, 1, NOW(), NOW()),
    ('Standard Post-Trip Checklist', 'Default post-trip checklist for tow trucks', 'post_trip', 1, 1, NOW(), NOW());

INSERT INTO truck_checklist_template_items (template_id, label, description, required, display_order) VALUES
(@pre_trip_template_id, 'Check tires (tread/pressure)', 'Inspect all tires for proper inflation and tread wear', 1, 1),
(@pre_trip_template_id, 'Lights & signals operational', 'Headlights, brake lights, turn signals, hazard lights', 1, 2),
(@pre_trip_template_id, 'Hydraulic system check', 'Verify hydraulics are functioning and leak-free', 1, 3),
(@pre_trip_template_id, 'Winch & cables inspected', 'Inspect winch operation and cable condition', 1, 4),
(@pre_trip_template_id, 'Safety equipment onboard', 'Cones, flares, fire extinguisher, PPE', 1, 5),
(@pre_trip_template_id, 'Fuel level sufficient', 'Verify fuel level for scheduled shift', 1, 6);

INSERT INTO truck_checklist_template_items (template_id, label, description, required, display_order) VALUES
(@post_trip_template_id, 'Vehicle damage noted', 'Record any new damage or issues', 1, 1),
(@post_trip_template_id, 'Equipment cleaned & secured', 'Secure chains, straps, and tools', 1, 2),
(@post_trip_template_id, 'Fuel level recorded', 'Record remaining fuel level', 1, 3),
(@post_trip_template_id, 'Odometer recorded', 'Log ending mileage', 1, 4),
(@post_trip_template_id, 'Issues reported to dispatch', 'Report any incidents or maintenance needs', 1, 5);

-- Insert a default template
INSERT INTO cms_templates (name, slug, description, structure, is_active) VALUES
('Default', 'default', 'Default page template', '<div class="container">{{content}}</div>', 1);

-- Insert default settings
INSERT INTO cms_settings (setting_key, setting_value, setting_type, description) VALUES
('site_name', 'My Website', 'string', 'The name of the website'),
('site_description', 'Welcome to our website', 'string', 'Site meta description'),
('cache_enabled', 'true', 'boolean', 'Enable page caching'),
('cache_ttl', '3600', 'number', 'Default cache TTL in seconds');

-- Insert existing system roles for backwards compatibility
INSERT INTO custom_roles (name, label, description, permissions, is_system) VALUES
('admin', 'Admin', 'Full control across all modules', JSON_ARRAY('*'), 1),
('manager', 'Manager', 'Manage shop operations, estimates, invoices, schedules, inventory', JSON_ARRAY('users.view', 'users.create', 'users.update', 'users.delete', 'users.invite', 'customers.*', 'vehicles.*', 'estimates.*', 'invoices.*', 'payments.*', 'appointments.*', 'inventory.*', 'inspections.*', 'warranty.*', 'reminders.*', 'bundles.*', 'time.*', 'credit.*', 'reports.view', 'documents.*', 'settings.view', 'notifications.view', 'service_types.*', 'cms.*'), 1),
('technician', 'Technician', 'Work estimates, inspections, jobs, and time tracking', JSON_ARRAY('customers.view', 'vehicles.view', 'estimates.view', 'estimates.create', 'estimates.update', 'inspections.*', 'time.*', 'appointments.view', 'service_types.view', 'cms.pages.view', 'cms.pages.create', 'cms.pages.update', 'cms.pages.delete', 'cms.menus.view', 'cms.menus.create', 'cms.menus.update', 'cms.menus.delete', 'cms.media.view', 'cms.media.create', 'cms.media.update', 'cms.media.delete', 'cms.components.view', 'cms.components.create', 'cms.components.update', 'cms.components.delete', 'cms.dashboard.view', 'cms.templates.view'), 1),
('customer', 'Customer', 'Customer portal scoped to their profile and documents', JSON_ARRAY('portal.profile', 'portal.vehicles', 'portal.estimates', 'portal.invoices', 'portal.warranty', 'portal.reminders'), 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), permissions = VALUES(permissions);

INSERT INTO cms_components (name, slug, type, description, content, css, javascript, is_active, cache_ttl, created_at, updated_at
) VALUES ('Estimate Request Form',
    'estimate-request', 'custom', 'Public-facing estimate request form with vehicle selection, service details, and photo upload', '<div data-vue-component="EstimateRequestForm" class="estimate-request-form-container"></div>', '/* Ensure form has proper spacing */ .estimate-request-form-container { width: 100%; max-width: 100%; padding: 0; margin: 0 auto; }', NULL, 1, 0, NOW(), NOW());

-- Seed default service classes
INSERT INTO towing_service_classes (name, description, weight_min, weight_max, sort_order) VALUES
    ('Light Duty', 'Standard passenger vehicles, motorcycles, small trucks', NULL, 10000, 1),
    ('Medium Duty', 'Box trucks, RVs, small buses, large pickup trucks', 10001, 26000, 2),
    ('Heavy Duty', 'Semi-trucks, large buses, heavy equipment', 26001, NULL, 3),
    ('Motorcycle', 'Motorcycles and scooters', NULL, 1500, 4)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Seed default service types
INSERT INTO towing_service_types (name, code, description, sort_order) VALUES
    ('Standard Tow', 'TOW', 'Standard towing service', 1),
    ('Winch Out', 'WINCH', 'Vehicle recovery using winch', 2),
    ('Jump Start', 'JUMP', 'Battery jump start service', 3),
    ('Tire Change', 'TIRE', 'Flat tire replacement/change', 4),
    ('Fuel Delivery', 'FUEL', 'Emergency fuel delivery', 5),
    ('Lockout', 'LOCK', 'Vehicle lockout assistance', 6),
    ('Accident Recovery', 'ACCIDENT', 'Accident scene recovery and cleanup', 7),
    ('Flatbed Tow', 'FLATBED', 'Flatbed towing for specialty vehicles', 8)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Insert default rejection reasons
INSERT IGNORE INTO offer_rejection_reasons (code, display_name, category, display_order) VALUES
    ('too_far', 'Too far away', 'distance', 1),
    ('heavy_traffic', 'Heavy traffic on route', 'distance', 2),
    ('equipment_issue', 'Equipment issue or unavailable', 'equipment', 3),
    ('wrong_equipment', 'Job requires different equipment', 'equipment', 4),
    ('shift_ending', 'Shift ending soon', 'availability', 5),
    ('already_assigned', 'Already on another job', 'availability', 6),
    ('personal_emergency', 'Personal emergency', 'availability', 7),
    ('vehicle_breakdown', 'Vehicle breakdown', 'equipment', 8),
    ('certification_expired', 'Required certification expired', 'qualification', 9),
    ('unfamiliar_area', 'Unfamiliar with area', 'distance', 10),
    ('weather_conditions', 'Unsafe weather conditions', 'safety', 11),
    ('other', 'Other reason', 'other', 99);

-- Insert default equipment job requirements
INSERT IGNORE INTO equipment_job_requirements (job_category, equipment_class, is_compatible, min_capacity, required_certifications) VALUES
    ('flatbed_tow', 'flatbed', 1, NULL, NULL),
    ('flatbed_tow', 'light_tow', 0, NULL, NULL),
    ('flatbed_tow', 'heavy_duty', 1, NULL, NULL),
    ('light_tow', 'light_tow', 1, NULL, NULL),
    ('light_tow', 'flatbed', 1, NULL, NULL),
    ('light_tow', 'heavy_duty', 1, NULL, NULL),
    ('heavy_tow', 'heavy_duty', 1, 10000, NULL),
    ('heavy_tow', 'flatbed', 0, NULL, NULL),
    ('heavy_tow', 'light_tow', 0, NULL, NULL),
    ('motorcycle_tow', 'flatbed', 1, NULL, '["MOTORCYCLE"]'),
    ('motorcycle_tow', 'light_tow', 0, NULL, NULL),
    ('tire_change', 'light_tow', 1, NULL, NULL),
    ('tire_change', 'flatbed', 1, NULL, NULL),
    ('tire_change', 'service_vehicle', 1, NULL, NULL),
    ('jump_start', 'light_tow', 1, NULL, NULL),
    ('jump_start', 'flatbed', 1, NULL, NULL),
    ('jump_start', 'service_vehicle', 1, NULL, NULL),
    ('fuel_delivery', 'light_tow', 1, NULL, NULL),
    ('fuel_delivery', 'service_vehicle', 1, NULL, NULL),
    ('lockout', 'light_tow', 1, NULL, '["LOCKSMITH"]'),
    ('lockout', 'service_vehicle', 1, NULL, '["LOCKSMITH"]'),
    ('winch_out', 'light_tow', 1, NULL, NULL),
    ('winch_out', 'heavy_duty', 1, NULL, NULL),
    ('hazmat_recovery', 'heavy_duty', 1, NULL, '["HAZMAT"]');

-- Insert default module settings (all enabled by default)
INSERT IGNORE INTO module_settings (module_key, enabled, config) VALUES
    ('core', 1, '{"description": "Core shop operations - cannot be disabled"}'),
    ('estimates', 1, '{"description": "Estimates and quotes"}'),
    ('workorders', 1, '{"description": "Work orders and job tracking"}'),
    ('invoicing', 1, '{"description": "Invoicing and payments"}'),
    ('appointments', 1, '{"description": "Appointment scheduling"}'),
    ('inventory', 1, '{"description": "Parts and inventory management"}'),
    ('towing', 1, '{"description": "Towing, roadside assistance, and dispatch"}'),
    ('cms', 1, '{"description": "Website content management"}'),
    ('impound', 0, '{"description": "Impound and vehicle storage"}'),
    ('inspections', 1, '{"description": "Vehicle inspections"}'),
    ('warranty', 1, '{"description": "Warranty claims management"}'),
    ('time_tracking', 1, '{"description": "Technician time tracking"}'),
    ('messaging', 1, '{"description": "Customer messaging and notifications"}'),
    ('reminders', 1, '{"description": "Reminder campaigns"}'),
    ('customer_portal', 1, '{"description": "Customer self-service portal"}'),
    ('reports', 1, '{"description": "Reports and analytics"}');

-- Insert default user group
INSERT IGNORE INTO user_groups (name, description, permissions, disabled_modules, is_default) VALUES
    ('All Staff', 'Default group for all staff members', '[]', '[]', 1);

-- 3. Insert templates (One by one for stability)

-- Parts Pending
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_parts_pending', 'Workorder Parts Pending', 'email', 'Parts Required for Workorder {workorder_number}', 'Hello {recipient_name},\n\nWorkorder {workorder_number} for {vehicle_info} is now waiting for parts.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\n\nPlease review and order necessary parts.\n\nView workorder: {workorder_link}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_parts_pending_manager', 'Workorder Parts Pending - Manager', 'email', 'Parts Pending Alert: {workorder_number}', 'Manager Alert: Workorder {workorder_number} is waiting for parts.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nTotal: ${grand_total}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- In Progress
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_in_progress', 'Workorder In Progress - Customer', 'email', 'Work Has Started on Your Vehicle - {workorder_number}', 'Hello {customer_name},\n\nGreat news! Work has begun on your {vehicle_info}.\n\nWorkorder: {workorder_number}\nTechnician: {technician_name}\n\nWe will notify you when the work is complete.\n\nThank you for your business!', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- On Hold
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_on_hold', 'Workorder On Hold - Manager', 'email', 'Workorder {workorder_number} Placed On Hold', 'Alert: Workorder {workorder_number} has been placed on hold.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nPrevious Status: {from_status}\n\nPlease review and take action.', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_on_hold_tech', 'Workorder On Hold - Technician', 'email', 'Your Workorder {workorder_number} Is On Hold', 'Hello {technician_name},\n\nWorkorder {workorder_number} that you were assigned to has been placed on hold.\n\nVehicle: {vehicle_info}\nCustomer: {customer_name}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Completed
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_completed', 'Workorder Completed - Customer', 'email', 'Your Vehicle Service Is Complete - {workorder_number}', 'Hello {customer_name},\n\nThe service on your {vehicle_info} has been completed!\n\nWorkorder: {workorder_number}\nTotal: ${grand_total}\n\nPlease contact us to schedule pickup.\n\nThank you for choosing us!', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_completed_manager', 'Workorder Completed - Manager', 'email', 'Workorder Completed: {workorder_number}', 'Workorder {workorder_number} has been marked as complete.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nTotal: ${grand_total}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Ready for Pickup
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_ready_pickup', 'Ready for Pickup - Customer Email', 'email', 'Your Vehicle Is Ready for Pickup! - {workorder_number}', 'Hello {customer_name},\n\nYour {vehicle_info} is ready for pickup!\n\nWorkorder: {workorder_number}\nTotal Due: ${grand_total}\n\nOur business hours are:\nMonday - Friday: 8:00 AM - 6:00 PM\nSaturday: 9:00 AM - 3:00 PM\n\nPlease bring a valid ID for vehicle release.\n\nThank you for your business!', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_ready_pickup_sms', 'Ready for Pickup - Customer SMS', 'sms', 'Vehicle Ready', 'Your {vehicle_info} is ready for pickup! Total: ${grand_total}. Workorder #{workorder_number}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Awaiting Authorization
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_awaiting_auth', 'Awaiting Authorization - Customer Email', 'email', 'Authorization Required for Additional Work - {workorder_number}', 'Hello {customer_name},\n\nDuring service on your {vehicle_info}, we discovered additional work that requires your authorization.\n\nWorkorder: {workorder_number}\nEstimated Additional Cost: ${grand_total}\n\nPlease review and approve the estimate to proceed.\n\nView and approve: {portal_link}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_awaiting_auth_sms', 'Awaiting Authorization - Customer SMS', 'sms', 'Authorization Needed', 'Additional work needed on your {vehicle_info}. Please review estimate for Workorder #{workorder_number}. Total: ${grand_total}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Cancelled
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_cancelled', 'Workorder Cancelled - Customer', 'email', 'Workorder {workorder_number} Has Been Cancelled', 'Hello {customer_name},\n\nWorkorder {workorder_number} for your {vehicle_info} has been cancelled.\n\nIf you have any questions, please contact us.\n\nThank you.', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_cancelled_tech', 'Workorder Cancelled - Technician', 'email', 'Workorder {workorder_number} Cancelled', 'Hello {technician_name},\n\nWorkorder {workorder_number} that you were assigned to has been cancelled.\n\nVehicle: {vehicle_info}\nCustomer: {customer_name}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- 4. Insert Rules
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('parts_pending', NULL, 'role:manager', 'workorder_parts_pending_manager', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('in_progress', NULL, 'customer', 'workorder_in_progress', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('on_hold', NULL, 'role:manager', 'workorder_on_hold', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('completed', NULL, 'customer', 'workorder_completed', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('ready_for_pickup', NULL, 'customer', 'workorder_ready_pickup', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('ready_for_pickup', NULL, 'customer_sms', 'workorder_ready_pickup_sms', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('awaiting_authorization', NULL, 'customer', 'workorder_awaiting_auth', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('awaiting_authorization', NULL, 'customer_sms', 'workorder_awaiting_auth_sms', 1);

INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('cancelled', NULL, 'customer', 'workorder_cancelled', 1);

-- Settings for QC enforcement
INSERT INTO settings (`key`, `group`, `type`, `value`, description, created_at, updated_at)
VALUES
    ('qc_enabled', 'workflow', 'boolean', 'true', 'Enable QC checklist requirement before invoicing', NOW(), NOW()),
    ('qc_auto_assign_template', 'workflow', 'boolean', 'true', 'Automatically assign default QC template to workorders', NOW(), NOW()),
    ('qc_required_for_invoice', 'workflow', 'boolean', 'true', 'Require QC pass before converting workorder to invoice', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Insert a default QC template with common items
INSERT INTO qc_templates (name, description, is_default, active, created_at, updated_at)
VALUES ('Standard QC Checklist', 'Default quality control checklist for all repairs', 1, 1, NOW(), NOW());

SET @default_template_id = LAST_INSERT_ID();

INSERT INTO qc_template_items (template_id, label, description, category, required, display_order) VALUES
(@default_template_id, 'All repairs completed per work order', 'Verify all work items marked as complete', 'Completion', 1, 1),
(@default_template_id, 'Parts properly installed', 'Check that all new parts are correctly installed', 'Functionality', 1, 2),
(@default_template_id, 'No fluid leaks', 'Inspect for oil, coolant, brake fluid, or other leaks', 'Safety', 1, 3),
(@default_template_id, 'Test drive completed (if applicable)', 'Verify vehicle operation during road test', 'Functionality', 0, 4),
(@default_template_id, 'Warning lights cleared', 'Confirm no dashboard warning lights are on', 'Functionality', 1, 5),
(@default_template_id, 'Vehicle exterior cleaned', 'Vehicle washed or wiped down before return', 'Cleanliness', 0, 6),
(@default_template_id, 'Interior clean and free of debris', 'Floor mats and seats clean, no tools left behind', 'Cleanliness', 1, 7),
(@default_template_id, 'Old parts available for customer (if requested)', 'Collect replaced parts for customer inspection', 'Documentation', 0, 8),
(@default_template_id, 'Work order documentation complete', 'All notes, times, and parts recorded accurately', 'Documentation', 1, 9),
(@default_template_id, 'Customer communication notes added', 'Any verbal agreements or instructions documented', 'Documentation', 0, 10);

-- PartsTech Integration Settings
INSERT INTO settings (`key`, `group`, `type`, `value`, description, created_at, updated_at)
VALUES
    ('partstech_enabled', 'integrations', 'boolean', 'false', 'Enable PartsTech integration for parts ordering', NOW(), NOW()),
    ('partstech_api_key', 'integrations', 'string', '', 'PartsTech API key', NOW(), NOW()),
    ('partstech_shop_id', 'integrations', 'string', '', 'PartsTech shop/account identifier', NOW(), NOW()),
    ('partstech_default_markup', 'integrations', 'number', '30', 'Default markup percentage on parts cost', NOW(), NOW()),
    ('parts_cart_requires_approval', 'workflow', 'boolean', 'true', 'Require manager approval before ordering parts', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Insert settings for barcode scanning
INSERT IGNORE INTO settings (`key`, `group`, type, value, description) VALUES
('inventory.barcode.enabled', 'inventory', 'boolean', 'true', 'Enable barcode scanning'),
('inventory.barcode.auto_add_to_cart', 'inventory', 'boolean', 'true', 'Automatically add scanned item to cart/workorder'),
('inventory.barcode.beep_on_success', 'inventory', 'boolean', 'true', 'Play sound on successful scan'),
('inventory.barcode.preferred_camera', 'inventory', 'string', 'environment', 'Preferred camera for scanning (user/environment)');

-- Insert common labor tasks with industry-standard flat rates
INSERT INTO labor_tasks (name, description, flat_rate_minutes, display_order) VALUES
('Oil Change', 'Standard engine oil and filter replacement', 30, 1),
('Brake Pad Replacement - Front', 'Front brake pad replacement', 60, 2),
('Brake Pad Replacement - Rear', 'Rear brake pad replacement', 60, 3),
('Brake Rotor Replacement - Front', 'Front brake rotor replacement (includes pads)', 90, 4),
('Brake Rotor Replacement - Rear', 'Rear brake rotor replacement (includes pads)', 90, 5),
('Tire Rotation', 'Rotate all four tires', 20, 6),
('Tire Balance', 'Balance all four tires', 30, 7),
('Tire Replacement', 'Mount and balance single tire', 20, 8),
('Battery Replacement', 'Replace vehicle battery', 15, 9),
('Spark Plug Replacement', 'Replace spark plugs (standard 4-cyl)', 60, 10),
('Air Filter Replacement', 'Replace engine air filter', 10, 11),
('Cabin Filter Replacement', 'Replace cabin air filter', 15, 12),
('Coolant Flush', 'Flush and refill cooling system', 45, 13),
('Transmission Fluid Change', 'Drain and refill transmission fluid', 45, 14),
('Brake Fluid Flush', 'Flush and refill brake fluid', 45, 15),
('Power Steering Flush', 'Flush and refill power steering fluid', 30, 16),
('Wheel Alignment', 'Four-wheel alignment', 60, 17),
('Diagnostic Scan', 'Computer diagnostic and code reading', 30, 18),
('Check Engine Light Diagnosis', 'Full diagnosis of check engine light issue', 60, 19),
('A/C Recharge', 'Recharge air conditioning system', 45, 20),
('Belt Replacement', 'Replace serpentine or drive belt', 45, 21),
('Alternator Replacement', 'Replace alternator', 90, 22),
('Starter Replacement', 'Replace starter motor', 90, 23),
('Water Pump Replacement', 'Replace water pump', 180, 24),
('Thermostat Replacement', 'Replace engine thermostat', 60, 25),
('Fuel Filter Replacement', 'Replace fuel filter', 30, 26),
('Wiper Blade Replacement', 'Replace windshield wiper blades', 5, 27),
('Light Bulb Replacement', 'Replace headlight/taillight bulb', 15, 28),
('Multi-Point Inspection', 'Comprehensive vehicle inspection', 30, 29),
('State Inspection', 'State safety and/or emissions inspection', 30, 30);

-- Create view for technician efficiency reporting
CREATE OR REPLACE VIEW technician_efficiency AS
SELECT
    te.technician_id,
    u.name AS technician_name,
    DATE(te.started_at) AS work_date,
    COUNT(te.id) AS tasks_completed,
    SUM(te.duration_minutes) AS actual_minutes,
    SUM(te.flat_rate_minutes) AS flat_rate_minutes,
    CASE
        WHEN SUM(te.duration_minutes) > 0 THEN
            ROUND((SUM(te.flat_rate_minutes) / SUM(te.duration_minutes)) * 100, 2)
        ELSE 0
    END AS efficiency_percentage,
    SUM(CASE WHEN te.duration_minutes <= te.flat_rate_minutes THEN 1 ELSE 0 END) AS under_time_count,
    SUM(CASE WHEN te.duration_minutes > te.flat_rate_minutes THEN 1 ELSE 0 END) AS over_time_count
FROM time_entries te
JOIN users u ON u.id = te.technician_id
WHERE te.ended_at IS NOT NULL
    AND te.status = 'approved'
    AND te.flat_rate_minutes IS NOT NULL
    AND te.flat_rate_minutes > 0
GROUP BY te.technician_id, u.name, DATE(te.started_at);
