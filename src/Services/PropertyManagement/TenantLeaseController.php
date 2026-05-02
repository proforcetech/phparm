<?php

namespace App\Services\PropertyManagement;

use App\Models\TenantLease;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/tenant-leases — Phase 12 of
 * docs/woms-expansion-plan.md.
 *
 * View permission: `property.leases.view`. Manage permission:
 * `property.leases.manage`.
 */
class TenantLeaseController
{
    public function __construct(
        private readonly TenantLeaseRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{leases: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'property.leases.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'leases' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'property.leases.view');

        $lease = $this->repository->findById($id);
        if ($lease === null) {
            throw new InvalidArgumentException("Tenant lease {$id} not found");
        }

        return self::toArray($lease);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'property.leases.manage');

        $tenantId = (int) ($body['tenant_id'] ?? 0);
        $unitId = (int) ($body['unit_id'] ?? 0);
        $startDate = trim((string) ($body['start_date'] ?? ''));
        if ($tenantId <= 0 || $unitId <= 0 || $startDate === '') {
            throw new InvalidArgumentException('tenant_id, unit_id, and start_date are required');
        }

        $lease = $this->repository->create([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'start_date' => $startDate,
            'end_date' => $body['end_date'] ?? null,
            'monthly_rent' => $body['monthly_rent'] ?? null,
            'deposit_amount' => $body['deposit_amount'] ?? null,
            'billing_responsibility' => isset($body['billing_responsibility'])
                ? (string) $body['billing_responsibility']
                : TenantLease::BILLING_LANDLORD,
            'maintenance_terms' => $body['maintenance_terms'] ?? null,
            'status' => isset($body['status']) ? (string) $body['status'] : TenantLease::STATUS_ACTIVE,
            'terms' => isset($body['terms']) ? (string) $body['terms'] : null,
            'notes' => isset($body['notes']) ? (string) $body['notes'] : null,
        ]);

        return self::toArray($lease);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'property.leases.manage');

        if ($this->repository->findById($id) === null) {
            throw new InvalidArgumentException("Tenant lease {$id} not found");
        }

        $updates = [];
        $allowed = [
            'start_date', 'end_date', 'monthly_rent', 'deposit_amount',
            'billing_responsibility', 'maintenance_terms', 'status', 'terms', 'notes',
        ];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }

        $updated = $this->repository->update($id, $updates);
        return self::toArray($updated);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(TenantLease $lease): array
    {
        return [
            'id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'unit_id' => $lease->unit_id,
            'start_date' => $lease->start_date,
            'end_date' => $lease->end_date,
            'monthly_rent' => $lease->monthly_rent,
            'deposit_amount' => $lease->deposit_amount,
            'billing_responsibility' => $lease->billing_responsibility,
            'maintenance_terms' => $lease->maintenance_terms,
            'status' => $lease->status,
            'terms' => $lease->terms,
            'notes' => $lease->notes,
            'created_at' => $lease->created_at,
            'updated_at' => $lease->updated_at,
        ];
    }
}
