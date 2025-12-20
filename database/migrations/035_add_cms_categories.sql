-- Add CMS categories for hierarchical page organization
-- Categories allow pages to be grouped and accessed via nested URIs

CREATE TABLE IF NOT EXISTS cms_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Display name of the category',
    slug VARCHAR(255) NOT NULL UNIQUE COMMENT 'URL-friendly identifier',
    description TEXT NULL COMMENT 'Category description',
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Display order (lower numbers first)',
    meta_title VARCHAR(255) NULL COMMENT 'SEO meta title',
    meta_description TEXT NULL COMMENT 'SEO meta description',
    meta_keywords VARCHAR(255) NULL COMMENT 'SEO meta keywords',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_categories_status (status),
    INDEX idx_cms_categories_sort_order (sort_order),
    INDEX idx_cms_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add category relationship to pages
ALTER TABLE cms_pages
    ADD COLUMN category_id INT UNSIGNED NULL AFTER slug,
    ADD INDEX idx_cms_pages_category_id (category_id),
    ADD CONSTRAINT fk_cms_pages_category
        FOREIGN KEY (category_id) REFERENCES cms_categories(id)
        ON DELETE SET NULL;

-- Note: When a category is deleted, pages are set to NULL (no category)
-- This prevents data loss and allows pages to continue functioning at base URLs
