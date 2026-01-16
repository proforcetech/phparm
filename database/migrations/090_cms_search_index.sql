CREATE TABLE IF NOT EXISTS cms_search_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_type VARCHAR(32) NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    slug VARCHAR(255) NULL,
    summary TEXT NULL,
    content LONGTEXT NULL,
    status VARCHAR(50) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cms_search_source (source_type, source_id),
    INDEX idx_cms_search_status (status),
    FULLTEXT KEY ft_cms_search_text (title, summary, content)
);
