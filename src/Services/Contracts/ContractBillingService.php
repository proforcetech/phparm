<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractConsumptionLedger;
use App\Models\ContractEntitlement;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 4.4 of docs/expansion-plan.md: contract-driven billing lines.
 *
 * Two use cases:
 *   1. At invoice generation time, given (company, site, date, kind, amount)
 *      compute how much of the requested quantity is covered by remaining
 *      contract entitlement vs overage. The invoice generator uses this to
 *      split a single labor/visit line into a covered (zero-cost or
 *      discounted) line + an overage line at standard rate.
 *
 *   2. Record the consumption atomically: increment quantity_used AND write
 *      a ledger row. The ledger is the append-only source of truth used by
 *      the utilization service and billing reconciliation.
 *
 * Coverage resolution rules:
 *   * Only `active` contracts covering the site on the requested date.
 *   * Only entitlements with is_active=1 AND matching kind.
 *   * Among multiple candidates, prefer entitlements with smallest remaining
 *     quota (consume the soonest-to-exhaust bucket first — this keeps larger
 *     buckets available for overflow).
 *   * If `quantity_allowed` is NULL on an entitlement it counts as unlimited
 *     coverage (used for 'coverage' kind which grants SLA access rather than
 *     a numeric quota).
 */
class ContractBillingService
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractEntitlementRepository $entitlements,
        private readonly ContractConsumptionRepository $ledger,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Compute coverage breakdown without persisting. Pure function over
     * repository state. The caller uses this to preview invoice lines before
     * committing (e.g. showing the shop owner "of 6.0 hrs requested, 4.5
     * covered, 1.5 overage at $95/hr" in an estimate confirmation).
     *
     * @return array{
     *   contract: ?array<string,mixed>,
     *   entitlement: ?array<string,mixed>,
     *   amount_requested: float,
     *   amount_covered: float,
     *   amount_overage: float,
     *   unit_rate_cents: ?int,
     * }
     */
    public function resolveCoverage(
        int $companyId,
        ?int $siteId,
        string $entitlementKind,
        float $amount,
        ?string $onDate = null
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount must be positive');
        }
        if (!in_array($entitlementKind, ContractEntitlementRepository::KINDS, true)) {
            throw new InvalidArgumentException("unknown entitlement_kind '{$entitlementKind}'");
        }
        $onDate = $onDate ?? date('Y-m-d');

        $candidates = $this->rankedCandidates($companyId, $siteId, $entitlementKind, $onDate);
        if ($candidates === []) {
            return [
                'contract' => null,
                'entitlement' => null,
                'amount_requested' => $amount,
                'amount_covered' => 0.0,
                'amount_overage' => $amount,
                'unit_rate_cents' => null,
            ];
        }

        // Draw from the first (smallest remaining) bucket only — multi-bucket
        // allocation is a later optimization. For 'coverage' kind with NULL
        // quantity_allowed we treat it as unlimited.
        ['contract' => $contract, 'entitlement' => $ent, 'remaining' => $remaining] = $candidates[0];
        $covered = $remaining === null ? $amount : min($amount, $remaining);
        $overage = max(0.0, $amount - $covered);

        return [
            'contract' => $contract->toArray(),
            'entitlement' => $ent->toArray(),
            'amount_requested' => $amount,
            'amount_covered' => $covered,
            'amount_overage' => $overage,
            'unit_rate_cents' => $ent->unit_rate_cents === null ? null : (int) $ent->unit_rate_cents,
        ];
    }

    /**
     * Apply a consumption: increment quantity_used on the entitlement AND
     * append a ledger row. If no coverage is found the ledger still records
     * the event (with entitlement_id=null) for full billing traceability.
     */
    public function applyConsumption(
        int $companyId,
        ?int $siteId,
        string $entitlementKind,
        float $amount,
        string $sourceType,
        ?int $sourceId,
        ?int $actorUserId = null,
        ?string $onDate = null,
        ?string $notes = null
    ): ContractConsumptionLedger {
        if (!in_array($sourceType, ContractConsumptionRepository::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException("unknown source_type '{$sourceType}'");
        }
        $coverage = $this->resolveCoverage($companyId, $siteId, $entitlementKind, $amount, $onDate);

        $entitlementId = $coverage['entitlement']['id'] ?? null;
        $contractId = $coverage['contract']['id'] ?? null;

        if ($entitlementId !== null && $coverage['amount_covered'] > 0) {
            $this->entitlements->consume($entitlementId, $coverage['amount_covered']);
        }

        $row = $this->ledger->record([
            'contract_id' => $contractId ?? 0,
            'entitlement_id' => $entitlementId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entitlement_kind' => $entitlementKind,
            'amount_requested' => $amount,
            'amount_covered' => $coverage['amount_covered'],
            'amount_overage' => $coverage['amount_overage'],
            'unit_rate_cents' => $coverage['unit_rate_cents'],
            'notes' => $notes,
            'actor_user_id' => $actorUserId,
            'occurred_at' => $onDate ? $onDate . ' ' . date('H:i:s') : date('Y-m-d H:i:s'),
        ]);

        if ($contractId !== null) {
            $this->audit->log(new AuditEntry(
                'contract.consumption_recorded',
                'contract',
                $contractId,
                $actorUserId,
                [
                    'ledger_id' => $row->id,
                    'entitlement_id' => $entitlementId,
                    'kind' => $entitlementKind,
                    'covered' => $coverage['amount_covered'],
                    'overage' => $coverage['amount_overage'],
                    'source' => $sourceType . ':' . ($sourceId ?? 'null'),
                ]
            ));
        }

        return $row;
    }

    /**
     * Translate a coverage result into suggested invoice-line rows. This is
     * the shape the invoice generator consumes when adding contract-aware
     * lines to a new invoice — a covered line and an overage line (either
     * may be omitted if zero).
     *
     * @return array<int, array{
     *   type: string, description: string, quantity: float,
     *   unit_rate_cents: int, note: ?string
     * }>
     */
    public function buildInvoiceLineSuggestions(
        array $coverage,
        int $standardRateCents,
        string $lineType = 'labor'
    ): array {
        $lines = [];
        $entitlement = $coverage['entitlement'] ?? null;

        if (($coverage['amount_covered'] ?? 0) > 0 && $entitlement !== null) {
            $entRate = isset($entitlement['unit_rate_cents'])
                ? (int) $entitlement['unit_rate_cents']
                : 0;
            $lines[] = [
                'type' => $lineType,
                'description' => "Contract coverage: {$entitlement['description']}",
                'quantity' => (float) $coverage['amount_covered'],
                'unit_rate_cents' => $entRate,
                'note' => 'Contract ' . ($coverage['contract']['contract_number'] ?? ''),
            ];
        }
        if (($coverage['amount_overage'] ?? 0) > 0) {
            $lines[] = [
                'type' => $lineType,
                'description' => 'Overage — beyond contract coverage',
                'quantity' => (float) $coverage['amount_overage'],
                'unit_rate_cents' => $standardRateCents,
                'note' => null,
            ];
        }
        return $lines;
    }

    /**
     * @return array<int, ContractConsumptionLedger>
     */
    public function listLedger(User $user, int $contractId, int $limit = 500): array
    {
        $this->gate->assert($user, 'contracts.view');
        return $this->ledger->listForContract($contractId, $limit);
    }

    /**
     * Manual ledger entry — admin adjustment, e.g. crediting back mistakenly
     * consumed hours.
     */
    public function recordManualAdjustment(
        User $user,
        int $contractId,
        string $entitlementKind,
        float $amount,
        string $notes,
        ?int $entitlementId = null
    ): ContractConsumptionLedger {
        $this->gate->assert($user, 'contracts.manage');
        if (!in_array($entitlementKind, ContractEntitlementRepository::KINDS, true)) {
            throw new InvalidArgumentException("unknown entitlement_kind '{$entitlementKind}'");
        }
        if (trim($notes) === '') {
            throw new InvalidArgumentException('notes required for manual adjustments');
        }
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new InvalidArgumentException("Contract {$contractId} not found");
        }
        if ($entitlementId !== null) {
            $ent = $this->entitlements->findById($entitlementId);
            if ($ent === null || $ent->contract_id !== $contractId) {
                throw new InvalidArgumentException(
                    "entitlement {$entitlementId} does not belong to contract {$contractId}"
                );
            }
            $this->entitlements->consume($entitlementId, $amount);
        }
        $row = $this->ledger->record([
            'contract_id' => $contractId,
            'entitlement_id' => $entitlementId,
            'source_type' => 'manual',
            'source_id' => null,
            'entitlement_kind' => $entitlementKind,
            'amount_requested' => $amount,
            'amount_covered' => $entitlementId !== null ? $amount : 0,
            'amount_overage' => $entitlementId !== null ? 0 : $amount,
            'notes' => $notes,
            'actor_user_id' => $user->id ?? null,
        ]);
        $this->audit->log(new AuditEntry(
            'contract.manual_adjustment',
            'contract',
            $contractId,
            $user->id ?? null,
            ['ledger_id' => $row->id, 'amount' => $amount, 'notes' => $notes]
        ));
        return $row;
    }

    /**
     * Rank usable entitlements: smallest remaining first so we drain the
     * buckets closest to depletion.
     *
     * @return array<int, array{contract: Contract, entitlement: ContractEntitlement, remaining: ?float}>
     */
    private function rankedCandidates(
        int $companyId,
        ?int $siteId,
        string $entitlementKind,
        string $onDate
    ): array {
        $candidates = [];
        foreach ($this->contracts->listActiveForSite($companyId, $siteId, $onDate) as $contract) {
            foreach ($this->entitlements->listForContract($contract->id, true) as $ent) {
                if ($ent->entitlement_kind !== $entitlementKind) {
                    continue;
                }
                $remaining = null;
                if ($ent->quantity_allowed !== null) {
                    $remaining = max(0.0, (float) $ent->quantity_allowed - (float) $ent->quantity_used);
                    if ($remaining <= 0) {
                        continue;
                    }
                }
                $candidates[] = [
                    'contract' => $contract,
                    'entitlement' => $ent,
                    'remaining' => $remaining,
                ];
            }
        }
        usort($candidates, static function ($a, $b) {
            // NULL (unlimited) sorts last — prefer finite buckets first.
            $ar = $a['remaining'];
            $br = $b['remaining'];
            if ($ar === null && $br === null) {
                return 0;
            }
            if ($ar === null) {
                return 1;
            }
            if ($br === null) {
                return -1;
            }
            return $ar <=> $br;
        });
        return $candidates;
    }
}
