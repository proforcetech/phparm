-- Migration: 089_core_return_followup_fields.sql
-- Description: Add sellable and warranty follow-up fields to core returns

ALTER TABLE core_returns
    ADD COLUMN IF NOT EXISTS return_sellable TINYINT(1) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS warranty_follow_up_status VARCHAR(40) NULL AFTER return_sellable,
    ADD COLUMN IF NOT EXISTS warranty_follow_up_reason TEXT NULL AFTER warranty_follow_up_status;
