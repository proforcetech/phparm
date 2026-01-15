-- Create messaging tables for staff conversations
CREATE TABLE IF NOT EXISTS message_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_message_threads_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message_participant (thread_id, participant_id),
    INDEX idx_message_participants_thread (thread_id),
    INDEX idx_message_participants_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_messages_thread (thread_id),
    INDEX idx_message_messages_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_reads (
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
    INDEX idx_message_reads_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_message_threads_created := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_threads' AND constraint_name = 'fk_message_threads_created_by'
);
SET @has_message_threads_created_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_threads'
      AND c.column_name = 'created_by'
);
SET @fk_message_threads_created_sql := IF(@has_fk_message_threads_created = 0 AND @has_message_threads_created_match = 1,
    'ALTER TABLE message_threads ADD CONSTRAINT fk_message_threads_created_by FOREIGN KEY (created_by) REFERENCES users (id)',
    'SELECT 1');
PREPARE fk_message_threads_created_stmt FROM @fk_message_threads_created_sql;
EXECUTE fk_message_threads_created_stmt;
DEALLOCATE PREPARE fk_message_threads_created_stmt;

SET @has_fk_message_participants_thread := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_participants' AND constraint_name = 'fk_message_participants_thread'
);
SET @has_message_participants_thread_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'message_threads'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_participants'
      AND c.column_name = 'thread_id'
);
SET @fk_message_participants_thread_sql := IF(@has_fk_message_participants_thread = 0 AND @has_message_participants_thread_match = 1,
    'ALTER TABLE message_participants ADD CONSTRAINT fk_message_participants_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_message_participants_thread_stmt FROM @fk_message_participants_thread_sql;
EXECUTE fk_message_participants_thread_stmt;
DEALLOCATE PREPARE fk_message_participants_thread_stmt;

SET @has_fk_message_participants_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_participants' AND constraint_name = 'fk_message_participants_user'
);
SET @has_message_participants_user_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_participants'
      AND c.column_name = 'participant_id'
);
SET @fk_message_participants_user_sql := IF(@has_fk_message_participants_user = 0 AND @has_message_participants_user_match = 1,
    'ALTER TABLE message_participants ADD CONSTRAINT fk_message_participants_user FOREIGN KEY (participant_id) REFERENCES users (id)',
    'SELECT 1');
PREPARE fk_message_participants_user_stmt FROM @fk_message_participants_user_sql;
EXECUTE fk_message_participants_user_stmt;
DEALLOCATE PREPARE fk_message_participants_user_stmt;

SET @has_fk_message_messages_thread := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_messages' AND constraint_name = 'fk_message_messages_thread'
);
SET @has_message_messages_thread_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'message_threads'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_messages'
      AND c.column_name = 'thread_id'
);
SET @fk_message_messages_thread_sql := IF(@has_fk_message_messages_thread = 0 AND @has_message_messages_thread_match = 1,
    'ALTER TABLE message_messages ADD CONSTRAINT fk_message_messages_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_message_messages_thread_stmt FROM @fk_message_messages_thread_sql;
EXECUTE fk_message_messages_thread_stmt;
DEALLOCATE PREPARE fk_message_messages_thread_stmt;

SET @has_fk_message_messages_sender := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_messages' AND constraint_name = 'fk_message_messages_sender'
);
SET @has_message_messages_sender_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_messages'
      AND c.column_name = 'sender_id'
);
SET @fk_message_messages_sender_sql := IF(@has_fk_message_messages_sender = 0 AND @has_message_messages_sender_match = 1,
    'ALTER TABLE message_messages ADD CONSTRAINT fk_message_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id)',
    'SELECT 1');
PREPARE fk_message_messages_sender_stmt FROM @fk_message_messages_sender_sql;
EXECUTE fk_message_messages_sender_stmt;
DEALLOCATE PREPARE fk_message_messages_sender_stmt;

SET @has_fk_message_reads_thread := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_reads' AND constraint_name = 'fk_message_reads_thread'
);
SET @has_message_reads_thread_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'message_threads'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_reads'
      AND c.column_name = 'thread_id'
);
SET @fk_message_reads_thread_sql := IF(@has_fk_message_reads_thread = 0 AND @has_message_reads_thread_match = 1,
    'ALTER TABLE message_reads ADD CONSTRAINT fk_message_reads_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_message_reads_thread_stmt FROM @fk_message_reads_thread_sql;
EXECUTE fk_message_reads_thread_stmt;
DEALLOCATE PREPARE fk_message_reads_thread_stmt;

SET @has_fk_message_reads_participant := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_reads' AND constraint_name = 'fk_message_reads_participant'
);
SET @has_message_reads_participant_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_reads'
      AND c.column_name = 'participant_id'
);
SET @fk_message_reads_participant_sql := IF(@has_fk_message_reads_participant = 0 AND @has_message_reads_participant_match = 1,
    'ALTER TABLE message_reads ADD CONSTRAINT fk_message_reads_participant FOREIGN KEY (participant_id) REFERENCES users (id)',
    'SELECT 1');
PREPARE fk_message_reads_participant_stmt FROM @fk_message_reads_participant_sql;
EXECUTE fk_message_reads_participant_stmt;
DEALLOCATE PREPARE fk_message_reads_participant_stmt;

SET @has_fk_message_reads_message := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'message_reads' AND constraint_name = 'fk_message_reads_message'
);
SET @has_message_reads_message_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'message_messages'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'message_reads'
      AND c.column_name = 'last_read_message_id'
);
SET @fk_message_reads_message_sql := IF(@has_fk_message_reads_message = 0 AND @has_message_reads_message_match = 1,
    'ALTER TABLE message_reads ADD CONSTRAINT fk_message_reads_message FOREIGN KEY (last_read_message_id) REFERENCES message_messages (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_message_reads_message_stmt FROM @fk_message_reads_message_sql;
EXECUTE fk_message_reads_message_stmt;
DEALLOCATE PREPARE fk_message_reads_message_stmt;
