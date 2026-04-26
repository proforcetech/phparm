-- Phase 3.3 of docs/expansion-plan.md: Auto-routing engine.
--
-- A catalog of ordered rules evaluated at ticket-create time. The first rule
-- whose match_* columns ALL satisfy the incoming ticket wins; the rule's
-- action_* columns dictate initial queue/assignee/priority.
--
-- `evaluation_order`: lower = earlier. Two rules with the same order fall
-- back to id ASC.
--
-- Match semantics: a NULL match column means "don't care". A non-null column
-- requires equality with the ticket's corresponding field (or, for
-- match_asset_type_id, with the asset's type).

CREATE TABLE IF NOT EXISTS ticket_routing_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    evaluation_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Matchers (all NULL-able; NULL = don't care)
    match_division_id INT UNSIGNED NULL,
    match_company_id INT UNSIGNED NULL,
    match_site_id INT UNSIGNED NULL,
    match_category_id INT UNSIGNED NULL,
    match_subcategory_id INT UNSIGNED NULL,
    match_priority VARCHAR(20) NULL,
    match_source VARCHAR(40) NULL,
    match_asset_type_id INT UNSIGNED NULL,
    -- Actions
    action_assign_queue_id INT UNSIGNED NULL,
    action_assign_user_id INT UNSIGNED NULL,
    action_set_priority VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_routing_rules_active_order (is_active, evaluation_order),
    INDEX idx_ticket_routing_rules_division (match_division_id),
    CONSTRAINT fk_ticket_routing_rules_division FOREIGN KEY (match_division_id)
        REFERENCES divisions(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_routing_rules_category FOREIGN KEY (match_category_id)
        REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_routing_rules_subcategory FOREIGN KEY (match_subcategory_id)
        REFERENCES ticket_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
