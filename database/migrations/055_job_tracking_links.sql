-- Create tracking links for workorder jobs

CREATE TABLE IF NOT EXISTS job_tracking_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    job_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NULL,
    last_position JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_job_tracking_links_token (token),
    INDEX idx_job_tracking_links_job (job_id),
    INDEX idx_job_tracking_links_expires (expires_at),
    CONSTRAINT fk_job_tracking_links_job FOREIGN KEY (job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
