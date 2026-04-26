<?php

namespace App\Services\Pm;

use App\Models\User;
use App\Services\Contracts\ContractBillingService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 5.5 of docs/expansion-plan.md — contract entitlement linkage.
 *
 * When a PM ticket completes (closed/resolved) and its schedule was created
 * with a contract_entitlement_id, this service calls the Phase 4.4
 * ContractBillingService.applyConsumption so the entitlement bucket
 * (hours/visits/coverage) decrements and appears in the consumption ledger.
 *
 * Idempotency:
 *   pm_generations.consumption_applied_at is the guard. Once set, a second
 *   call throws — operators have to use the manual-adjustment path in 4.4 to
 *   correct a misreported value. The repository UPDATE only writes when the
 *   column is NULL, so a race between two completion calls only succeeds once.
 */
class PmLinkageService
{
    public function __construct(
        private readonly PmGenerationRepository $generations,
        private readonly PmScheduleRepository $schedules,
        private readonly ContractBillingService $billing,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Apply consumption for a completed PM generation.
     *
     * @return array{
     *   status: 'applied'|'skipped',
     *   reason?: string,
     *   generation_id: int,
     *   entitlement_id?: ?int,
     *   amount?: float,
     *   ledger_id?: ?int
     * }
     */
    public function applyCompletion(
        User $actor,
        int $generationId,
        string $entitlementKind,
        float $amount,
        ?string $notes = null,
    ): array {
        $this->gate->assert($actor, 'pm.manage');
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount must be positive');
        }

        $generation = $this->generations->findById($generationId);
        if ($generation === null) {
            throw new InvalidArgumentException("pm_generation {$generationId} not found");
        }
        if ($generation->consumption_applied_at !== null) {
            return [
                'status' => 'skipped',
                'reason' => 'already_applied',
                'generation_id' => $generationId,
                'entitlement_id' => $generation->consumption_entitlement_id,
                'amount' => $generation->consumption_amount,
                'ledger_id' => $generation->consumption_ledger_id,
            ];
        }
        if ($generation->status !== 'generated' || $generation->ticket_id === null) {
            throw new InvalidArgumentException(
                "pm_generation {$generationId} has no ticket to bill against (status={$generation->status})"
            );
        }

        $schedule = $this->schedules->findById($generation->schedule_id);
        if ($schedule === null) {
            throw new InvalidArgumentException(
                "pm_schedule {$generation->schedule_id} not found"
            );
        }

        // If the schedule wasn't bound to a contract, there's nothing to
        // decrement — but we still stamp consumption_applied_at so the
        // "pending completion" list in 5.4 can move on.
        if ($schedule->contract_id === null) {
            $this->generations->markConsumptionApplied(
                $generation->id, null, $amount, null
            );
            $this->audit->log(new AuditEntry(
                'pm.completion_recorded',
                'pm_generation',
                $generation->id,
                $actor->id ?? null,
                [
                    'schedule_id' => $schedule->id,
                    'ticket_id' => $generation->ticket_id,
                    'amount' => $amount,
                    'contract_bound' => false,
                ]
            ));
            return [
                'status' => 'applied',
                'reason' => 'no_contract_binding',
                'generation_id' => $generation->id,
                'entitlement_id' => null,
                'amount' => $amount,
                'ledger_id' => null,
            ];
        }

        // Contract-bound: run through the billing service so we get the
        // smallest-remaining-first bucket-drain plus ledger row + audit.
        // Note we pass sourceType='ticket' + sourceId=the generated ticket
        // so the consumption ledger threads back to the real ticket, not
        // the intermediate generation row.
        $ledger = $this->billing->applyConsumption(
            $schedule->company_id,
            $schedule->site_id,
            $entitlementKind,
            $amount,
            'ticket',
            $generation->ticket_id,
            $actor->id ?? null,
            null,
            $notes ?? ("PM completion: pm_schedule={$schedule->id} pm_generation={$generation->id}")
        );

        $this->generations->markConsumptionApplied(
            $generation->id,
            $ledger->entitlement_id,
            $amount,
            $ledger->id,
        );

        $this->audit->log(new AuditEntry(
            'pm.completion_recorded',
            'pm_generation',
            $generation->id,
            $actor->id ?? null,
            [
                'schedule_id' => $schedule->id,
                'ticket_id' => $generation->ticket_id,
                'contract_id' => $schedule->contract_id,
                'entitlement_id' => $ledger->entitlement_id,
                'entitlement_kind' => $entitlementKind,
                'amount' => $amount,
                'amount_covered' => (float) $ledger->amount_covered,
                'amount_overage' => (float) $ledger->amount_overage,
                'ledger_id' => $ledger->id,
            ]
        ));

        return [
            'status' => 'applied',
            'generation_id' => $generation->id,
            'entitlement_id' => $ledger->entitlement_id,
            'amount' => $amount,
            'amount_covered' => (float) $ledger->amount_covered,
            'amount_overage' => (float) $ledger->amount_overage,
            'ledger_id' => $ledger->id,
        ];
    }
}
