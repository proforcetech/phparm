<?php

namespace App\Services\PropertyManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/tenants — Phase 12 of docs/woms-expansion-plan.md.
 *
 * View permission: `property.tenants.view`. Manage permission:
 * `property.tenants.manage`.
 */
class TenantController
{
    public function __construct(
        private readonly TenantRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{tenants: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'property.tenants.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'tenants' => array_map([self::class, 'toArray'], $rows),
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
        $this->gate->assert($user, 'property.tenants.view');

        $tenant = $this->repository->findById($id);
        if ($tenant === null) {
            throw new InvalidArgumentException("Tenant {$id} not found");
        }

        return self::toArray($tenant);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'property.tenants.manage');

        $displayName = trim((string) ($body['display_name'] ?? ''));
        if ($displayName === '') {
            throw new InvalidArgumentException('display_name is required');
        }

        $tenant = $this->repository->create([
            'company_id' => $body['company_id'] ?? null,
            'portal_user_id' => $body['portal_user_id'] ?? null,
            'entity_type' => isset($body['entity_type']) ? (string) $body['entity_type'] : 'individual',
            'display_name' => $displayName,
            'primary_email' => isset($body['primary_email']) ? (string) $body['primary_email'] : null,
            'primary_phone' => isset($body['primary_phone']) ? (string) $body['primary_phone'] : null,
            'secondary_phone' => isset($body['secondary_phone']) ? (string) $body['secondary_phone'] : null,
            'status' => isset($body['status']) ? (string) $body['status'] : 'active',
            'move_in_date' => isset($body['move_in_date']) ? (string) $body['move_in_date'] : null,
            'notes' => isset($body['notes']) ? (string) $body['notes'] : null,
        ]);

        return self::toArray($tenant);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'property.tenants.manage');

        if ($this->repository->findById($id) === null) {
            throw new InvalidArgumentException("Tenant {$id} not found");
        }

        $updates = [];
        $simple = [
            'entity_type', 'display_name', 'primary_email', 'primary_phone',
            'secondary_phone', 'status', 'move_in_date', 'notes',
            'company_id', 'portal_user_id',
        ];
        foreach ($simple as $key) {
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
    public static function toArray(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'company_id' => $tenant->company_id,
            'portal_user_id' => $tenant->portal_user_id,
            'entity_type' => $tenant->entity_type,
            'display_name' => $tenant->display_name,
            'primary_email' => $tenant->primary_email,
            'primary_phone' => $tenant->primary_phone,
            'secondary_phone' => $tenant->secondary_phone,
            'status' => $tenant->status,
            'move_in_date' => $tenant->move_in_date,
            'notes' => $tenant->notes,
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ];
    }
}
