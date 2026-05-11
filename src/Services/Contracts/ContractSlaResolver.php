<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractEntitlement;
use App\Models\Ticket;
use App\Models\TicketSlaPolicy;
use App\Services\Tickets\TicketSlaPolicyRepository;

/**
 * Phase 4.5 of docs/expansion-plan.md: contract-driven SLA tier at ticket
 * create.
 *
 * When a ticket is reported for a site covered by an active contract whose
 * entitlement carries an `sla_policy_id`, that policy overrides the default
 * (division, priority) lookup. The resolver ranks competing contracts by the
 * tightest response_minutes — customers always get the best tier they're
 * entitled to across their active contracts.
 */
class ContractSlaResolver
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractEntitlementRepository $entitlements,
        private readonly TicketSlaPolicyRepository $slaPolicies,
    ) {
    }

    /**
     * Return the best contract-sourced policy for this ticket, or null if no
     * covering contract links an SLA policy.
     *
     * @return array{
     *   policy: TicketSlaPolicy,
     *   contract: Contract,
     *   entitlement: ContractEntitlement
     * }|null
     */
    public function resolveForTicket(Ticket $ticket, ?string $onDate = null): ?array
    {
        if ($ticket->company_id === null) {
            return null;
        }
        return $this->resolveFor(
            (int) $ticket->company_id,
            $ticket->site_id !== null ? (int) $ticket->site_id : null,
            $onDate
        );
    }

    /**
     * Lower-level resolver used by callers that don't yet have a Ticket
     * (e.g., preview endpoints or tests).
     *
     * @return array{
     *   policy: TicketSlaPolicy,
     *   contract: Contract,
     *   entitlement: ContractEntitlement
     * }|null
     */
    public function resolveFor(int $companyId, ?int $siteId, ?string $onDate = null): ?array
    {
        $onDate = $onDate ?? date('Y-m-d');

        // Two-pass: first collect every covering (contract, entitlement) pair
        // that names an sla_policy_id, then bulk-load the referenced policies
        // in a single query. Avoids the per-entitlement findById N+1 that
        // ran on every ticket create (AUD-073).
        $pairs = [];
        $policyIds = [];
        foreach ($this->contracts->listActiveForSite($companyId, $siteId, $onDate) as $contract) {
            foreach ($this->entitlements->listForContract($contract->id, true) as $ent) {
                if ($ent->sla_policy_id === null) {
                    continue;
                }
                $pairs[] = ['contract' => $contract, 'entitlement' => $ent];
                $policyIds[] = (int) $ent->sla_policy_id;
            }
        }
        if ($pairs === []) {
            return null;
        }

        $policiesById = $this->slaPolicies->findByIds($policyIds);

        $best = null;
        foreach ($pairs as $pair) {
            $policy = $policiesById[(int) $pair['entitlement']->sla_policy_id] ?? null;
            if ($policy === null || !$policy->is_active) {
                continue;
            }
            $responseMinutes = $policy->response_minutes ?? PHP_INT_MAX;
            if ($best === null || $responseMinutes < $best['response']) {
                $best = [
                    'policy' => $policy,
                    'contract' => $pair['contract'],
                    'entitlement' => $pair['entitlement'],
                    'response' => $responseMinutes,
                ];
            }
        }
        if ($best === null) {
            return null;
        }
        unset($best['response']);
        return $best;
    }
}
