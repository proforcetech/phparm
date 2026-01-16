CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    type VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reason TEXT NULL,
    reviewer_id INT UNSIGNED NULL,
    reviewer_notes TEXT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leave_requests_user (user_id),
    INDEX idx_leave_requests_status (status),
    INDEX idx_leave_requests_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_leave_requests_user := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_user'
);
SET @has_leave_requests_user_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'leave_requests'
      AND c.column_name = 'user_id'
);
SET @fk_leave_requests_user_sql := IF(@has_fk_leave_requests_user = 0 AND @has_leave_requests_user_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_leave_requests_user_stmt FROM @fk_leave_requests_user_sql;
EXECUTE fk_leave_requests_user_stmt;
DEALLOCATE PREPARE fk_leave_requests_user_stmt;

SET @has_fk_leave_requests_reviewer := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND constraint_name = 'fk_leave_requests_reviewer'
);
SET @has_leave_requests_reviewer_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'leave_requests'
      AND c.column_name = 'reviewer_id'
);
SET @fk_leave_requests_reviewer_sql := IF(@has_fk_leave_requests_reviewer = 0 AND @has_leave_requests_reviewer_match = 1,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_leave_requests_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_leave_requests_reviewer_stmt FROM @fk_leave_requests_reviewer_sql;
EXECUTE fk_leave_requests_reviewer_stmt;
DEALLOCATE PREPARE fk_leave_requests_reviewer_stmt;
