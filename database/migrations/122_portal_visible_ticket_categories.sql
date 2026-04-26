-- Phase 6.2 of docs/expansion-plan.md: Portal Request Wizard.
--
-- Marks which ticket_categories the customer-portal request wizard exposes
-- to portal users. Kept as a simple boolean instead of a separate
-- portal_request_types table because:
--   * the existing two-level ticket_categories tree IS already a
--     type-selector → category-routing tree; adding a parallel catalog
--     would force sync drift between staff-facing and portal-facing labels;
--   * admins can curate visibility per row (top-level and per subcategory)
--     so a staff-only triage bucket can exist alongside a portal-visible
--     "Service Request" root without schema fork;
--   * routing rules in ticket_routing_rules already match on category_id /
--     subcategory_id, so a portal submission automatically benefits from
--     the 3.3 routing engine once the category is set.
--
-- Default 0 so every existing row is invisible to the portal — admin must
-- opt-in each row explicitly. Indexed because the hot wizard read
-- ("show me portal-visible top-level categories") must stay cheap even
-- when a division has hundreds of internal categories.

ALTER TABLE ticket_categories
    ADD COLUMN IF NOT EXISTS portal_visible TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD INDEX IF NOT EXISTS idx_ticket_categories_portal_visible (portal_visible, is_active);
