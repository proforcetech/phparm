-- Add component_order to cms_pages to store visual component ordering

ALTER TABLE cms_pages
    ADD COLUMN component_order TEXT NULL AFTER content;
