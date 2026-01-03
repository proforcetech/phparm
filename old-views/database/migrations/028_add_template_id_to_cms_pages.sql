-- Add template_id to cms_pages to allow template switching
-- This allows pages to change their template after creation

ALTER TABLE cms_pages
    ADD COLUMN template_id INT UNSIGNED NULL AFTER slug,
    ADD INDEX idx_cms_pages_template_id (template_id),
    ADD CONSTRAINT fk_cms_pages_template
        FOREIGN KEY (template_id) REFERENCES cms_templates(id)
        ON DELETE SET NULL;
