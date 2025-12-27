-- Check and add description column
SET @has_description := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'description'
);
SET @description_sql := IF(@has_description = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN description TEXT NULL AFTER name', 'SELECT 1');
PREPARE description_stmt FROM @description_sql;
EXECUTE description_stmt;
DEALLOCATE PREPARE description_stmt;

-- Check and add frequency_unit column
SET @has_frequency_unit := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'frequency_unit'
);
SET @frequency_unit_sql := IF(@has_frequency_unit = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN frequency_unit VARCHAR(20) NOT NULL DEFAULT \'day\' AFTER frequency', 'SELECT 1');
PREPARE frequency_unit_stmt FROM @frequency_unit_sql;
EXECUTE frequency_unit_stmt;
DEALLOCATE PREPARE frequency_unit_stmt;

-- Check and add frequency_interval column
SET @has_frequency_interval := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'frequency_interval'
);
SET @frequency_interval_sql := IF(@has_frequency_interval = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN frequency_interval INT NOT NULL DEFAULT 1 AFTER frequency_unit', 'SELECT 1');
PREPARE frequency_interval_stmt FROM @frequency_interval_sql;
EXECUTE frequency_interval_stmt;
DEALLOCATE PREPARE frequency_interval_stmt;

-- Check and add email_subject column
SET @has_email_subject := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'email_subject'
);
SET @email_subject_sql := IF(@has_email_subject = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN email_subject VARCHAR(255) NULL AFTER status', 'SELECT 1');
PREPARE email_subject_stmt FROM @email_subject_sql;
EXECUTE email_subject_stmt;
DEALLOCATE PREPARE email_subject_stmt;

-- Check and add email_body column
SET @has_email_body := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'email_body'
);
SET @email_body_sql := IF(@has_email_body = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN email_body TEXT NULL AFTER email_subject', 'SELECT 1');
PREPARE email_body_stmt FROM @email_body_sql;
EXECUTE email_body_stmt;
DEALLOCATE PREPARE email_body_stmt;

-- Check and add sms_body column
SET @has_sms_body := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'sms_body'
);
SET @sms_body_sql := IF(@has_sms_body = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN sms_body TEXT NULL AFTER email_body', 'SELECT 1');
PREPARE sms_body_stmt FROM @sms_body_sql;
EXECUTE sms_body_stmt;
DEALLOCATE PREPARE sms_body_stmt;

-- Check and add created_at column
SET @has_created_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'created_at'
);
SET @created_at_sql := IF(@has_created_at = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER next_run_at', 'SELECT 1');
PREPARE created_at_stmt FROM @created_at_sql;
EXECUTE created_at_stmt;
DEALLOCATE PREPARE created_at_stmt;

-- Check and add updated_at column
SET @has_updated_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'reminder_campaigns' AND column_name = 'updated_at'
);
SET @updated_at_sql := IF(@has_updated_at = 0,
    'ALTER TABLE reminder_campaigns ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'SELECT 1');
PREPARE updated_at_stmt FROM @updated_at_sql;
EXECUTE updated_at_stmt;
DEALLOCATE PREPARE updated_at_stmt;

CREATE TABLE IF NOT EXISTS reminder_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    preference_id INT UNSIGNED NULL,
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
