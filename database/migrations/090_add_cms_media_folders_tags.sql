-- Add folder and tags metadata to CMS media library

ALTER TABLE cms_media
    ADD COLUMN folder VARCHAR(255) NULL AFTER alt_text,
    ADD COLUMN tags JSON NULL AFTER folder;
