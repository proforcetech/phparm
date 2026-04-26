-- Phase 5.5 of docs/expansion-plan.md — contract entitlement linkage.
--
-- When a PM ticket completes and its schedule is tied to a contract
-- entitlement (pm_schedules.contract_entitlement_id set during 5.1), we
-- decrement that entitlement's bucket via ContractBillingService.
-- The pm_generations row is the join point between a PM run and the
-- consumption ledger entry.
--
-- consumption_applied_at is the idempotency guard: once set, re-running
-- completion for the same generation is a no-op. consumption_entitlement_id
-- + consumption_amount snapshot what was actually applied so the ledger
-- entry isn't the only audit trail.

ALTER TABLE pm_generations
    ADD COLUMN consumption_applied_at DATETIME NULL AFTER failure_reason,
    ADD COLUMN consumption_entitlement_id BIGINT UNSIGNED NULL AFTER consumption_applied_at,
    ADD COLUMN consumption_amount DECIMAL(12, 2) NULL AFTER consumption_entitlement_id,
    ADD COLUMN consumption_ledger_id BIGINT UNSIGNED NULL AFTER consumption_amount,
    ADD INDEX idx_pm_generations_consumption_applied (consumption_applied_at);
