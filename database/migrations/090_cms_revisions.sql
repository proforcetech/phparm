-- Add revisions table for CMS content versioning
CREATE TABLE IF NOT EXISTS cms_revisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NULL,
    snapshot_data LONGTEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cms_revisions_entity (entity_type, entity_id),
    INDEX idx_cms_revisions_created_at (created_at),
    INDEX idx_cms_revisions_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
