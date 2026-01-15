CREATE TABLE IF NOT EXISTS message_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_attachments_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_message_attachments_message := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_attachments' AND constraint_name = 'fk_message_attachments_message'
);
SET @has_message_attachments_message_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'message_messages'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_attachments'
      AND c.column_name = 'message_id'
);
SET @fk_message_attachments_message_sql := IF(@has_fk_message_attachments_message = 0 AND @has_message_attachments_message_match = 1,
    'ALTER TABLE message_attachments ADD CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES message_messages (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_message_attachments_message_stmt FROM @fk_message_attachments_message_sql;
EXECUTE fk_message_attachments_message_stmt;
DEALLOCATE PREPARE fk_message_attachments_message_stmt;
