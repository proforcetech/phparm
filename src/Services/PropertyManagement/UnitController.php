<?php

namespace App\Services\PropertyManagement;

use App\Models\Unit;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/units — Phase 12 of docs/woms-expansion-plan.md.
 *
 * View permission: `property.units.view`. Manage permission:
 * `property.units.manage`. Mirrors the gating pattern used by
 * ServiceLineController.
 *
 * NOTE: not-found is signalled with InvalidArgumentException to match the
 * existing convention (Router maps it to a 400). The codebase has no
 * HttpException type yet — see ServiceLineController for the same pattern.
 */
class UnitController
{
    public function __construct(
        private readonly UnitRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{units: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'property.units.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'units' => array_map([self::class, 'toArray'], $rows),
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
        $this->gate->assert($user, 'property.units.view');

        $unit = $this->repository->findById($id);
        if ($unit === null) {
            throw new InvalidArgumentException("Unit {$id} not found");
        }

        return self::toArray($unit);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'property.units.manage');

        $siteId = (int) ($body['site_id'] ?? 0);
        $code = trim((string) ($body['code'] ?? ''));
        if ($siteId <= 0 || $code === '') {
            throw new InvalidArgumentException('site_id and code are required');
        }

        $unit = $this->repository->create([
            'site_id' => $siteId,
            'code' => $code,
            'name' => isset($body['name']) ? (string) $body['name'] : null,
            'unit_type' => isset($body['unit_type']) ? (string) $body['unit_type'] : 'commercial',
            'floor' => isset($body['floor']) ? (string) $body['floor'] : null,
            'square_feet' => $body['square_feet'] ?? null,
            'bedrooms' => $body['bedrooms'] ?? null,
            'bathrooms' => $body['bathrooms'] ?? null,
            'status' => isset($body['status']) ? (string) $body['status'] : 'active',
            'notes' => isset($body['notes']) ? (string) $body['notes'] : null,
        ]);

        return self::toArray($unit);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'property.units.manage');

        if ($this->repository->findById($id) === null) {
            throw new InvalidArgumentException("Unit {$id} not found");
        }

        $updates = [];
        foreach (['code', 'name', 'unit_type', 'floor', 'status', 'notes'] as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }
        foreach (['square_feet', 'bedrooms', 'bathrooms'] as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }

        $updated = $this->repository->update($id, $updates);
        return self::toArray($updated);
    }

    /**
     * @return array{success: bool}
     */
    public function destroy(User $user, int $id): array
    {
        $this->gate->assert($user, 'property.units.manage');

        $this->repository->delete($id);
        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'site_id' => $unit->site_id,
            'code' => $unit->code,
            'name' => $unit->name,
            'unit_type' => $unit->unit_type,
            'floor' => $unit->floor,
            'square_feet' => $unit->square_feet,
            'bedrooms' => $unit->bedrooms,
            'bathrooms' => $unit->bathrooms,
            'status' => $unit->status,
            'notes' => $unit->notes,
            'created_at' => $unit->created_at,
            'updated_at' => $unit->updated_at,
        ];
    }
}
