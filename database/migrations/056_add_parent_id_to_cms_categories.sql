-- Add optional parent category support for CMS categories

ALTER TABLE cms_categories
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER slug,
    ADD INDEX idx_cms_categories_parent_id (parent_id),
    ADD CONSTRAINT fk_cms_categories_parent
        FOREIGN KEY (parent_id) REFERENCES cms_categories(id)
        ON DELETE SET NULL;
