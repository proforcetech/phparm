-- Create messaging tables for staff conversations
CREATE TABLE IF NOT EXISTS message_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_message_threads_created_at (created_at),
    CONSTRAINT fk_message_threads_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_participants (
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

CREATE TABLE IF NOT EXISTS message_messages (
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
    INDEX idx_message_reads_created_at (created_at),
    CONSTRAINT fk_message_reads_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE,
    CONSTRAINT fk_message_reads_participant FOREIGN KEY (participant_id) REFERENCES users (id),
    CONSTRAINT fk_message_reads_message FOREIGN KEY (last_read_message_id) REFERENCES message_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
