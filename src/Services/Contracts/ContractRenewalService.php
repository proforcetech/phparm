<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractEntitlement;
use App\Models\ContractSite;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;

/**
 * Phase 4.3 of docs/expansion-plan.md: auto-renewal + expiry.
 *
 * Two daily actions:
 *   * autoRenewDue()  — create successor contract + link via
 *                       renewed_from_contract_id, transition old to 'renewed',
 *                       copy sites + entitlements (resetting usage where
 *                       reset_on_renewal=1), log a 'renew' amendment.
 *   * expireDue()     — transition contracts past end_date without auto_renew
 *                       to 'expired'.
 *
 * Contracts are eligible for auto-renewal when:
 *   status = active AND auto_renew = 1 AND renewal_term_months IS NOT NULL
 *   AND end_date <= today + renewal_notice_days
 */
class ContractRenewalService
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractSiteRepository $sites,
        private readonly ContractEntitlementRepository $entitlements,
        private readonly ContractAmendmentRepository $amendments,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Run auto-renew for every eligible contract. Returns summary per contract.
     *
     * @return array<int, array{old_id:int, new_id:int, new_contract_number:string}>
     */
    public function autoRenewDue(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $results = [];
        foreach ($this->contracts->listExpiringThrough($this->horizonCutoff($today)) as $contract) {
            if (!$this->isAutoRenewEligible($contract, $today)) {
                continue;
            }
            $new = $this->renewOne($contract);
            $results[] = [
                'old_id' => $contract->id,
                'new_id' => $new->id,
                'new_contract_number' => $new->contract_number,
            ];
        }
        return $results;
    }

    /**
     * Mark non-renewing contracts whose end_date is before today as 'expired'.
     *
     * @return array<int, int> IDs of contracts transitioned to expired.
     */
    public function expireDue(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $expired = [];
        foreach ($this->contracts->listExpiringThrough($today) as $contract) {
            if ($contract->end_date >= $today) {
                continue;
            }
            if ($this->isAutoRenewEligible($contract, $today)) {
                continue;
            }
            $this->contracts->update($contract->id, ['status' => 'expired']);
            $this->audit->log(new AuditEntry(
                'contract.expired',
                'contract',
                $contract->id,
                null,
                ['end_date' => $contract->end_date]
            ));
            $expired[] = $contract->id;
        }
        return $expired;
    }

    /**
     * Manual renewal (admin-triggered — e.g. clicking "Renew now" in the UI).
     * Does not require auto_renew=1.
     */
    public function renewManually(int $contractId, ?int $termMonths = null): Contract
    {
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new \InvalidArgumentException("contract {$contractId} not found");
        }
        if (in_array($contract->status, ['cancelled', 'renewed'], true)) {
            throw new \InvalidArgumentException(
                "cannot renew contract in '{$contract->status}' status"
            );
        }
        $termMonths = $termMonths ?? (int) ($contract->renewal_term_months ?? 12);
        if ($termMonths <= 0) {
            throw new \InvalidArgumentException('renewal term must be positive');
        }
        return $this->renewOne($contract, $termMonths);
    }

    /**
     * Contracts matching expiring/auto-renew criteria (for dashboards).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRenewalsDue(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $rows = [];
        foreach ($this->contracts->listExpiringThrough($this->horizonCutoff($today)) as $contract) {
            $rows[] = [
                'contract' => $contract->toArray(),
                'days_to_expiry' => (int) round(
                    (strtotime($contract->end_date) - strtotime($today)) / 86400
                ),
                'auto_renew_eligible' => $this->isAutoRenewEligible($contract, $today),
            ];
        }
        return $rows;
    }

    private function isAutoRenewEligible(Contract $contract, string $today): bool
    {
        if ($contract->status !== 'active') {
            return false;
        }
        if (!$contract->auto_renew) {
            return false;
        }
        if (empty($contract->renewal_term_months) || (int) $contract->renewal_term_months <= 0) {
            return false;
        }
        $noticeDays = max(0, (int) ($contract->renewal_notice_days ?? 30));
        $noticeCutoff = date('Y-m-d', strtotime("{$today} +{$noticeDays} days"));
        return $contract->end_date <= $noticeCutoff;
    }

    private function horizonCutoff(string $today): string
    {
        // Look 90 days ahead by default — covers longest plausible notice window.
        // Filtering happens in isAutoRenewEligible() using per-contract notice.
        return date('Y-m-d', strtotime("{$today} +90 days"));
    }

    private function renewOne(Contract $old, ?int $overrideTermMonths = null): Contract
    {
        $term = $overrideTermMonths ?? (int) $old->renewal_term_months;
        $newStart = date('Y-m-d', strtotime($old->end_date . ' +1 day'));
        $newEnd = date('Y-m-d', strtotime($newStart . " +{$term} months -1 day"));

        $new = $this->contracts->create([
            'company_id' => $old->company_id,
            'division_id' => $old->division_id,
            'renewed_from_contract_id' => $old->id,
            'title' => $old->title,
            'description' => $old->description,
            'contract_type' => $old->contract_type,
            'status' => 'active',
            'start_date' => $newStart,
            'end_date' => $newEnd,
            'billing_frequency' => $old->billing_frequency,
            'billing_amount_cents' => $old->billing_amount_cents,
            'auto_renew' => $old->auto_renew,
            'renewal_term_months' => $old->renewal_term_months,
            'renewal_notice_days' => $old->renewal_notice_days,
            'terms_markdown' => $old->terms_markdown,
            'created_by_user_id' => null,
        ]);

        // Mirror sites + entitlements onto the new contract.
        foreach ($this->sites->listForContract($old->id) as $siteLink) {
            $this->sites->attach($new->id, $siteLink->site_id);
        }
        foreach ($this->entitlements->listForContract($old->id, false) as $ent) {
            $this->copyEntitlement($new->id, $ent);
        }

        // Transition old contract and log the amendment on BOTH for traceability.
        $this->contracts->update($old->id, ['status' => 'renewed']);
        $this->amendments->create([
            'contract_id' => $old->id,
            'amendment_kind' => 'renew',
            'effective_date' => $newStart,
            'summary' => "Renewed as contract {$new->contract_number}",
            'delta_json' => [
                'new_contract_id' => $new->id,
                'new_contract_number' => $new->contract_number,
                'new_start_date' => $newStart,
                'new_end_date' => $newEnd,
            ],
        ]);

        $this->audit->log(new AuditEntry(
            'contract.renewed',
            'contract',
            $old->id,
            null,
            [
                'new_contract_id' => $new->id,
                'new_contract_number' => $new->contract_number,
                'new_end_date' => $newEnd,
            ]
        ));

        return $new;
    }

    private function copyEntitlement(int $newContractId, ContractEntitlement $ent): void
    {
        $copy = $this->entitlements->create([
            'contract_id' => $newContractId,
            'entitlement_kind' => $ent->entitlement_kind,
            'description' => $ent->description,
            'quantity_allowed' => $ent->quantity_allowed,
            'period' => $ent->period,
            'reset_on_renewal' => $ent->reset_on_renewal,
            'sla_policy_id' => $ent->sla_policy_id,
            'unit_rate_cents' => $ent->unit_rate_cents,
            'notes' => $ent->notes,
            'is_active' => $ent->is_active,
        ]);
        // Carry over unused quota for entitlements flagged "do not reset".
        if (!$ent->reset_on_renewal && (float) $ent->quantity_used > 0) {
            $this->entitlements->consume($copy->id, (float) $ent->quantity_used);
        }
    }
}
