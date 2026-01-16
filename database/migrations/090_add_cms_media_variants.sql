-- Add variants JSON metadata for responsive images and WebP

ALTER TABLE cms_media
    ADD COLUMN variants JSON NULL AFTER published_at;
