-- Track component-to-page usage relationships for cache invalidation

CREATE TABLE IF NOT EXISTS cms_component_page_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component_id INT UNSIGNED NOT NULL,
    page_id INT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_component_page (component_id, page_id),
    INDEX idx_component_page_component (component_id),
    INDEX idx_component_page_page (page_id),
    CONSTRAINT fk_component_page_component
        FOREIGN KEY (component_id) REFERENCES cms_components(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_component_page_page
        FOREIGN KEY (page_id) REFERENCES cms_pages(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
