-- Add pending status to CMS pages

ALTER TABLE cms_pages
    MODIFY status ENUM('draft', 'pending', 'published', 'archived') NOT NULL DEFAULT 'draft';
