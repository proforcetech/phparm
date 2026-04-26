-- Phase 4.1 of docs/expansion-plan.md: Contracts & Service Agreements.
--
-- A contract is an agreement with a customer (company) to provide service
-- over a defined term. Scope:
--   * contracts           — master record with term, billing cadence, status
--   * contract_sites      — optional scoping of a contract to specific sites
--                           (empty = applies to ALL sites under the company)
--   * contract_entitlements — what the contract includes (hours, visits,
--                           tickets, or coverage of a specific SLA tier)
--   * contract_amendments — immutable change log for post-signing changes
--
-- Status machine:
--   draft → pending_signature → active → (expired | cancelled | renewed)
-- renewed is terminal for the old record — a new contract row is created
-- linked via renewed_from_contract_id.

CREATE TABLE IF NOT EXISTS contracts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_number VARCHAR(40) NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    division_id INT UNSIGNED NULL,
    renewed_from_contract_id INT UNSIGNED NULL,
    title VARCHAR(191) NOT NULL,
    description TEXT NULL,
    contract_type VARCHAR(40) NOT NULL DEFAULT 'service_agreement',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    billing_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
    billing_amount_cents BIGINT NOT NULL DEFAULT 0,
    auto_renew TINYINT(1) NOT NULL DEFAULT 0,
    renewal_term_months INT UNSIGNED NULL,
    renewal_notice_days INT UNSIGNED NOT NULL DEFAULT 30,
    terms_markdown MEDIUMTEXT NULL,
    signed_at DATETIME NULL,
    signed_by_contact_id INT UNSIGNED NULL,
    signer_ip VARCHAR(45) NULL,
    signer_user_agent VARCHAR(255) NULL,
    signature_data MEDIUMTEXT NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contracts_number (contract_number),
    INDEX idx_contracts_company (company_id),
    INDEX idx_contracts_division (division_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_end_date (end_date),
    INDEX idx_contracts_renewed_from (renewed_from_contract_id),
    CONSTRAINT fk_contracts_company FOREIGN KEY (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    CONSTRAINT fk_contracts_renewed_from FOREIGN KEY (renewed_from_contract_id)
        REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contract_sites (contract_id, site_id),
    INDEX idx_contract_sites_site (site_id),
    CONSTRAINT fk_contract_sites_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contract_sites_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- entitlement_kind:
--   * 'hours'     — N hours of labor per period
--   * 'visits'    — N site visits per period (used by PM in Phase 5)
--   * 'tickets'   — N tickets per period (ignored for unlimited)
--   * 'coverage'  — no numeric quota; grants access to an SLA tier
-- period:
--   * 'term'      — counted across the whole contract term (no reset)
--   * 'annual' | 'quarterly' | 'monthly' — reset at each period boundary
CREATE TABLE IF NOT EXISTS contract_entitlements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    entitlement_kind VARCHAR(30) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity_allowed DECIMAL(12,2) NULL,
    quantity_used DECIMAL(12,2) NOT NULL DEFAULT 0,
    period VARCHAR(20) NOT NULL DEFAULT 'term',
    period_resets_at DATE NULL,
    reset_on_renewal TINYINT(1) NOT NULL DEFAULT 1,
    sla_policy_id INT UNSIGNED NULL,
    unit_rate_cents BIGINT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract_entitlements_contract (contract_id),
    INDEX idx_contract_entitlements_kind (entitlement_kind),
    INDEX idx_contract_entitlements_sla (sla_policy_id),
    CONSTRAINT fk_contract_entitlements_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contract_entitlements_sla FOREIGN KEY (sla_policy_id)
        REFERENCES ticket_sla_policies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable audit trail of every post-creation change to a contract.
-- amendment_kind:
--   * 'extend'         — bump end_date (delta_json.end_date)
--   * 'change_scope'   — sites/entitlements changed
--   * 'change_price'   — billing_amount_cents or billing_frequency changed
--   * 'pause'          — temporarily suspend (delta_json.paused_until)
--   * 'terminate'      — early termination (delta_json.reason)
--   * 'renew'          — link to new contract (delta_json.new_contract_id)
CREATE TABLE IF NOT EXISTS contract_amendments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    amendment_kind VARCHAR(40) NOT NULL,
    effective_date DATE NOT NULL,
    summary VARCHAR(255) NOT NULL,
    delta_json JSON NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract_amendments_contract (contract_id),
    INDEX idx_contract_amendments_kind (amendment_kind),
    INDEX idx_contract_amendments_effective (effective_date),
    CONSTRAINT fk_contract_amendments_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
