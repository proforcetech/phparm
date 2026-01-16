ALTER TABLE time_entries
    ADD COLUMN payroll_included TINYINT(1) NOT NULL DEFAULT 0 AFTER review_notes,
    ADD COLUMN payroll_included_at DATETIME NULL AFTER payroll_included;
