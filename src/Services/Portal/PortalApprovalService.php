<?php

namespace App\Services\Portal;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Estimate\EstimateRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 6.3 of docs/expansion-plan.md — customer-portal approval center.
 *
 * One service for everything a portal user has to act on:
 *   * estimates in status pending/sent whose customer is owned by the
 *     portal_account's company (approve → 'approved' via
 *     EstimateRepository::updateStatus; reject → 'rejected' via same);
 *   * contracts awaiting customer approval — either fresh draft/
 *     pending_signature contracts OR successors created by Phase 4.3's
 *     renewal cron (renewed_from_contract_id IS NOT NULL). Both live in
 *     the same workflow so "change orders" and "renewals" share code;
 *     approve transitions to 'active' and stamps signed_at + a
 *     ContractAmendment row, reject transitions to 'cancelled'.
 *
 * Scope invariants (all enforced server-side, never trusted from input):
 *   * an estimate is only visible if its customer.company_id matches the
 *     portal_account.company_id — estimates.company_id doesn't exist in
 *     the legacy schema so we resolve it via the customer FK;
 *   * a contract is only visible if contracts.company_id matches the
 *     portal_account.company_id;
 *   * portal_account must be isUsable() — revoked accounts cannot act on
 *     pending approvals even if they still have a valid JWT (belt+
 *     suspenders with Middleware::portalAuth);
 *   * estimates and contracts can only be acted on from a non-terminal
 *     status — re-approving an already-approved estimate or cancelling
 *     a cancelled contract throws.
 *
 * NOT delegating to EstimateController::approve / EstimateController::
 * reject because those gate on staff permissions (estimates.manage). The
 * portal path authenticates via portal_account ownership; bridging the
 * two paths would require permission-splitting inside EstimateController
 * which is exactly the foot-gun Phase 6.1 isolated by design.
 */
class PortalApprovalService
{
    public const ESTIMATE_PENDING_STATUSES = ['pending', 'sent'];
    public const CONTRACT_PENDING_STATUSES = ['draft', 'pending_signature'];

    public function __construct(
        private readonly EstimateRepository $estimates,
        private readonly CustomerRepository $customers,
        private readonly ContractRepository $contracts,
        private readonly ContractAmendmentRepository $amendments,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Return everything awaiting decision for this portal_account. The
     * shape keeps estimates/contracts in separate buckets so the UI can
     * render them under their own tabs; each entry carries enough
     * context for the decision screen to render without a second call.
     *
     * @return array{estimates: array<int, array<string, mixed>>, contracts: array<int, array<string, mixed>>}
     */
    public function listPending(User $user, PortalAccount $account): array
    {
        $this->assertUsable($account);
        return [
            'estimates' => array_map(
                fn(Estimate $e) => $this->serializeEstimate($e),
                $this->pendingEstimatesFor($account),
            ),
            'contracts' => array_map(
                fn(Contract $c) => $this->serializeContract($c),
                $this->pendingContractsFor($account),
            ),
        ];
    }

    public function approveEstimate(
        User $user,
        PortalAccount $account,
        int $estimateId,
        ?string $note = null,
    ): Estimate {
        $estimate = $this->loadScopedEstimate($account, $estimateId);
        if (!in_array($estimate->status, self::ESTIMATE_PENDING_STATUSES, true)) {
            throw new InvalidArgumentException(
                "estimate is not awaiting approval (status={$estimate->status})"
            );
        }
        $reason = 'Approved via customer portal'
            . ($note !== null && $note !== '' ? ': ' . $note : '');
        $updated = $this->estimates->updateStatus(
            $estimateId, 'approved', $user->id, $reason,
        );
        if ($updated === null) {
            throw new InvalidArgumentException("estimate {$estimateId} vanished after approve");
        }

        $this->audit->log(new AuditEntry(
            'portal.approval.estimate_approved',
            'estimate',
            $estimateId,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'customer_id' => $estimate->customer_id,
                'note' => $note,
            ]
        ));

        return $updated;
    }

    public function rejectEstimate(
        User $user,
        PortalAccount $account,
        int $estimateId,
        string $reason,
    ): Estimate {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('a rejection reason is required');
        }
        $estimate = $this->loadScopedEstimate($account, $estimateId);
        if (!in_array($estimate->status, self::ESTIMATE_PENDING_STATUSES, true)) {
            throw new InvalidArgumentException(
                "estimate is not awaiting approval (status={$estimate->status})"
            );
        }
        $updated = $this->estimates->updateStatus(
            $estimateId,
            'rejected',
            $user->id,
            'Rejected via customer portal: ' . $reason,
        );
        if ($updated === null) {
            throw new InvalidArgumentException("estimate {$estimateId} vanished after reject");
        }

        $this->audit->log(new AuditEntry(
            'portal.approval.estimate_rejected',
            'estimate',
            $estimateId,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'customer_id' => $estimate->customer_id,
                'reason' => $reason,
            ]
        ));

        return $updated;
    }

    public function approveContract(
        User $user,
        PortalAccount $account,
        int $contractId,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Contract {
        $contract = $this->loadScopedContract($account, $contractId);
        if (!in_array($contract->status, self::CONTRACT_PENDING_STATUSES, true)) {
            throw new InvalidArgumentException(
                "contract is not awaiting approval (status={$contract->status})"
            );
        }
        $updated = $this->contracts->update($contractId, [
            'status' => 'active',
            'signed_at' => date('Y-m-d H:i:s'),
            'signer_ip' => $ipAddress,
            'signer_user_agent' => $userAgent,
        ]);

        $isRenewal = $contract->renewed_from_contract_id !== null;
        $this->amendments->create([
            'contract_id' => $contractId,
            'amendment_kind' => $isRenewal ? 'renew' : 'change_scope',
            'effective_date' => date('Y-m-d'),
            'summary' => $isRenewal
                ? 'Renewal accepted via customer portal'
                : 'Contract accepted via customer portal',
            'delta_json' => [
                'portal_account_id' => $account->id,
                'prior_status' => $contract->status,
                'note' => $note,
            ],
            'created_by_user_id' => $user->id,
        ]);

        $this->audit->log(new AuditEntry(
            'portal.approval.contract_approved',
            'contract',
            $contractId,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'kind' => $isRenewal ? 'renewal' : 'new',
                'note' => $note,
            ]
        ));

        return $updated;
    }

    public function rejectContract(
        User $user,
        PortalAccount $account,
        int $contractId,
        string $reason,
    ): Contract {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('a rejection reason is required');
        }
        $contract = $this->loadScopedContract($account, $contractId);
        if (!in_array($contract->status, self::CONTRACT_PENDING_STATUSES, true)) {
            throw new InvalidArgumentException(
                "contract is not awaiting approval (status={$contract->status})"
            );
        }
        $updated = $this->contracts->update($contractId, [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancellation_reason' => 'Portal rejection: ' . $reason,
        ]);

        $isRenewal = $contract->renewed_from_contract_id !== null;
        $this->amendments->create([
            'contract_id' => $contractId,
            'amendment_kind' => 'terminate',
            'effective_date' => date('Y-m-d'),
            'summary' => $isRenewal
                ? 'Renewal rejected via customer portal'
                : 'Contract rejected via customer portal',
            'delta_json' => [
                'portal_account_id' => $account->id,
                'prior_status' => $contract->status,
                'reason' => $reason,
            ],
            'created_by_user_id' => $user->id,
        ]);

        $this->audit->log(new AuditEntry(
            'portal.approval.contract_rejected',
            'contract',
            $contractId,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'kind' => $isRenewal ? 'renewal' : 'new',
                'reason' => $reason,
            ]
        ));

        return $updated;
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * @return array<int, Estimate>
     */
    private function pendingEstimatesFor(PortalAccount $account): array
    {
        $out = [];
        foreach (self::ESTIMATE_PENDING_STATUSES as $status) {
            // EstimateRepository::list doesn't accept company_id directly —
            // we resolve via customer_id after loading. The legacy schema
            // binds estimates to a customer row; filtering on the
            // customers.company_id column happens here. Kept simple (N+1
            // pattern) because portal pending queues are small lists a
            // human is about to look at; if the list grows huge, add a
            // listByCompanyId helper.
            foreach ($this->estimates->list(['status' => $status], 500, 0) as $e) {
                $customer = $this->customers->find($e->customer_id);
                if ($customer === null) {
                    continue;
                }
                if ((int) ($customer->company_id ?? 0) !== $account->company_id) {
                    continue;
                }
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * @return array<int, Contract>
     */
    private function pendingContractsFor(PortalAccount $account): array
    {
        $out = [];
        foreach (self::CONTRACT_PENDING_STATUSES as $status) {
            $result = $this->contracts->search([
                'company_id' => $account->company_id,
                'status' => $status,
                'limit' => 500,
            ]);
            foreach ($result['data'] as $c) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private function loadScopedEstimate(PortalAccount $account, int $estimateId): Estimate
    {
        $this->assertUsable($account);
        if ($estimateId <= 0) {
            throw new InvalidArgumentException('estimate id is required');
        }
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null) {
            throw new InvalidArgumentException("estimate {$estimateId} not found");
        }
        $customer = $this->customers->find($estimate->customer_id);
        if ($customer === null
            || (int) ($customer->company_id ?? 0) !== $account->company_id
        ) {
            throw new UnauthorizedException(
                'estimate belongs to a different company'
            );
        }
        return $estimate;
    }

    private function loadScopedContract(PortalAccount $account, int $contractId): Contract
    {
        $this->assertUsable($account);
        if ($contractId <= 0) {
            throw new InvalidArgumentException('contract id is required');
        }
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new InvalidArgumentException("contract {$contractId} not found");
        }
        if ($contract->company_id !== $account->company_id) {
            throw new UnauthorizedException(
                'contract belongs to a different company'
            );
        }
        return $contract;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEstimate(Estimate $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'status' => $e->status,
            'customer_id' => $e->customer_id,
            'vehicle_id' => $e->vehicle_id,
            'expiration_date' => $e->expiration_date,
            'grand_total' => $e->grand_total,
            'subtotal' => $e->subtotal,
            'tax' => $e->tax,
            'customer_notes' => $e->customer_notes,
            'created_at' => $e->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeContract(Contract $c): array
    {
        return [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'status' => $c->status,
            'kind' => $c->renewed_from_contract_id !== null ? 'renewal' : 'new',
            'renewed_from_contract_id' => $c->renewed_from_contract_id,
            'title' => $c->title,
            'description' => $c->description,
            'start_date' => $c->start_date,
            'end_date' => $c->end_date,
            'billing_frequency' => $c->billing_frequency,
            'billing_amount_cents' => $c->billing_amount_cents,
            'auto_renew' => $c->auto_renew,
            'terms_markdown' => $c->terms_markdown,
        ];
    }
}
