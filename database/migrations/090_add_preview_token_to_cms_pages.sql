-- Add preview token support for CMS pages

ALTER TABLE cms_pages
    ADD COLUMN preview_token VARCHAR(64) NULL AFTER slug,
    ADD INDEX idx_cms_pages_preview_token (preview_token);
