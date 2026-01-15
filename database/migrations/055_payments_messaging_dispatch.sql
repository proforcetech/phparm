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
    INDEX idx_driver_push_tokens_driver (driver_profile_id)
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
    INDEX idx_driver_job_offers_reference (job_reference)
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
    INDEX idx_masked_sms_sessions_masked (masked_number)
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
    INDEX idx_masked_sms_messages_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_driver_push_tokens_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_push_tokens' AND constraint_name = 'fk_driver_push_tokens_driver'
);
SET @has_driver_push_tokens_driver_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'driver_profiles'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'driver_push_tokens'
      AND c.column_name = 'driver_profile_id'
);
SET @fk_driver_push_tokens_driver_sql := IF(@has_fk_driver_push_tokens_driver = 0 AND @has_driver_push_tokens_driver_match = 1,
    'ALTER TABLE driver_push_tokens ADD CONSTRAINT fk_driver_push_tokens_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_push_tokens_driver_stmt FROM @fk_driver_push_tokens_driver_sql;
EXECUTE fk_driver_push_tokens_driver_stmt;
DEALLOCATE PREPARE fk_driver_push_tokens_driver_stmt;

SET @has_fk_driver_job_offers_driver := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_job_offers' AND constraint_name = 'fk_driver_job_offers_driver'
);
SET @has_driver_job_offers_driver_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'driver_profiles'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'driver_job_offers'
      AND c.column_name = 'driver_profile_id'
);
SET @fk_driver_job_offers_driver_sql := IF(@has_fk_driver_job_offers_driver = 0 AND @has_driver_job_offers_driver_match = 1,
    'ALTER TABLE driver_job_offers ADD CONSTRAINT fk_driver_job_offers_driver FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_driver_job_offers_driver_stmt FROM @fk_driver_job_offers_driver_sql;
EXECUTE fk_driver_job_offers_driver_stmt;
DEALLOCATE PREPARE fk_driver_job_offers_driver_stmt;

SET @has_fk_driver_job_offers_creator := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'driver_job_offers' AND constraint_name = 'fk_driver_job_offers_creator'
);
SET @has_driver_job_offers_creator_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'driver_job_offers'
      AND c.column_name = 'created_by'
);
SET @fk_driver_job_offers_creator_sql := IF(@has_fk_driver_job_offers_creator = 0 AND @has_driver_job_offers_creator_match = 1,
    'ALTER TABLE driver_job_offers ADD CONSTRAINT fk_driver_job_offers_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_driver_job_offers_creator_stmt FROM @fk_driver_job_offers_creator_sql;
EXECUTE fk_driver_job_offers_creator_stmt;
DEALLOCATE PREPARE fk_driver_job_offers_creator_stmt;

SET @has_fk_masked_sms_driver_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'masked_sms_sessions' AND constraint_name = 'fk_masked_sms_driver_user'
);
SET @has_masked_sms_driver_user_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'masked_sms_sessions'
      AND c.column_name = 'driver_user_id'
);
SET @fk_masked_sms_driver_user_sql := IF(@has_fk_masked_sms_driver_user = 0 AND @has_masked_sms_driver_user_match = 1,
    'ALTER TABLE masked_sms_sessions ADD CONSTRAINT fk_masked_sms_driver_user FOREIGN KEY (driver_user_id) REFERENCES users (id)',
    'SELECT 1');
PREPARE fk_masked_sms_driver_user_stmt FROM @fk_masked_sms_driver_user_sql;
EXECUTE fk_masked_sms_driver_user_stmt;
DEALLOCATE PREPARE fk_masked_sms_driver_user_stmt;

SET @has_fk_masked_sms_customer := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'masked_sms_sessions' AND constraint_name = 'fk_masked_sms_customer'
);
SET @has_masked_sms_customer_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'customers'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'masked_sms_sessions'
      AND c.column_name = 'customer_id'
);
SET @fk_masked_sms_customer_sql := IF(@has_fk_masked_sms_customer = 0 AND @has_masked_sms_customer_match = 1,
    'ALTER TABLE masked_sms_sessions ADD CONSTRAINT fk_masked_sms_customer FOREIGN KEY (customer_id) REFERENCES customers (id)',
    'SELECT 1');
PREPARE fk_masked_sms_customer_stmt FROM @fk_masked_sms_customer_sql;
EXECUTE fk_masked_sms_customer_stmt;
DEALLOCATE PREPARE fk_masked_sms_customer_stmt;

SET @has_fk_masked_sms_messages_session := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'masked_sms_messages' AND constraint_name = 'fk_masked_sms_messages_session'
);
SET @has_masked_sms_messages_session_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'masked_sms_sessions'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'masked_sms_messages'
      AND c.column_name = 'session_id'
);
SET @fk_masked_sms_messages_session_sql := IF(@has_fk_masked_sms_messages_session = 0 AND @has_masked_sms_messages_session_match = 1,
    'ALTER TABLE masked_sms_messages ADD CONSTRAINT fk_masked_sms_messages_session FOREIGN KEY (session_id) REFERENCES masked_sms_sessions (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_masked_sms_messages_session_stmt FROM @fk_masked_sms_messages_session_sql;
EXECUTE fk_masked_sms_messages_session_stmt;
DEALLOCATE PREPARE fk_masked_sms_messages_session_stmt;
