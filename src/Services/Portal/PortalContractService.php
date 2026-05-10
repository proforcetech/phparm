<?php

namespace App\Services\Portal;

use App\Models\Contract;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Contracts\ContractRepository;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 2c — read-only contracts surface for the customer portal.
 *
 * The Phase 6.3 PortalApprovalService already handles approve/reject for
 * contracts in draft / pending_signature; this service is the broader
 * "view all my contracts" companion: active, cancelled, expired, etc. are
 * all returned so a portal user can see history and SLA terms, but no
 * write operations live here.
 *
 * Scope invariant: contracts.company_id MUST match portal_account.company_id
 * — we lean on ContractRepository::search's native company_id filter, then
 * re-check on findById in case search filters drift.
 */
class PortalContractService
{
    public function __construct(
        private readonly ContractRepository $contracts,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function listForPortal(User $user, PortalAccount $account, array $query = []): array
    {
        $this->assertUsable($account);

        $filters = [
            'company_id' => $account->company_id,
            'limit' => $this->intOr($query['limit'] ?? null, 50, 1, 200),
            'offset' => $this->intOr($query['offset'] ?? null, 0, 0, PHP_INT_MAX),
        ];
        if (isset($query['status']) && is_string($query['status']) && $query['status'] !== '') {
            $filters['status'] = $query['status'];
        }
        if (isset($query['contract_type']) && is_string($query['contract_type']) && $query['contract_type'] !== '') {
            $filters['contract_type'] = $query['contract_type'];
        }
        if (isset($query['active_on']) && is_string($query['active_on']) && $query['active_on'] !== '') {
            $filters['active_on'] = $query['active_on'];
        }
        if (isset($query['query']) && is_string($query['query']) && trim($query['query']) !== '') {
            $filters['query'] = trim($query['query']);
        }

        $result = $this->contracts->search($filters);
        return [
            'data' => array_map(
                fn(Contract $c) => $this->serialize($c),
                $result['data'],
            ),
            'total' => $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getForPortal(User $user, PortalAccount $account, int $contractId): array
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
            throw new UnauthorizedException('contract belongs to a different company');
        }
        return $this->serialize($contract, includeTerms: true);
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    private function intOr(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $n = (int) $value;
        if ($n < $min) return $min;
        if ($n > $max) return $max;
        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Contract $c, bool $includeTerms = false): array
    {
        $out = [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'status' => $c->status,
            'kind' => $c->renewed_from_contract_id !== null ? 'renewal' : 'new',
            'renewed_from_contract_id' => $c->renewed_from_contract_id,
            'title' => $c->title,
            'description' => $c->description,
            'contract_type' => $c->contract_type,
            'start_date' => $c->start_date,
            'end_date' => $c->end_date,
            'billing_frequency' => $c->billing_frequency,
            'billing_amount_cents' => $c->billing_amount_cents,
            'auto_renew' => (bool) $c->auto_renew,
            'renewal_term_months' => $c->renewal_term_months,
            'renewal_notice_days' => $c->renewal_notice_days,
            'signed_at' => $c->signed_at,
            'cancelled_at' => $c->cancelled_at,
            'cancellation_reason' => $c->cancellation_reason,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
        if ($includeTerms) {
            $out['terms_markdown'] = $c->terms_markdown;
        }
        return $out;
    }
}
