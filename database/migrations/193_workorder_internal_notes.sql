-- Append-only staff notes for workorders. This is separate from
-- workorders.internal_notes so teams can add timestamped process notes
-- throughout dispatch, repair, parts, QC, and closeout.

CREATE TABLE IF NOT EXISTS workorder_internal_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    author_user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    context VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_workorder_internal_notes_workorder_created (workorder_id, created_at),
    INDEX idx_workorder_internal_notes_author (author_user_id),
    CONSTRAINT fk_workorder_internal_notes_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE,
    CONSTRAINT fk_workorder_internal_notes_author
        FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
