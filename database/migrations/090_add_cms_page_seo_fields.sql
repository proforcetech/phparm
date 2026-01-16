-- Add canonical and Open Graph fields to cms_pages
ALTER TABLE cms_pages
    ADD COLUMN canonical_url VARCHAR(500) NULL AFTER meta_keywords,
    ADD COLUMN og_title VARCHAR(255) NULL AFTER canonical_url,
    ADD COLUMN og_description TEXT NULL AFTER og_title,
    ADD COLUMN og_image VARCHAR(500) NULL AFTER og_description,
    ADD COLUMN og_type VARCHAR(100) NULL AFTER og_image,
    ADD COLUMN og_url VARCHAR(500) NULL AFTER og_type;
