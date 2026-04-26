-- Phase 4.4 of docs/expansion-plan.md: contract-driven billing.
--
-- Append-only ledger of every consumption event against a contract. Rows
-- are written when labor / visits / tickets are delivered under a covered
-- contract, alongside the atomic increment of
-- contract_entitlements.quantity_used.
--
-- Each row records:
--   * the entitlement being drawn down (NULL if overage with no coverage)
--   * source type + id (ticket | workorder | invoice | manual)
--   * amount requested, amount covered by entitlement, amount overage
--   * unit_rate_cents snapshot for billing reconstruction
--
-- The ledger is the source of truth for "how much of the contract has been
-- burned?"; the quantity_used denormalization is a performance shortcut.

CREATE TABLE IF NOT EXISTS contract_consumption_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    entitlement_id INT UNSIGNED NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id INT UNSIGNED NULL,
    entitlement_kind VARCHAR(30) NOT NULL,
    amount_requested DECIMAL(12,2) NOT NULL,
    amount_covered DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_overage DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_rate_cents BIGINT NULL,
    notes VARCHAR(255) NULL,
    actor_user_id INT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract_ledger_contract (contract_id),
    INDEX idx_contract_ledger_entitlement (entitlement_id),
    INDEX idx_contract_ledger_source (source_type, source_id),
    INDEX idx_contract_ledger_occurred (occurred_at),
    CONSTRAINT fk_contract_ledger_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contract_ledger_entitlement FOREIGN KEY (entitlement_id)
        REFERENCES contract_entitlements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
