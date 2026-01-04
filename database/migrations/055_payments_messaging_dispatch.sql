-- Add payment transactions, masked SMS sessions, driver push tokens, and job offers

CREATE TABLE IF NOT EXISTS payment_transactions (
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

CREATE TABLE IF NOT EXISTS driver_push_tokens (
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

CREATE TABLE IF NOT EXISTS driver_job_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_profile_id INT UNSIGNED NOT NULL,
    job_reference VARCHAR(120) NOT NULL,
    job_type VARCHAR(40) NOT NULL DEFAULT 'workorder',
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    offer_payload JSON NULL,
    created_by INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    accepted_at DATETIME NULL,
    declined_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_driver_job_offers_driver (driver_profile_id),
    INDEX idx_driver_job_offers_status (status),
    INDEX idx_driver_job_offers_reference (job_reference),
    CONSTRAINT fk_driver_job_offers_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_job_offers_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS masked_sms_sessions (
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
    CONSTRAINT fk_masked_sms_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS masked_sms_messages (
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
    INDEX idx_masked_sms_messages_direction (direction),
    CONSTRAINT fk_masked_sms_messages_session FOREIGN KEY (session_id) REFERENCES masked_sms_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
