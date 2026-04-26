<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractEntitlement;
use App\Models\ContractSite;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Contracts CRUD + scoping + amendments (Phase 4.1 of docs/expansion-plan.md).
 *
 * Gates:
 *   - contracts.view                   — read
 *   - contracts.manage                 — create / update / delete / scope / entitlements / amendments
 *   - contracts.sign                   — reserved for Phase 4.2 signing flow
 *
 * Every mutation appends an audit_logs row. The `contract_amendments` table
 * is the user-visible change log for post-creation lifecycle events.
 */
class ContractController
{
    public const STATUSES = [
        'draft', 'pending_signature', 'active',
        'expired', 'cancelled', 'renewed',
    ];

    public const TYPES = [
        'service_agreement', 'maintenance', 'support', 'warranty',
    ];

    public const BILLING_FREQUENCIES = [
        'monthly', 'quarterly', 'annual', 'one_time',
    ];

    /**
     * Allowed status transitions. Values are arrays of permissible next states.
     * Terminal states (cancelled, expired, renewed) cannot be left.
     */
    public const TRANSITIONS = [
        'draft' => ['pending_signature', 'active', 'cancelled'],
        'pending_signature' => ['draft', 'active', 'cancelled'],
        'active' => ['expired', 'cancelled', 'renewed'],
        'expired' => ['renewed'],
        'cancelled' => [],
        'renewed' => [],
    ];

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractSiteRepository $contractSites,
        private readonly ContractEntitlementRepository $entitlements,
        private readonly ContractAmendmentRepository $amendments,
        private readonly CompanyRepository $companies,
        private readonly SiteRepository $sites,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    // ───────────────────────────────────────── Contracts ──────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listContracts(User $user, array $filters): array
    {
        $this->gate->assert($user, 'contracts.view');
        $result = $this->contracts->search($filters);
        return [
            'data' => array_map(static fn(Contract $c) => $c->toArray(), $result['data']),
            'meta' => ['total' => $result['total']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getContract(User $user, int $id): array
    {
        $this->gate->assert($user, 'contracts.view');
        $contract = $this->contracts->findById($id);
        if ($contract === null) {
            throw new InvalidArgumentException("Contract {$id} not found");
        }
        return [
            'data' => [
                'contract' => $contract->toArray(),
                'sites' => array_map(
                    static fn(ContractSite $s) => $s->toArray(),
                    $this->contractSites->listForContract($id)
                ),
                'entitlements' => array_map(
                    static fn(ContractEntitlement $e) => $e->toArray(),
                    $this->entitlements->listForContract($id, false)
                ),
                'amendments' => array_map(
                    static fn(ContractAmendment $a) => $a->toArray(),
                    $this->amendments->listForContract($id)
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createContract(User $user, array $body): array
    {
        $this->gate->assert($user, 'contracts.manage');
        $this->assertContractFields($body, isCreate: true);
        if ($this->companies->findById((int) $body['company_id']) === null) {
            throw new InvalidArgumentException("Company {$body['company_id']} not found");
        }
        $contract = $this->contracts->create(
            $body + ['created_by_user_id' => $user->id ?? null]
        );
        $this->logAudit('contract.created', 'contract', $contract->id, $user, [
            'contract_number' => $contract->contract_number,
            'company_id' => $contract->company_id,
            'status' => $contract->status,
        ]);
        return ['data' => $contract->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateContract(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'contracts.manage');
        $existing = $this->contracts->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Contract {$id} not found");
        }
        $this->assertContractFields($body, isCreate: false);
        if (array_key_exists('status', $body) && $body['status'] !== $existing->status) {
            $allowed = self::TRANSITIONS[$existing->status] ?? [];
            if (!in_array($body['status'], $allowed, true)) {
                throw new InvalidArgumentException(
                    "cannot transition contract status from '{$existing->status}' to '{$body['status']}'"
                );
            }
            if ($body['status'] === 'cancelled' && empty($existing->cancelled_at)) {
                $body['cancelled_at'] = $body['cancelled_at'] ?? date('Y-m-d H:i:s');
            }
        }
        $contract = $this->contracts->update($id, $body);
        $this->logAudit('contract.updated', 'contract', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $contract->toArray()];
    }

    public function deleteContract(User $user, int $id): void
    {
        $this->gate->assert($user, 'contracts.manage');
        $existing = $this->contracts->findById($id);
        if ($existing === null) {
            return;
        }
        if ($existing->signed_at !== null) {
            throw new InvalidArgumentException(
                'signed contracts cannot be deleted — cancel them instead'
            );
        }
        $this->contracts->delete($id);
        $this->logAudit('contract.deleted', 'contract', $id, $user, [
            'contract_number' => $existing->contract_number,
        ]);
    }

    // ───────────────────────────────────────── Sites scope ────────────────

    /**
     * @return array<string, mixed>
     */
    public function listContractSites(User $user, int $contractId): array
    {
        $this->gate->assert($user, 'contracts.view');
        $this->assertContractExists($contractId);
        $rows = $this->contractSites->listForContract($contractId);
        return ['data' => array_map(static fn(ContractSite $s) => $s->toArray(), $rows)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function attachSite(User $user, int $contractId, array $body): array
    {
        $this->gate->assert($user, 'contracts.manage');
        $contract = $this->requireContract($contractId);
        $siteId = (int) ($body['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id is required');
        }
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            throw new InvalidArgumentException("Site {$siteId} not found");
        }
        if ($site->company_id !== $contract->company_id) {
            throw new InvalidArgumentException(
                "Site {$siteId} does not belong to company {$contract->company_id}"
            );
        }
        $link = $this->contractSites->attach($contractId, $siteId);
        $this->logAudit('contract.site_attached', 'contract', $contractId, $user, [
            'site_id' => $siteId,
        ]);
        return ['data' => $link->toArray()];
    }

    public function detachSite(User $user, int $contractId, int $siteId): void
    {
        $this->gate->assert($user, 'contracts.manage');
        $this->assertContractExists($contractId);
        $this->contractSites->detach($contractId, $siteId);
        $this->logAudit('contract.site_detached', 'contract', $contractId, $user, [
            'site_id' => $siteId,
        ]);
    }

    // ───────────────────────────────────────── Entitlements ───────────────

    /**
     * @return array<string, mixed>
     */
    public function listEntitlements(User $user, int $contractId, bool $activeOnly): array
    {
        $this->gate->assert($user, 'contracts.view');
        $this->assertContractExists($contractId);
        $rows = $this->entitlements->listForContract($contractId, $activeOnly);
        return ['data' => array_map(static fn(ContractEntitlement $e) => $e->toArray(), $rows)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createEntitlement(User $user, int $contractId, array $body): array
    {
        $this->gate->assert($user, 'contracts.manage');
        $this->assertContractExists($contractId);
        $body['contract_id'] = $contractId;
        $this->assertEntitlementFields($body, isCreate: true);
        $ent = $this->entitlements->create($body);
        $this->logAudit('contract.entitlement_created', 'contract', $contractId, $user, [
            'entitlement_id' => $ent->id,
            'kind' => $ent->entitlement_kind,
        ]);
        return ['data' => $ent->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateEntitlement(User $user, int $contractId, int $entitlementId, array $body): array
    {
        $this->gate->assert($user, 'contracts.manage');
        $this->assertContractExists($contractId);
        $this->assertEntitlementFields($body, isCreate: false);
        // Never permit direct quantity_used edits through this endpoint —
        // consumption must flow through ContractEntitlementRepository::consume().
        unset($body['quantity_used']);
        $ent = $this->entitlements->update($entitlementId, $body);
        if ($ent->contract_id !== $contractId) {
            throw new InvalidArgumentException(
                "entitlement {$entitlementId} does not belong to contract {$contractId}"
            );
        }
        $this->logAudit('contract.entitlement_updated', 'contract', $contractId, $user, [
            'entitlement_id' => $entitlementId,
            'fields' => array_keys($body),
        ]);
        return ['data' => $ent->toArray()];
    }

    public function deleteEntitlement(User $user, int $contractId, int $entitlementId): void
    {
        $this->gate->assert($user, 'contracts.manage');
        $this->assertContractExists($contractId);
        $existing = $this->entitlements->findById($entitlementId);
        if ($existing === null) {
            return;
        }
        if ($existing->contract_id !== $contractId) {
            throw new InvalidArgumentException(
                "entitlement {$entitlementId} does not belong to contract {$contractId}"
            );
        }
        $this->entitlements->delete($entitlementId);
        $this->logAudit('contract.entitlement_deleted', 'contract', $contractId, $user, [
            'entitlement_id' => $entitlementId,
        ]);
    }

    // ───────────────────────────────────────── Amendments ─────────────────

    /**
     * @return array<string, mixed>
     */
    public function listAmendments(User $user, int $contractId): array
    {
        $this->gate->assert($user, 'contracts.view');
        $this->assertContractExists($contractId);
        $rows = $this->amendments->listForContract($contractId);
        return ['data' => array_map(static fn(ContractAmendment $a) => $a->toArray(), $rows)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createAmendment(User $user, int $contractId, array $body): array
    {
        $this->gate->assert($user, 'contracts.manage');
        $this->assertContractExists($contractId);
        $kind = (string) ($body['amendment_kind'] ?? '');
        if (!in_array($kind, ContractAmendmentRepository::KINDS, true)) {
            throw new InvalidArgumentException(
                'amendment_kind must be one of: ' . implode(', ', ContractAmendmentRepository::KINDS)
            );
        }
        if (trim((string) ($body['summary'] ?? '')) === '') {
            throw new InvalidArgumentException('summary is required');
        }
        if (empty($body['effective_date'])) {
            throw new InvalidArgumentException('effective_date is required');
        }
        $amendment = $this->amendments->create(
            $body + [
                'contract_id' => $contractId,
                'created_by_user_id' => $user->id ?? null,
            ]
        );
        $this->logAudit('contract.amendment_created', 'contract', $contractId, $user, [
            'amendment_id' => $amendment->id,
            'kind' => $amendment->amendment_kind,
        ]);
        return ['data' => $amendment->toArray()];
    }

    // ───────────────────────────────────────── Validation ─────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function assertContractFields(array $body, bool $isCreate): void
    {
        if ($isCreate) {
            foreach (['title', 'company_id', 'start_date', 'end_date'] as $required) {
                if (empty($body[$required])) {
                    throw new InvalidArgumentException("{$required} is required");
                }
            }
        } elseif (array_key_exists('title', $body) && trim((string) $body['title']) === '') {
            throw new InvalidArgumentException('title cannot be blank');
        }

        if (
            array_key_exists('contract_type', $body)
            && !in_array($body['contract_type'], self::TYPES, true)
        ) {
            throw new InvalidArgumentException(
                'contract_type must be one of: ' . implode(', ', self::TYPES)
            );
        }
        if (
            array_key_exists('billing_frequency', $body)
            && !in_array($body['billing_frequency'], self::BILLING_FREQUENCIES, true)
        ) {
            throw new InvalidArgumentException(
                'billing_frequency must be one of: ' . implode(', ', self::BILLING_FREQUENCIES)
            );
        }
        if (
            array_key_exists('status', $body)
            && !in_array($body['status'], self::STATUSES, true)
        ) {
            throw new InvalidArgumentException(
                'status must be one of: ' . implode(', ', self::STATUSES)
            );
        }
        if (
            !empty($body['start_date']) && !empty($body['end_date'])
            && strtotime((string) $body['end_date']) < strtotime((string) $body['start_date'])
        ) {
            throw new InvalidArgumentException('end_date must be on or after start_date');
        }
        if (
            array_key_exists('billing_amount_cents', $body)
            && $body['billing_amount_cents'] !== null
            && (int) $body['billing_amount_cents'] < 0
        ) {
            throw new InvalidArgumentException('billing_amount_cents must be non-negative');
        }
        if (
            array_key_exists('renewal_term_months', $body)
            && $body['renewal_term_months'] !== null
            && (int) $body['renewal_term_months'] <= 0
        ) {
            throw new InvalidArgumentException('renewal_term_months must be positive');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertEntitlementFields(array $body, bool $isCreate): void
    {
        if ($isCreate) {
            foreach (['entitlement_kind', 'description'] as $required) {
                if (empty($body[$required])) {
                    throw new InvalidArgumentException("{$required} is required");
                }
            }
        }
        if (
            array_key_exists('entitlement_kind', $body)
            && !in_array($body['entitlement_kind'], ContractEntitlementRepository::KINDS, true)
        ) {
            throw new InvalidArgumentException(
                'entitlement_kind must be one of: '
                . implode(', ', ContractEntitlementRepository::KINDS)
            );
        }
        if (
            array_key_exists('period', $body)
            && !in_array($body['period'], ContractEntitlementRepository::PERIODS, true)
        ) {
            throw new InvalidArgumentException(
                'period must be one of: ' . implode(', ', ContractEntitlementRepository::PERIODS)
            );
        }
        if (
            array_key_exists('quantity_allowed', $body)
            && $body['quantity_allowed'] !== null
            && (float) $body['quantity_allowed'] < 0
        ) {
            throw new InvalidArgumentException('quantity_allowed must be non-negative');
        }
        if (
            array_key_exists('unit_rate_cents', $body)
            && $body['unit_rate_cents'] !== null
            && (int) $body['unit_rate_cents'] < 0
        ) {
            throw new InvalidArgumentException('unit_rate_cents must be non-negative');
        }
    }

    private function assertContractExists(int $id): void
    {
        if ($this->contracts->findById($id) === null) {
            throw new InvalidArgumentException("Contract {$id} not found");
        }
    }

    private function requireContract(int $id): Contract
    {
        $contract = $this->contracts->findById($id);
        if ($contract === null) {
            throw new InvalidArgumentException("Contract {$id} not found");
        }
        return $contract;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudit(string $event, string $entityType, int $entityId, User $user, array $context): void
    {
        $this->audit->log(new AuditEntry(
            $event,
            $entityType,
            $entityId,
            (int) ($user->id ?? 0),
            $context
        ));
    }
}
