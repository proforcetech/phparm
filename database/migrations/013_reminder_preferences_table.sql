CREATE TABLE reminder_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
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
