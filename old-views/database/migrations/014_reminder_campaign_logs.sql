ALTER TABLE reminder_campaigns
    ADD COLUMN description TEXT NULL AFTER name,
    ADD COLUMN frequency_unit VARCHAR(20) NOT NULL DEFAULT 'day' AFTER frequency,
    ADD COLUMN frequency_interval INT NOT NULL DEFAULT 1 AFTER frequency_unit,
    ADD COLUMN email_subject VARCHAR(255) NULL AFTER status,
    ADD COLUMN email_body TEXT NULL AFTER email_subject,
    ADD COLUMN sms_body TEXT NULL AFTER email_body,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER next_run_at,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    MODIFY channel VARCHAR(20) NOT NULL;

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
