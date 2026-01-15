-- Migration: Job damage photo evidence

CREATE TABLE IF NOT EXISTS job_damage_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_damage_media_job (workorder_job_id),
    CONSTRAINT fk_job_damage_media_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_damage_media_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
