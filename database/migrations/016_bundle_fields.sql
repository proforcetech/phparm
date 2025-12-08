-- Add active flag and sort order to bundles
ALTER TABLE bundles
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER default_job_title,
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active;

-- Add sort order to bundle items
ALTER TABLE bundle_items
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER taxable;

-- Backfill existing records with sensible ordering
UPDATE bundles SET is_active = 1 WHERE is_active IS NULL;
UPDATE bundles SET sort_order = id WHERE sort_order = 0;
UPDATE bundle_items SET sort_order = id WHERE sort_order = 0;
