-- Impound storage tables for cases, rates, fees, and lien notices

CREATE TABLE IF NOT EXISTS impound_cases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_number VARCHAR(60) NOT NULL,
    customer_id INT UNSIGNED NULL,
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
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_impound_cases_case_number (case_number),
    INDEX idx_impound_cases_status (status),
    INDEX idx_impound_cases_state (state_code),
    INDEX idx_impound_cases_vehicle (customer_vehicle_id),
    CONSTRAINT fk_impound_cases_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_impound_cases_vehicle FOREIGN KEY (customer_vehicle_id) REFERENCES customer_vehicles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_rates (
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
    INDEX idx_storage_rates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_fees (
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
    INDEX idx_storage_fees_status (status),
    CONSTRAINT fk_storage_fees_case FOREIGN KEY (impound_case_id) REFERENCES impound_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lien_notices (
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
