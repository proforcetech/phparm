-- Track message threads tied to system notification scopes
CREATE TABLE IF NOT EXISTS message_notification_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope_type VARCHAR(60) NOT NULL,
    scope_id VARCHAR(120) NOT NULL,
    thread_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message_notification_scope (scope_type, scope_id),
    CONSTRAINT fk_message_notification_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
